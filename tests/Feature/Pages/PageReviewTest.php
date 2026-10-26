<?php

namespace Tests\Feature\Pages;

use App\Models\PageReview;
use App\Notifications\ReviewDecidedNotification;
use App\Notifications\ReviewRequestedNotification;
use App\Support\PageReviews\ReviewGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Document review (BRAI-80): requesting a review, who sees the snapshot, decisions, stale reviews,
 * reviewers leaving the project and the review notifications.
 */
class PageReviewTest extends TestCase
{
    use InteractsWithPageReviews;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
    }

    public function test_requesting_a_review_freezes_a_snapshot_of_the_draft(): void
    {
        $page = $this->publishedPage();
        $this->editDraft($page, '<p>Proposed text</p>');

        $review = $this->requestReview($page, [$this->viewer, $this->editor], message: 'Please check the wording');

        $this->assertSame('pending', $review->status);
        $this->assertSame('<p>Proposed text</p>', $review->snapshotHtml());
        $this->assertSame('Runbook', $review->snapshot_name);
        $this->assertSame($page->fresh()->draftHash(), $review->content_hash);
        $this->assertSame($page->fresh()->edit_revision, $review->edit_revision);
        $this->assertSame('Please check the wording', $review->message);
        $this->assertEqualsCanonicalizing([$this->viewer->id, $this->editor->id], $review->reviewers()->pluck('user_id')->all());

        // the snapshot doesn't move when the draft does
        $this->editDraft($page, '<p>Changed again</p>');
        $this->assertSame('<p>Proposed text</p>', $review->fresh()->snapshotHtml());
    }

    public function test_reviewers_must_be_project_members_other_than_the_author(): void
    {
        $page = $this->draftPage();

        foreach ([[$this->outsider->id], [$this->author->id], []] as $ids) {
            $this->actingAs($this->author)
                ->post($this->pageRoute('pages.reviews.store', $page), ['reviewer_ids' => $ids])
                ->assertSessionHasErrors();
        }
        $this->assertSame(0, PageReview::count());

        // readers can't ask for a review of a page they can't edit
        $published = $this->publishedPage();
        $this->actingAs($this->viewer)
            ->post($this->pageRoute('pages.reviews.store', $published), ['reviewer_ids' => [$this->editor->id]])
            ->assertForbidden();
    }

    public function test_candidates_are_the_people_who_can_open_the_project(): void
    {
        $page = $this->draftPage();

        $ids = collect($this->actingAs($this->author)->getJson($this->pageRoute('pages.reviews.candidates', $page))
            ->assertOk()->json('users'))->pluck('id');

        $this->assertTrue($ids->contains($this->viewer->id));
        $this->assertTrue($ids->contains($this->admin->id));
        $this->assertFalse($ids->contains($this->author->id));
        $this->assertFalse($ids->contains($this->outsider->id));

        // only people who may ask for a review get the list (it carries emails)
        $this->actingAs($this->viewer)->getJson($this->pageRoute('pages.reviews.candidates', $page))->assertForbidden();
        $this->assertContains($this->actingAs($this->outsider)->getJson($this->pageRoute('pages.reviews.candidates', $page))->status(), [403, 404]);
    }

    public function test_a_reviewer_sees_the_snapshot_but_not_the_unpublished_page(): void
    {
        $page = $this->draftPage(['name' => 'Secret plan', 'content' => $this->html('<p>Draft for review</p>')]);
        $review = $this->requestReview($page, [$this->viewer]);

        // the reviewer (a project viewer) can't open an author-only draft…
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))->assertForbidden();

        // …but sees exactly the snapshot through the review
        $this->actingAs($this->viewer)->get($this->reviewRoute('pages.reviews.show', $review))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Pages/Review')
                ->where('review.snapshot_html', '<p>Draft for review</p>')
                ->where('review.snapshot_name', 'Secret plan')
                ->where('page.can_open', false)
                ->where('can.decide', true)
                ->where('can.cancel', false)
                ->missing('page.content')
                ->missing('page.draft_content'));

        // the draft moving on doesn't leak into the review
        $this->editDraft($page, '<p>Private follow-up</p>');
        $this->actingAs($this->viewer)->get($this->reviewRoute('pages.reviews.show', $review))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('review.snapshot_html', '<p>Draft for review</p>')
                ->where('review.stale', true));
    }

    public function test_visibility_matrix(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer]);
        $url = $this->reviewRoute('pages.reviews.show', $review);

        $this->actingAs($this->author)->get($url)->assertOk();     // asked for it
        $this->actingAs($this->admin)->get($url)->assertOk();      // project admin: can edit the draft
        $this->actingAs($this->viewer)->get($url)->assertOk();     // reviewer
        $this->actingAs($this->editor)->get($url)->assertForbidden(); // project editor, not a reviewer, can't see the draft
        $this->assertContains($this->actingAs($this->outsider)->get($url)->status(), [403, 404]);

        // a review id only resolves under its own page
        $other = $this->draftPage();
        $this->actingAs($this->author)
            ->get(route('pages.reviews.show', [$this->workspace->slug, $this->project->slug, $other->uuid, $review->uuid]))
            ->assertNotFound();

        // non-reviewers can't decide, comment or cancel
        $this->decide($review, $this->editor, 'approved')->assertForbidden();
        $this->actingAs($this->editor)->post($this->reviewRoute('pages.reviews.comments.store', $review), ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($this->viewer)->post($this->reviewRoute('pages.reviews.cancel', $review))->assertForbidden();
    }

    public function test_status_follows_the_decisions_of_all_reviewers(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer, $this->editor]);

        $this->decide($review, $this->viewer, 'approved')->assertSessionHasNoErrors();
        $this->assertSame('pending', $review->fresh()->status);

        $this->decide($review, $this->editor, 'changes_requested', 'Fix the intro')->assertSessionHasNoErrors();
        $this->assertSame('changes_requested', $review->fresh()->status);

        $this->decide($review, $this->editor, 'approved')->assertSessionHasNoErrors();
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertTrue(ReviewGate::approved($page->fresh()));
    }

    public function test_editing_the_draft_makes_the_review_stale_and_it_cannot_be_approved(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer]);
        $this->decide($review, $this->viewer, 'approved');
        $this->assertTrue(ReviewGate::approved($page->fresh()));

        $this->editDraft($page, '<p>Edited after the approval</p>');

        // the approval no longer covers the draft
        $this->assertFalse(ReviewGate::approved($page->fresh()));
        $this->actingAs($this->author)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('review.latest.uuid', $review->uuid)
                ->where('review.latest.stale', true)
                ->where('review.draft_hash', $page->fresh()->draftHash()));

        // a stale snapshot can't be (re-)approved, but changes can still be requested
        $second = $this->requestReview($page, [$this->viewer]);
        $this->editDraft($page, '<p>Moving target</p>');
        $this->decide($second, $this->viewer, 'approved')->assertSessionHasErrors('review');
        $this->decide($second, $this->viewer, 'changes_requested')->assertSessionHasNoErrors();

        // a new request supersedes the open review
        $this->assertSame('superseded', $review->fresh()->status);
    }

    public function test_the_author_can_cancel_a_review(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer]);

        $this->actingAs($this->author)->post($this->reviewRoute('pages.reviews.cancel', $review))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $review->fresh()->status);
        $this->decide($review, $this->viewer, 'approved')->assertForbidden();
    }

    public function test_a_reviewer_removed_from_the_project_drops_out_of_the_status(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer, $this->editor]);
        $this->decide($review, $this->viewer, 'approved');
        $this->assertSame('pending', $review->fresh()->status);

        $this->project->removeUser($this->editor);

        // the pending decision no longer blocks the approval
        $this->assertTrue(ReviewGate::approved($page->fresh()));
        $this->actingAs($this->author)->get($this->reviewRoute('pages.reviews.show', $review))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('review.status', 'approved')
                ->where('review.reviewers', fn ($reviewers) => collect($reviewers)->firstWhere('user_id', $this->editor->id)['active'] === false
                    && collect($reviewers)->firstWhere('user_id', $this->viewer->id)['active'] === true)
                ->etc());
        $this->assertSame('approved', $review->fresh()->status);

        // and the removed person can't open or decide on it any more
        $this->actingAs($this->editor)->get($this->reviewRoute('pages.reviews.show', $review))->assertForbidden();
        $this->decide($review, $this->editor, 'changes_requested')->assertForbidden();
    }

    public function test_a_removed_reviewer_who_requested_changes_no_longer_blocks(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer, $this->editor]);
        $this->decide($review, $this->viewer, 'approved');
        $this->decide($review, $this->editor, 'changes_requested');
        $this->assertFalse(ReviewGate::approved($page->fresh()));

        $this->project->removeUser($this->editor);

        $this->assertTrue(ReviewGate::approved($page->fresh()));
    }

    public function test_a_deleted_reviewer_keeps_their_name_on_the_history(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer, $this->editor]);
        $this->decide($review, $this->editor, 'approved', 'Looks good');
        $name = $this->editor->name;

        $this->editor->delete();

        $row = $review->reviewers()->where('user_name', $name)->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame('approved', $row->decision);
        $this->assertSame('Looks good', $row->comment);

        // the remaining reviewer decides alone
        $this->assertFalse(ReviewGate::approved($page->fresh()));
        $this->decide($review, $this->viewer, 'approved');
        $this->assertTrue(ReviewGate::approved($page->fresh()));
    }

    public function test_requested_notifies_each_reviewer_once_and_never_the_author(): void
    {
        $page = $this->draftPage();
        $this->requestReview($page, [$this->viewer, $this->editor]);

        Notification::assertSentToTimes($this->viewer, ReviewRequestedNotification::class, 1);
        Notification::assertSentToTimes($this->editor, ReviewRequestedNotification::class, 1);
        Notification::assertNotSentTo($this->author, ReviewRequestedNotification::class);

        $payload = (new ReviewRequestedNotification(PageReview::firstOrFail(), $this->author))->toDatabase($this->viewer);
        $this->assertSame('review_requested', $payload['type']);
        $this->assertStringContainsString('/reviews/', $payload['url']);
    }

    public function test_decided_notifies_the_author_only_when_the_decision_changes(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer]);

        $this->decide($review, $this->viewer, 'changes_requested', 'Too long');
        $this->decide($review, $this->viewer, 'changes_requested', 'Too long');   // same again: silent
        Notification::assertSentToTimes($this->author, ReviewDecidedNotification::class, 1);

        $this->decide($review, $this->viewer, 'approved');
        Notification::assertSentToTimes($this->author, ReviewDecidedNotification::class, 2);
        Notification::assertNotSentTo($this->viewer, ReviewDecidedNotification::class);
    }

    public function test_only_project_admins_switch_review_required(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->author)
            ->put($this->pageRoute('pages.reviews.settings', $page), ['review_required' => true])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put($this->pageRoute('pages.reviews.settings', $page), ['review_required' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($page->fresh()->review_required);

        $this->actingAs($this->author)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('review.required', true)
                ->where('review.can_manage', false)
                ->where('review.can_request', true));
    }

    public function test_a_reviewer_of_a_published_page_is_pointed_to_the_review(): void
    {
        $page = $this->publishedPage();
        $this->editDraft($page, '<p>Proposal</p>');
        $review = $this->requestReview($page, [$this->viewer]);

        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->missing('page.draft_content')
                ->where('review.latest', null)
                ->where('review.draft_hash', null)
                ->where('review.assigned.0.uuid', $review->uuid));
    }
}
