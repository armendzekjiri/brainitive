<?php

namespace App\Support\Feedback;

use App\Models\Board;
use App\Models\FeedbackEvent;
use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\Item;
use App\Models\User;
use App\Support\Boards\ProjectIssues;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moderator actions on a feedback portal (BRAI-60). Every action locks the post row, is idempotent
 * where a retry is likely (create card, link card, merge) and writes a FeedbackEvent (who/when/what).
 * Callers authorize first (project admins: ProjectPolicy::update; the card: BoardPolicy::createItems
 * / ItemPolicy::view).
 */
final class FeedbackModeration
{
    public static function moderate(FeedbackPost $post, User $actor, string $decision): FeedbackPost
    {
        return DB::transaction(function () use ($post, $actor, $decision) {
            $locked = self::lock($post);
            if ($locked->merged_into_id !== null) {
                throw ValidationException::withMessages(['post' => 'This idea was merged into another one.']);
            }

            $target = $decision === 'approve' ? 'approved' : 'rejected';
            if ($locked->moderation === $target) {
                return $locked;
            }

            $from = $locked->moderation;
            $locked->update([
                'moderation' => $target,
                'approved_at' => $target === 'approved' ? ($locked->approved_at ?? now()) : $locked->approved_at,
            ]);
            FeedbackEvent::record($locked->portal, $locked, $actor, $target, ['from' => $from, 'to' => $target, 'title' => $locked->title]);

            return $locked;
        });
    }

    public static function setStatus(FeedbackPost $post, User $actor, string $status, ?string $note): FeedbackPost
    {
        return DB::transaction(function () use ($post, $actor, $status, $note) {
            $locked = self::lock($post);
            if ($locked->merged_into_id !== null) {
                throw ValidationException::withMessages(['status' => 'This idea was merged into another one.']);
            }

            $statusChanged = $locked->status !== $status;
            if (! $statusChanged && $locked->status_note === $note) {
                return $locked;
            }

            $changes = ['title' => $locked->title];
            if ($statusChanged) {
                $changes += ['from' => $locked->status, 'to' => $status];
            }
            if ($locked->status_note !== $note) {
                $changes['note'] = $note;
            }

            $locked->update([
                'status' => $status,
                'status_note' => $note,
                'status_changed_at' => $statusChanged ? now() : $locked->status_changed_at,
            ]);
            FeedbackEvent::record($locked->portal, $locked, $actor, 'status_changed', $changes);

            // followers hear about public changes only (a pending post's status is not public yet)
            if ($statusChanged && $locked->isPublished()) {
                FeedbackNotifier::statusChanged($locked, $status, $note);
            }

            return $locked;
        });
    }

    /**
     * Merge a duplicate into another post of the same portal: votes and followers are unioned
     * (unique pairs, so nobody counts twice), the duplicate's URL redirects to the target, and posts
     * merged into the duplicate earlier now point at the target.
     */
    public static function merge(FeedbackPost $source, FeedbackPost $target, User $actor): FeedbackPost
    {
        if ($source->id === $target->id || $source->feedback_portal_id !== $target->feedback_portal_id) {
            throw ValidationException::withMessages(['target' => 'Pick another idea of this portal.']);
        }

        return DB::transaction(function () use ($source, $target, $actor) {
            // lock in id order: two merges in opposite directions never deadlock
            $rows = FeedbackPost::whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $rows[$source->id];
            $into = $rows[$target->id];

            if ($from->merged_into_id === $into->id) {
                return $into; // a retry
            }
            if ($from->merged_into_id !== null) {
                throw ValidationException::withMessages(['target' => 'This idea was already merged.']);
            }
            if (! $into->isPublished()) {
                throw ValidationException::withMessages(['target' => 'Merge into a published idea.']);
            }

            foreach (['feedback_votes', 'feedback_subscriptions'] as $table) {
                DB::table($table)->insertOrIgnoreUsing(
                    ['feedback_post_id', 'portal_identity_id', 'created_at'],
                    DB::table($table)->where('feedback_post_id', $from->id)
                        ->select([DB::raw((int) $into->id.' as feedback_post_id'), 'portal_identity_id', 'created_at'])
                );
                DB::table($table)->where('feedback_post_id', $from->id)->delete();
            }

            $before = $into->votes_count;
            $into->update(['votes_count' => DB::table('feedback_votes')->where('feedback_post_id', $into->id)->count()]);
            FeedbackPost::where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);
            $from->update(['merged_into_id' => $into->id, 'votes_count' => 0]);

            FeedbackEvent::record($from->portal, $from, $actor, 'merged', [
                'title' => $from->title,
                'into_number' => $into->number,
                'into_title' => $into->title,
                'votes_before' => $before,
                'votes_after' => $into->votes_count,
            ]);

            return $into;
        });
    }

    /**
     * "Create card": one board issue for the post, in the board's first column. Repeating it
     * (double click, retry) returns the card already linked.
     *
     * @return array{0: Item, 1: bool} the item, and whether it was created now
     */
    public static function createCard(FeedbackPost $post, Board $board, User $actor): array
    {
        return DB::transaction(function () use ($post, $board, $actor) {
            $locked = self::lock($post);
            $portal = $locked->portal;

            if ($locked->item_id && ($existing = Item::find($locked->item_id))) {
                return [$existing, false];
            }
            if ($board->project_id !== $portal->project_id) {
                throw ValidationException::withMessages(['board_id' => 'Pick a board of this project.']);
            }

            try {
                $item = ProjectIssues::create($board, $portal->project, $actor, [[
                    'title' => $locked->title,
                    'description' => self::cardDescription($locked, $portal),
                ]])->first();
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['board_id' => $e->getMessage()]);
            }

            $locked->update(['item_id' => $item->id]);
            FeedbackEvent::record($portal, $locked, $actor, 'card_created', ['title' => $locked->title, 'card' => $item->key, 'board' => $board->name]);

            return [$item, true];
        });
    }

    /** "Link card": an existing issue of the SAME project. Linking the card already linked is a no-op. */
    public static function linkCard(FeedbackPost $post, Item $item, User $actor): FeedbackPost
    {
        return DB::transaction(function () use ($post, $item, $actor) {
            $locked = self::lock($post);
            $portal = $locked->portal;

            $item->loadMissing('board:id,project_id');
            if ((int) $item->board?->project_id !== (int) $portal->project_id) {
                throw ValidationException::withMessages(['item_id' => 'Pick an issue of this project.']);
            }
            if ($locked->item_id === $item->id) {
                return $locked;
            }

            $locked->update(['item_id' => $item->id]);
            FeedbackEvent::record($portal, $locked, $actor, 'card_linked', ['title' => $locked->title, 'card' => $item->key]);

            return $locked;
        });
    }

    public static function unlinkCard(FeedbackPost $post, User $actor): FeedbackPost
    {
        return DB::transaction(function () use ($post, $actor) {
            $locked = self::lock($post);
            if ($locked->item_id === null) {
                return $locked;
            }

            $key = Item::withTrashed()->whereKey($locked->item_id)->value('key');
            $locked->update(['item_id' => null]);
            FeedbackEvent::record($locked->portal, $locked, $actor, 'card_unlinked', ['title' => $locked->title, 'card' => $key]);

            return $locked;
        });
    }

    /** @param  array{enabled: bool, slug: string, title: string, intro: ?string}  $data */
    public static function savePortal(FeedbackPortal $portal, array $data, User $actor): FeedbackPortal
    {
        return DB::transaction(function () use ($portal, $data, $actor) {
            $portal->fill($data);
            $changed = array_keys($portal->getDirty());
            $creating = ! $portal->exists;
            $portal->save();

            if ($creating || $changed !== []) {
                FeedbackEvent::record($portal, null, $actor, 'portal_updated', [
                    'fields' => $creating ? ['created'] : array_values($changed),
                    'enabled' => $portal->enabled,
                ]);
            }

            return $portal;
        });
    }

    private static function lock(FeedbackPost $post): FeedbackPost
    {
        return FeedbackPost::whereKey($post->id)->lockForUpdate()->with('portal')->firstOrFail();
    }

    /** The card's description: the idea as plain text in HTML paragraphs, and where it came from. */
    private static function cardDescription(FeedbackPost $post, FeedbackPortal $portal): string
    {
        $paragraphs = collect(preg_split("/\n{2,}/", (string) $post->body))
            ->filter(fn ($p) => trim($p) !== '')
            ->map(fn ($p) => '<p>'.nl2br(e(trim($p)), false).'</p>')
            ->implode('');

        $url = e($post->publicUrl($portal));

        return $paragraphs.'<p>From the feedback portal: <a href="'.$url.'">'.e($portal->title).' #'.$post->number.'</a> ('.$post->votes_count.' votes)</p>';
    }
}
