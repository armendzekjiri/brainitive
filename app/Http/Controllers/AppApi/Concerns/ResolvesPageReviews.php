<?php

namespace App\Http\Controllers\AppApi\Concerns;

use App\Models\Page;
use App\Models\PageComment;
use App\Models\PageReview;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\ApiKeys\ApiKeyGate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Document reviews (BRAI-80) in the App API (BRAI-413): a reviewer sees the review's snapshot without seeing
 * an unpublished draft itself, so reviews are found through a page of a project the user can open, then
 * PageReviewPolicy::view (editors of the page, active reviewers). Everything else is a 404.
 */
trait ResolvesPageReviews
{
    /** A page of a project the user can open (PagePolicy::view is checked per review, not here). */
    protected function reviewedPage(Request $request, string $uuid): Page
    {
        abort_unless(Str::isUuid($uuid), 404);
        $page = Page::with('project.workspace')->where('uuid', $uuid)->first();
        abort_unless($page instanceof Page && $page->project?->workspace !== null && $request->user()->hasProjectAccess($page->project), 404);
        // a personal API key: the doc must be in its scope and Docs readable (ApiKeyGate), else the same 404
        $key = ApiKeyAccess::forUser($request->user());
        abort_unless($key === null || ApiKeyGate::allows($key, 'view', [$page]), 404);

        return $page;
    }

    protected function visibleReview(Request $request, Page $page, string $uuid): PageReview
    {
        abort_unless(Str::isUuid($uuid), 404);
        $review = PageReview::where('page_id', $page->id)->where('uuid', $uuid)->first();
        abort_unless($review instanceof PageReview, 404);
        $review->setRelation('page', $page);
        abort_unless($request->user()->can('view', $review), 404);

        return $review;
    }

    /** A thread (root comment) of the page, on a review the user may see. */
    protected function visibleThread(Request $request, Page $page, string $uuid): PageComment
    {
        abort_unless(Str::isUuid($uuid), 404);
        $thread = PageComment::where('page_id', $page->id)->where('uuid', $uuid)->whereNull('parent_id')->with('review')->first();
        abort_unless($thread instanceof PageComment && $thread->review !== null, 404);
        $thread->review->setRelation('page', $page);
        abort_unless($request->user()->can('view', $thread->review), 404);

        return $thread;
    }
}
