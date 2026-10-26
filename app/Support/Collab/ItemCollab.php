<?php

namespace App\Support\Collab;

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\ItemCollabState;
use App\Models\User;
use App\Support\Boards\ItemMentions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Live issue descriptions (BRAI-151): several people edit one issue's description at once, on the same
 * realtime server and Yjs / Lexical binding as documents (BRAI-54). Y document `item.<id>.<epoch>`,
 * state in `item_collab_states`. Brainitive stays the source of truth: the browsers store the HTML of
 * the live document through the normal item update (history, mentions, smart links, search, webhooks
 * and automations as for a single-user save). Only the description is live — title, fields and
 * comments stay as they are. Plan: docs/ai/tasks/feat-live-basics.md.
 */
final class ItemCollab
{
    /** How long a bootstrap claim keeps the others waiting before someone else may load the description. */
    public const BOOTSTRAP_LEASE_SECONDS = 20;

    /** Saves of one person within this window share one history entry (live saves come every few seconds). */
    public const HISTORY_MERGE_MINUTES = 10;

    /** The issue, its board, project and workspace exist (not trashed). */
    public static function supports(Item $item): bool
    {
        return ! $item->trashed() && $item->board?->project?->workspace !== null;
    }

    /**
     * 'edit' for people who may update the issue (ItemPolicy::update), 'view' (read-only live view) for
     * everyone else who may open it, null otherwise.
     *
     * @return 'edit'|'view'|null
     */
    public static function access(Item $item, User $user): ?string
    {
        if (! self::supports($item) || $user->isSuspended()) {
            return null;
        }
        try {
            $gate = Gate::forUser($user);
            if ($gate->allows('update', $item)) {
                return 'edit';
            }

            return $gate->allows('view', $item) ? 'view' : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Make sure the issue has its row (no error, no aborted transaction, the state is never read back). */
    public static function ensureState(Item $item): void
    {
        ItemCollabState::query()->insertOrIgnore([
            'item_id' => $item->id,
            'epoch' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Everything a browser needs to connect: server URL, document name, a fresh token.
     *
     * @param  'edit'|'view'  $access
     * @return array<string, mixed>
     */
    public static function session(Item $item, User $user, string $access): array
    {
        self::ensureState($item);
        $state = ItemCollabState::where('item_id', $item->id)->firstOrFail(['id', 'epoch', 'html_vector']);
        $document = CollabDocument::itemName($item->id, $state->epoch);
        $token = CollabToken::issue($user, $document, $access === 'edit');

        return [
            'url' => CollabConfig::url(),
            'document' => $document,
            'epoch' => $state->epoch,
            'token' => $token['token'],
            'expires_at' => $token['expires_at'],
            'edit' => $access === 'edit',
            'html_vector' => $state->html_vector === null ? null : (object) $state->html_vector,
        ];
    }

    /**
     * An editor synced an empty Y document: the first one (per lease) loads the stored description into
     * it, the others wait for it through the sync. Refused once the server stored any state.
     *
     * @return array{status: 'granted', html: string|null, name: string, icon: null}|array{status: 'wait'}|array{status: 'stale', epoch: int}
     */
    public static function claimBootstrap(Item $item, int $epoch, string $claim): array
    {
        self::ensureState($item);

        return DB::transaction(function () use ($item, $epoch, $claim) {
            $state = ItemCollabState::where('item_id', $item->id)->lockForUpdate()
                ->firstOrFail(['id', 'epoch', 'state_bytes', 'bootstrap_claim', 'bootstrap_claimed_at']);
            if ($state->epoch !== $epoch) {
                return ['status' => 'stale', 'epoch' => $state->epoch];
            }
            $leased = $state->bootstrap_claimed_at?->gt(now()->subSeconds(self::BOOTSTRAP_LEASE_SECONDS)) ?? false;
            if ($state->state_bytes !== null || ($leased && $state->bootstrap_claim !== $claim)) {
                return ['status' => 'wait'];
            }
            $state->update(['bootstrap_claim' => $claim, 'bootstrap_claimed_at' => now()]);
            $fresh = $item->fresh() ?? $item;

            return ['status' => 'granted', 'html' => self::html($fresh->description), 'name' => (string) $fresh->title, 'icon' => null];
        });
    }

    /**
     * Store a live browser's HTML of the Y document as the description, through the normal item update.
     * Skipped when its state vector is covered by the last stored one (older or concurrent snapshot: the
     * leader saves the merged one) or when nothing would change.
     *
     * @param  array<int, int>  $vector
     * @return array{status: 'saved'|'unchanged'|'covered'|'baseline', item: Item}|array{status: 'stale', item: Item, epoch: int}
     */
    public static function save(Item $item, User $user, int $epoch, string $html, array $vector, bool $baseline = false): array
    {
        self::ensureState($item);

        return DB::transaction(function () use ($item, $user, $epoch, $html, $vector, $baseline) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $state = ItemCollabState::where('item_id', $item->id)->lockForUpdate()
                ->firstOrFail(['id', 'epoch', 'html_vector']);

            if ($state->epoch !== $epoch) {
                return ['status' => 'stale', 'item' => $locked, 'epoch' => $state->epoch];
            }
            if ($baseline) {
                if ($state->html_vector === null) {
                    $state->update(['html_vector' => $vector]);
                }

                return ['status' => 'baseline', 'item' => $locked];
            }
            if ($state->html_vector !== null && (StateVector::covers($state->html_vector, $vector) || ! StateVector::covers($vector, $state->html_vector))) {
                return ['status' => 'covered', 'item' => $locked];
            }

            $status = 'unchanged';
            $previous = $locked->description;
            if (self::html($previous) !== $html) {
                $locked->collabWrite = true;
                try {
                    // the same as BoardController::updateItem: observer (history, webhooks, automations),
                    // smart links, search; then mentions of people not mentioned before
                    $locked->update(['description' => $html]);
                    ItemMentions::notify($html, $locked, $user, $previous);
                } finally {
                    $locked->collabWrite = false;
                }
                self::mergeHistory($locked, $user);
                $status = 'saved';
            }
            $state->update(['html_vector' => $vector]);

            return ['status' => $status, 'item' => $locked];
        });
    }

    /**
     * The description was replaced outside the live document: the next session starts a new Y document
     * from it. Live browsers on the old one are sent away (store 409, session, save 409).
     */
    public static function reset(Item $item): void
    {
        ItemCollabState::where('item_id', $item->id)->update([
            'epoch' => DB::raw('epoch + 1'),
            'state' => null,
            'state_bytes' => null,
            'html_vector' => null,
            'bootstrap_claim' => null,
            'bootstrap_claimed_at' => null,
            'updated_at' => now(),
        ]);
    }

    /** Current epoch; null when the issue never had a live session. */
    public static function epoch(Item $item): ?int
    {
        $epoch = ItemCollabState::where('item_id', $item->id)->value('epoch');

        return $epoch === null ? null : (int) $epoch;
    }

    /**
     * The description as editor HTML: issues created long ago may hold Editor.js JSON (`{blocks: […]}`),
     * which the issue view converts the same way (ItemDetail parseDescription).
     */
    public static function html(?string $description): string
    {
        if ($description === null || $description === '') {
            return '';
        }
        $decoded = json_decode($description, true);
        if (! is_array($decoded) || ! is_array($decoded['blocks'] ?? null)) {
            return $description;
        }

        return implode('', array_map(function ($block) {
            $text = is_array($block) && is_array($block['data'] ?? null) && is_string($block['data']['text'] ?? null) ? $block['data']['text'] : '';

            return is_array($block) && ($block['type'] ?? null) === 'paragraph' ? "<p>{$text}</p>" : $text;
        }, $decoded['blocks']));
    }

    /**
     * Live saves come every few seconds while someone types: their previous description-only entry of
     * the last minutes is dropped, so the history shows one "changed the description" per session.
     */
    private static function mergeHistory(Item $item, User $user): void
    {
        $recent = $item->activities()->limit(2)->get(['id', 'user_id', 'action', 'changes', 'created_at']);
        if ($recent->count() < 2) {
            return;
        }
        $descriptionOnly = fn (ActivityLog $entry) => $entry->action === 'updated'
            && $entry->user_id === $user->id
            && $entry->changes === ['description' => ['changed' => true]];
        [$latest, $previous] = [$recent[0], $recent[1]];
        if ($descriptionOnly($latest) && $descriptionOnly($previous) && $previous->created_at?->gte(now()->subMinutes(self::HISTORY_MERGE_MINUTES))) {
            $previous->delete();
        }
    }
}
