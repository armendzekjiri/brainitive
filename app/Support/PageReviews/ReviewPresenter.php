<?php

namespace App\Support\PageReviews;

use App\Models\Page;
use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\PageReviewReviewer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Arrays the review UI reads (BRAI-80; TypeScript: "Document review" section of types/index.d.ts).
 */
final class ReviewPresenter
{
    /**
     * The `review` prop of Pages/Show: settings, the latest open review (editors) and the reviews
     * waiting for this user's decision.
     *
     * @return array<string, mixed>|null
     */
    public static function forPage(Page $page, User $user, bool $canUpdate): ?array
    {
        if (! $page->isPage() || $page->is_whiteboard) {
            return null;
        }

        $latest = $canUpdate ? $page->reviews()->open()->first() : null;
        if ($latest) {
            PageReviews::recompute($latest->setRelation('page', $page), $page);
        }

        $assigned = PageReview::where('page_id', $page->id)->open()
            ->whereHas('reviewers', fn ($q) => $q->where('user_id', $user->id)->where('decision', 'pending'))
            ->orderByDesc('id')->get()
            ->map(fn (PageReview $review) => [
                'uuid' => $review->uuid,
                'requested_by_name' => $review->requested_by_name,
                'url' => self::url($review, $page),
            ])->values()->all();

        return [
            'required' => (bool) $page->review_required,
            'can_manage' => $user->can('manage', [PageReview::class, $page]),
            'can_request' => $user->can('request', [PageReview::class, $page]),
            'draft_hash' => $canUpdate ? $page->draftHash() : null,
            'latest' => $latest ? self::summary($latest, $page) : null,
            'assigned' => $assigned,
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(PageReview $review, Page $page): array
    {
        $reviewers = $review->relationLoaded('reviewers') ? $review->reviewers : $review->reviewers()->get();
        $active = PageReviews::activeUserIds($page, $reviewers->pluck('user_id')->filter()->all());

        return [
            'uuid' => $review->uuid,
            'number' => $review->number(),
            'status' => $review->status,
            'stale' => $review->isOpen() && $review->isStale($page),
            'content_hash' => $review->content_hash,
            'snapshot_name' => $review->snapshot_name,
            'message' => $review->message,
            'requested_by' => ['id' => $review->requested_by, 'name' => $review->requested_by_name],
            'created_at' => $review->created_at?->toIso8601String(),
            'closed_at' => $review->closed_at?->toIso8601String(),
            'url' => self::url($review, $page),
            'reviewers' => $reviewers->map(fn (PageReviewReviewer $r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'name' => $r->user_name,
                'decision' => $r->decision,
                'comment' => $r->comment,
                'decided_at' => $r->decided_at?->toIso8601String(),
                // false: the account was deleted or can't open the project any more → not counted
                'active' => $r->user_id !== null && in_array($r->user_id, $active, true),
            ])->values()->all(),
        ];
    }

    /**
     * Threads a viewer of $viewing sees: those of every review of the page they can open (editors:
     * all; reviewers: the reviews they're on), each marked outdated against $viewing's snapshot.
     *
     * @return list<array<string, mixed>>
     */
    public static function threads(PageReview $viewing, Page $page, User $user, bool $canUpdate): array
    {
        $reviewIds = $canUpdate
            ? PageReview::where('page_id', $page->id)->pluck('id')
            : PageReview::where('page_id', $page->id)->whereHas('reviewers', fn ($q) => $q->where('user_id', $user->id))->pluck('id');
        $numbers = PageReview::where('page_id', $page->id)->orderBy('id')->pluck('uuid', 'id')
            ->keys()->flip()->map(fn ($index) => $index + 1);
        $uuids = PageReview::whereIn('id', $reviewIds)->pluck('uuid', 'id');
        $text = QuoteAnchor::text($viewing->snapshotHtml());

        /** @var Collection<int, PageComment> $roots */
        $roots = PageComment::where('page_id', $page->id)->whereNull('parent_id')->whereIn('page_review_id', $reviewIds)
            ->with(['replies', 'resolver:id,name'])->orderBy('id')->get();

        return $roots->map(fn (PageComment $thread) => [
            'uuid' => $thread->uuid,
            'review_uuid' => $uuids[$thread->page_review_id] ?? null,
            'review_number' => $numbers[$thread->page_review_id] ?? null,
            'quote' => $thread->quote,
            'prefix' => $thread->quote_prefix,
            'suffix' => $thread->quote_suffix,
            'outdated' => $thread->quote !== null && QuoteAnchor::locate($text, $thread->quote, $thread->quote_prefix, $thread->quote_suffix) === null,
            'resolved' => $thread->resolved_at !== null,
            'resolved_at' => $thread->resolved_at?->toIso8601String(),
            'resolved_by_name' => $thread->resolver?->name,
            'comments' => collect([$thread])->concat($thread->replies)->map(fn (PageComment $comment) => [
                'uuid' => $comment->uuid,
                'author' => ['id' => $comment->user_id, 'name' => $comment->user_name],
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();
    }

    public static function url(PageReview $review, Page $page): string
    {
        $project = $page->project;

        return route('pages.reviews.show', [$project->workspace->slug, $project->slug, $page->uuid, $review->uuid]);
    }
}
