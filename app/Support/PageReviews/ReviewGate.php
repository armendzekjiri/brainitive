<?php

namespace App\Support\PageReviews;

use App\Models\Page;
use App\Models\PageReview;
use Illuminate\Support\Facades\DB;

/**
 * "Review required before publish" (BRAI-80). Publishing runs through publishing(): one transaction
 * that locks the page row, re-reads it, lets the caller apply the editor's pending changes, and only
 * then compares the draft hash with the approved reviews — so what gets published is exactly what was
 * checked, and a concurrent autosave / decision waits for (or is seen by) the lock.
 */
final class ReviewGate
{
    public const REFUSAL = 'This page needs an approved review of the current draft before it can be published.';

    /**
     * @template T
     *
     * @param  callable(Page): (T|null)  $prepare  apply pending editor changes on the locked page; non-null = stop with that result
     * @param  callable(Page): T  $publish  publish the locked page
     * @return T|string the publish result, or the refusal message
     */
    public static function publishing(Page $page, callable $prepare, callable $publish): mixed
    {
        return DB::transaction(function () use ($page, $prepare, $publish) {
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();

            $early = $prepare($locked);
            if ($early !== null) {
                return $early;
            }

            $hash = $locked->draftHash();
            if ($locked->review_required && ! self::approved($locked, $hash)) {
                return self::REFUSAL;
            }

            $result = $publish($locked);

            // the approved snapshot went live: the review is done
            PageReview::where('page_id', $locked->id)->open()->where('content_hash', $hash)->get()
                ->filter(fn (PageReview $review) => PageReviews::recompute($review->setRelation('page', $locked), $locked) === 'approved')
                ->each(fn (PageReview $review) => $review->update(['status' => 'published', 'closed_at' => now()]));

            return $result;
        });
    }

    /** Whether an open review of exactly this draft is approved by all its active reviewers. */
    public static function approved(Page $page, ?string $hash = null): bool
    {
        $hash ??= $page->draftHash();

        return PageReview::where('page_id', $page->id)->open()->where('content_hash', $hash)->get()
            ->contains(fn (PageReview $review) => PageReviews::recompute($review->setRelation('page', $page), $page) === 'approved');
    }
}
