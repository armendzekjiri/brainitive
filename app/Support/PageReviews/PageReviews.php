<?php

namespace App\Support\PageReviews;

use App\Models\Page;
use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\PageReviewReviewer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of page reviews (BRAI-80). Every write locks the page row, so requests, decisions and
 * cancels serialize with publishing (ReviewGate) — a publish never sees half a decision.
 *
 * Status (open reviews): changes_requested if any ACTIVE reviewer asked for changes, approved when all
 * active reviewers approved, else pending. Active = a reviewer whose account still exists and can open
 * the project; it's recomputed on every read, so someone removed from the project simply drops out
 * (no membership hooks), and a deleted account keeps its name on the history.
 */
final class PageReviews
{
    /**
     * Ask for a review of the page's current draft. An earlier open review is superseded.
     *
     * @param  list<int>  $reviewerIds  validated: people who can open the project, not the actor
     */
    public static function request(Page $page, User $actor, array $reviewerIds, ?string $message): PageReview
    {
        return DB::transaction(function () use ($page, $actor, $reviewerIds, $message) {
            $locked = self::lock($page);

            PageReview::where('page_id', $locked->id)->open()->update(['status' => 'superseded', 'closed_at' => now()]);

            $state = $locked->draftState();
            $content = $state['content'];
            if (is_array($content)) {
                unset($content['excalidrawEmbeds']); // not hashed, not shown: don't copy big scene data
            }
            $review = PageReview::create([
                'page_id' => $locked->id,
                'requested_by' => $actor->id,
                'requested_by_name' => $actor->name,
                'message' => $message !== null && trim($message) !== '' ? trim($message) : null,
                'status' => 'pending',
                'snapshot_name' => $state['name'],
                'snapshot_content' => $content,
                'content_hash' => $locked->draftHash(),
                'edit_revision' => $locked->edit_revision,
            ]);

            foreach (User::whereIn('id', $reviewerIds)->orderBy('name')->get(['id', 'name']) as $user) {
                $review->reviewers()->create(['user_id' => $user->id, 'user_name' => $user->name]);
            }

            DB::afterCommit(fn () => ReviewNotifications::requested($review, $actor));

            return $review;
        });
    }

    /**
     * A reviewer approves or requests changes. Repeating the same decision changes nothing and tells
     * nobody; approving a stale snapshot is refused (the draft moved on — ask for a new review).
     */
    public static function decide(PageReview $review, User $actor, string $decision, ?string $comment): PageReview
    {
        return DB::transaction(function () use ($review, $actor, $decision, $comment) {
            $page = self::lock($review->page);
            $review->refresh();

            if (! $review->isOpen()) {
                throw ValidationException::withMessages(['review' => 'This review is closed.']);
            }
            if ($decision === 'approved' && $review->isStale($page)) {
                throw ValidationException::withMessages(['review' => 'The draft changed after this review was requested. Ask for a new review to approve it.']);
            }

            $row = $review->reviewers()->where('user_id', $actor->id)->firstOrFail();
            $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
            $changed = $row->decision !== $decision;

            if ($changed || $row->comment !== $comment) {
                $row->update(['decision' => $decision, 'comment' => $comment, 'decided_at' => now()]);
            }
            self::recompute($review, $page);

            if ($changed) {
                DB::afterCommit(fn () => ReviewNotifications::decided($review, $decision, $comment, $actor));
            }

            return $review;
        });
    }

    public static function cancel(PageReview $review): void
    {
        DB::transaction(function () use ($review) {
            self::lock($review->page);
            $review->refresh();
            if ($review->isOpen()) {
                $review->update(['status' => 'cancelled', 'closed_at' => now()]);
            }
        });
    }

    /**
     * Start a thread (optionally anchored to a quote of the snapshot) or reply to one.
     *
     * @param  array{body: string, parent?: PageComment|null, quote?: string|null, prefix?: string|null, suffix?: string|null}  $data
     */
    public static function comment(PageReview $review, User $actor, array $data): PageComment
    {
        $parent = $data['parent'] ?? null;
        $quote = $parent ? null : QuoteAnchor::normalize(trim((string) ($data['quote'] ?? '')));

        $comment = PageComment::create([
            'page_id' => $review->page_id,
            'page_review_id' => $parent?->page_review_id ?? $review->id,
            'parent_id' => $parent?->id,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'body' => trim($data['body']),
            'quote' => $quote !== null && $quote !== '' ? $quote : null,
            'quote_prefix' => $quote ? mb_substr((string) ($data['prefix'] ?? ''), -QuoteAnchor::CONTEXT * 2) : null,
            'quote_suffix' => $quote ? mb_substr((string) ($data['suffix'] ?? ''), 0, QuoteAnchor::CONTEXT * 2) : null,
        ]);

        // a reply reopens nothing by itself; resolving stays an explicit action
        ReviewNotifications::commented($comment, $actor);

        return $comment;
    }

    public static function resolve(PageComment $thread, User $actor, bool $resolved): void
    {
        $thread->update($resolved
            ? ['resolved_at' => $thread->resolved_at ?? now(), 'resolved_by' => $thread->resolved_by ?? $actor->id]
            : ['resolved_at' => null, 'resolved_by' => null]);
    }

    /** Recompute (and store) the status of an open review from its active reviewers. */
    public static function recompute(PageReview $review, ?Page $page = null): string
    {
        if (! $review->isOpen()) {
            return $review->status;
        }

        $page ??= $review->page;
        $reviewers = $review->reviewers()->get();
        $active = self::activeUserIds($page, $reviewers->pluck('user_id')->filter()->all());
        $decisions = $reviewers->filter(fn (PageReviewReviewer $r) => $r->user_id !== null && in_array($r->user_id, $active, true))
            ->pluck('decision');

        $status = match (true) {
            $decisions->contains('changes_requested') => 'changes_requested',
            $decisions->isNotEmpty() && $decisions->every(fn ($d) => $d === 'approved') => 'approved',
            default => 'pending',
        };

        if ($status !== $review->status) {
            // only while still open: a lock-free read must never reopen a review closed meanwhile
            PageReview::whereKey($review->id)->whereIn('status', PageReview::OPEN)->update(['status' => $status]);
            $review->setAttribute('status', $status)->syncOriginalAttribute('status');
        }
        $review->setRelation('reviewers', $reviewers);

        return $status;
    }

    /**
     * Of these user ids, the ones that can still open the page's project.
     *
     * @param  array<int, int>  $userIds
     * @return list<int>
     */
    public static function activeUserIds(Page $page, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $page->project->accessibleUsers()->whereIn('users.id', $userIds)->pluck('users.id')
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Whether the user is an active reviewer of the review. */
    public static function isActiveReviewer(PageReview $review, User $user): bool
    {
        return $review->reviewers()->where('user_id', $user->id)->exists()
            && self::activeUserIds($review->page, [$user->id]) !== [];
    }

    private static function lock(Page $page): Page
    {
        return Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
    }
}
