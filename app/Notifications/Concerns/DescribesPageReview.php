<?php

namespace App\Notifications\Concerns;

use App\Models\PageReview;

/** Link and context line ("Website · Runbook") of a page review, for catalog notifications (BRAI-80). */
trait DescribesPageReview
{
    protected function reviewUrl(PageReview $review): string
    {
        $page = $review->page;
        $project = $page?->project;
        $workspace = $project?->workspace;

        return $workspace && $project && $page
            ? route('pages.reviews.show', [$workspace->slug, $project->slug, $page->uuid, $review->uuid])
            : url('/');
    }

    protected function reviewContext(PageReview $review): ?string
    {
        $project = $review->page?->project;

        return $project ? $project->name.' · '.$review->snapshot_name : $review->snapshot_name;
    }
}
