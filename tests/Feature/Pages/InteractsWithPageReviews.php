<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Models\PageReview;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the document review tests (BRAI-80), on top of InteractsWithPages.
 */
trait InteractsWithPageReviews
{
    /**
     * @param  list<User>  $reviewers
     */
    protected function requestReview(Page $page, array $reviewers, ?User $as = null, ?string $message = null): PageReview
    {
        $this->actingAs($as ?? $this->author)
            ->post($this->pageRoute('pages.reviews.store', $page), [
                'reviewer_ids' => array_map(fn (User $user) => $user->id, $reviewers),
                'message' => $message,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return PageReview::where('page_id', $page->id)->latest('id')->firstOrFail();
    }

    protected function decide(PageReview $review, User $reviewer, string $decision, ?string $comment = null): TestResponse
    {
        return $this->actingAs($reviewer)->post($this->reviewRoute('pages.reviews.decide', $review), [
            'decision' => $decision,
            'comment' => $comment,
        ]);
    }

    protected function reviewRoute(string $name, PageReview $review): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $review->page->uuid, $review->uuid]);
    }

    /** Autosave new HTML into the page's draft, as the editor does. */
    protected function editDraft(Page $page, string $html, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->author)
            ->putJson($this->pageRoute('pages.draft.save', $page), ['content' => json_encode($this->html($html))])
            ->assertOk();
    }
}
