<?php

namespace App\Support\Collab;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Live co-editing of a document's draft (BRAI-54). The realtime server keeps the Yjs document; this
 * class decides who may open it, hands out connection tokens, lets exactly one browser load the draft
 * HTML into an empty document, stores the browsers' HTML of the live document as the draft (through
 * Page::saveDraft) and starts a new document when the draft is replaced from elsewhere.
 * Plan: docs/ai/tasks/feat-live-docs.md.
 */
final class PageCollab
{
    /** How long a bootstrap claim keeps the others waiting before someone else may load the draft. */
    public const BOOTSTRAP_LEASE_SECONDS = 20;

    /** Live editing exists for documents only (no folders, no whiteboards). */
    public static function supports(Page $page): bool
    {
        return $page->isPage() && ! $page->is_whiteboard;
    }

    /**
     * 'edit' for editors; 'view' (read-only live view) only on never-published pages, whose readers see
     * the draft anyway; null for everyone else — readers of a published page keep the published version.
     *
     * @return 'edit'|'view'|null
     */
    public static function access(Page $page, User $user, ?bool $canUpdate = null): ?string
    {
        if (! self::supports($page) || $page->status === 'archived' || $user->isSuspended()) {
            return null;
        }
        if ($canUpdate ?? $user->can('update', $page)) {
            return 'edit';
        }

        return $page->isDraft() && $user->can('view', $page) ? 'view' : null;
    }

    /**
     * The `collab` prop of pages.show; null when the doc opens with the single-user editor.
     *
     * @return array{epoch: int, url: string}|null
     */
    public static function props(Page $page, User $user, ?bool $canUpdate = null): ?array
    {
        if (! CollabConfig::enabled() || self::access($page, $user, $canUpdate) === null) {
            return null;
        }

        // the URL too: a browser that is offline on load still opens the document from IndexedDB
        return ['epoch' => (int) (PageCollabState::where('page_id', $page->id)->value('epoch') ?? 0), 'url' => CollabConfig::url()];
    }

    /**
     * Make sure the page has its row (ON CONFLICT DO NOTHING: no error, no aborted transaction, and
     * the possibly large Yjs state is never read back).
     */
    public static function ensureState(Page $page): void
    {
        PageCollabState::query()->insertOrIgnore([
            'page_id' => $page->id,
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
    public static function session(Page $page, User $user, string $access): array
    {
        self::ensureState($page);
        $state = PageCollabState::where('page_id', $page->id)->firstOrFail(['id', 'epoch', 'html_vector']);
        $document = CollabDocument::name($page->uuid, $state->epoch);
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
     * A browser synced an empty Y document: the first one (per lease) loads the current draft into it,
     * the others wait for it to arrive through the sync. Refused once the server stored any state.
     *
     * @return array{status: 'granted', html: string|null, name: string, icon: string|null}|array{status: 'wait'}|array{status: 'stale', epoch: int}
     */
    public static function claimBootstrap(Page $page, int $epoch, string $claim): array
    {
        return self::claim($page, $epoch, $claim, function (Page $page) {
            $draft = $page->fresh()?->draftState() ?? ['name' => $page->name, 'icon' => $page->icon, 'content' => null];
            $content = $draft['content'];

            return [
                'html' => is_array($content) && ($content['type'] ?? null) === 'lexical' && is_string($content['html'] ?? null) ? $content['html'] : null,
                'name' => $draft['name'],
                'icon' => $draft['icon'],
            ];
        });
    }

    /**
     * The claim behind every bootstrap (documents, whiteboards — BRAI-153): under the state row lock one
     * claim per lease wins and gets `$payload($page)`; refused once the server stored any state.
     *
     * @param  callable(Page): array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function claim(Page $page, int $epoch, string $claim, callable $payload): array
    {
        self::ensureState($page);

        return DB::transaction(function () use ($page, $epoch, $claim, $payload) {
            $state = PageCollabState::where('page_id', $page->id)->lockForUpdate()
                ->firstOrFail(['id', 'epoch', 'state_bytes', 'bootstrap_claim', 'bootstrap_claimed_at']);
            if ($state->epoch !== $epoch) {
                return ['status' => 'stale', 'epoch' => $state->epoch];
            }
            $leased = $state->bootstrap_claimed_at?->gt(now()->subSeconds(self::BOOTSTRAP_LEASE_SECONDS)) ?? false;
            if ($state->state_bytes !== null || ($leased && $state->bootstrap_claim !== $claim)) {
                return ['status' => 'wait'];
            }
            $state->update(['bootstrap_claim' => $claim, 'bootstrap_claimed_at' => now()]);

            return ['status' => 'granted'] + $payload($page);
        });
    }

    /**
     * Store a live browser's HTML of the Y document as the draft. Skipped when its state vector is
     * covered by the last stored one (older snapshot) or when nothing would change.
     *
     * @param  array{content?: array<string, mixed>|null, name?: string, icon?: string|null}  $changes
     * @param  array<int, int>  $vector
     *                                   `$baseline`: the browser that loaded the draft into an empty document reports the vector of that
     *                                   load; nothing is written to the page (its HTML may serialize differently from the stored one).
     * @return array{status: 'saved'|'unchanged'|'covered'|'baseline', page: Page}|array{status: 'stale', page: Page, epoch: int}
     */
    public static function save(Page $page, User $user, int $epoch, array $changes, array $vector, bool $baseline = false): array
    {
        self::ensureState($page);

        return DB::transaction(function () use ($page, $user, $epoch, $changes, $vector, $baseline) {
            // same order as ReviewGate (page row first), so a publish and a save serialize
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $state = PageCollabState::where('page_id', $page->id)->lockForUpdate()
                ->firstOrFail(['id', 'epoch', 'html_vector']);

            if ($state->epoch !== $epoch) {
                return ['status' => 'stale', 'page' => $locked, 'epoch' => $state->epoch];
            }
            if ($baseline) {
                if ($state->html_vector === null) {
                    $state->update(['html_vector' => $vector]);
                }

                return ['status' => 'baseline', 'page' => $locked];
            }
            // Only a snapshot that contains everything the stored one has may replace it: an older one
            // (covered) or a concurrent one (each lacks something) waits for a merged save — the leader
            // receives every change and saves the union.
            if ($state->html_vector !== null && (StateVector::covers($state->html_vector, $vector) || ! StateVector::covers($vector, $state->html_vector))) {
                return ['status' => 'covered', 'page' => $locked];
            }

            $status = 'unchanged';
            if ($locked->draftWouldChange($changes)) {
                $locked->collabWrite = true;
                try {
                    $locked->saveDraft($user, $changes);
                } finally {
                    $locked->collabWrite = false;
                }
                $status = 'saved';
            }
            $state->update(['html_vector' => $vector]);

            return ['status' => $status, 'page' => $locked];
        });
    }

    /**
     * The draft was replaced outside the live document: the next session starts a new Y document from
     * it. Live browsers on the old one are sent away (store 409, session, heartbeat, save 409).
     */
    public static function reset(Page $page): void
    {
        PageCollabState::where('page_id', $page->id)->update([
            'epoch' => DB::raw('epoch + 1'),
            'state' => null,
            'state_bytes' => null,
            'html_vector' => null,
            'bootstrap_claim' => null,
            'bootstrap_claimed_at' => null,
            'updated_at' => now(),
        ]);
    }

    /** Current epoch for the presence heartbeat; null when the page never had a live session. */
    public static function epoch(Page $page): ?int
    {
        $epoch = PageCollabState::where('page_id', $page->id)->value('epoch');

        return $epoch === null ? null : (int) $epoch;
    }
}
