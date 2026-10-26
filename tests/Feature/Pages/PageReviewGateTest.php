<?php

namespace Tests\Feature\Pages;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\UpdatePageTool;
use App\Models\Page;
use App\Support\PageReviews\ReviewGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Review required before publish" (BRAI-80): publish / publish changes are refused server-side
 * unless an approved, non-stale review matches the current draft — checked on the locked page row.
 */
class PageReviewGateTest extends TestCase
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

    public function test_pages_without_the_switch_publish_as_before(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasNoErrors();

        $this->assertTrue($page->fresh()->isPublished());
    }

    public function test_first_publish_needs_an_approved_review(): void
    {
        $page = $this->draftPage(['review_required' => true]);

        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasErrors('review');
        $this->assertFalse($page->fresh()->isPublished());

        // a pending review isn't enough
        $review = $this->requestReview($page, [$this->viewer]);
        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasErrors('review');

        // JSON clients get a 422
        $this->actingAs($this->author)->postJson($this->pageRoute('pages.publish', $page))
            ->assertStatus(422)->assertJsonPath('errors.review.0', ReviewGate::REFUSAL);

        $this->decide($review, $this->viewer, 'approved');
        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertTrue($page->isPublished());
        $this->assertSame(1, $page->current_version);
        // the approved snapshot went live: the review is done
        $this->assertSame('published', $review->fresh()->status);
    }

    public function test_publishing_changes_needs_an_approval_of_exactly_this_draft(): void
    {
        $page = $this->publishedPage(['review_required' => true]);
        $this->editDraft($page, '<p>Version two</p>');

        $publish = fn (array $data = []) => $this->actingAs($this->author)
            ->put($this->pageRoute('pages.publish-changes', $page), $data + ['notify' => false]);

        $publish()->assertSessionHasErrors('review');
        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);

        $review = $this->requestReview($page, [$this->viewer]);
        $this->decide($review, $this->viewer, 'approved');

        // the editor still had unsaved typing: it lands in the draft, which is no longer the approved one
        $publish(['content' => json_encode($this->html('<p>Version two, plus a late edit</p>'))])->assertSessionHasErrors('review');
        $page->refresh();
        $this->assertSame('<p>Live text</p>', $page->content['html']);
        $this->assertSame('<p>Version two, plus a late edit</p>', $page->draft_content['html']); // not lost

        // approve the new draft → publishes it
        $second = $this->requestReview($page, [$this->viewer]);
        $this->decide($second, $this->viewer, 'approved');
        $publish()->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('<p>Version two, plus a late edit</p>', $page->content['html']);
        $this->assertFalse($page->hasUnpublishedChanges());
        $this->assertSame(2, $page->current_version);
        $this->assertSame('published', $second->fresh()->status);
        $this->assertSame('superseded', $review->fresh()->status);
    }

    public function test_an_approval_goes_stale_when_the_draft_changes(): void
    {
        $page = $this->draftPage(['review_required' => true]);
        $review = $this->requestReview($page, [$this->viewer]);
        $this->decide($review, $this->viewer, 'approved');

        $this->editDraft($page, '<p>Slipped in after the approval</p>');

        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasErrors('review');
        $this->assertFalse($page->fresh()->isPublished());
    }

    public function test_the_gate_reads_the_locked_row_not_the_callers_copy(): void
    {
        $page = $this->draftPage(['review_required' => true]);
        $review = $this->requestReview($page, [$this->viewer]);
        $this->decide($review, $this->viewer, 'approved');

        // the request loaded the page while it matched the approval…
        $stale = Page::findOrFail($page->id);
        // …and a concurrent autosave committed before the publish got the lock
        Page::findOrFail($page->id)->saveDraft($this->editor, ['content' => $this->html('<p>Concurrent edit</p>')]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $published = false;
        $result = ReviewGate::publishing($stale, fn () => null, function (Page $locked) use (&$published) {
            $published = true;

            return $locked->publish($this->author);
        });

        $this->assertSame(ReviewGate::REFUSAL, $result);
        $this->assertFalse($published);
        $this->assertFalse($page->fresh()->isPublished());
        $this->assertTrue(collect($queries)->contains(fn (string $sql) => str_contains(strtolower($sql), 'for update')));
    }

    public function test_decisions_lock_the_page_row_too(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });
        $this->decide($review, $this->viewer, 'approved');

        $this->assertTrue(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'from "pages"') && str_contains($sql, 'for update')));
    }

    public function test_removing_the_last_blocking_reviewer_unblocks_publishing(): void
    {
        $page = $this->draftPage(['review_required' => true]);
        $review = $this->requestReview($page, [$this->viewer, $this->editor]);
        $this->decide($review, $this->viewer, 'approved');

        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasErrors('review');

        $this->project->removeUser($this->editor);

        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasNoErrors();
        $this->assertTrue($page->fresh()->isPublished());
    }

    public function test_mcp_edits_and_version_restores_cannot_bypass_the_review(): void
    {
        $page = $this->publishedPage(['review_required' => true]);

        BrainitiveServer::actingAs($this->editor)
            ->tool(UpdatePageTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'page_uuid' => $page->uuid, 'markdown' => 'Agent rewrite'])
            ->assertHasErrors();
        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);

        $this->actingAs($this->author)
            ->post($this->pageRoute('pages.versions.restore', $page, [1]))
            ->assertSessionHasErrors('review');
        $this->assertSame(1, $page->fresh()->current_version);
    }

    public function test_side_doors_cannot_change_a_published_review_required_page(): void
    {
        $page = $this->publishedPage(['review_required' => true, 'icon' => '📄']);

        // the whiteboard save route can't turn the document into a live scene
        $this->actingAs($this->author)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), ['diagram_data' => json_encode(['elements' => [], 'appState' => []])])
            ->assertStatus(422);
        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);

        // a metadata rename / new icon waits in the draft
        $this->actingAs($this->author)->putJson($this->pageRoute('pages.update', $page), ['name' => 'Renamed', 'icon' => '🚀'])->assertOk();
        $page->refresh();
        $this->assertSame('Runbook', $page->name);
        $this->assertSame('Renamed', $page->draft_name);
        $this->assertSame('🚀', $page->draft_icon);

        // and the icon is part of what gets approved
        $review = $this->requestReview($page, [$this->viewer]);
        $this->decide($review, $this->viewer, 'approved');
        $this->actingAs($this->author)->putJson($this->pageRoute('pages.draft.save', $page), ['icon' => '🔥'])->assertOk();
        $this->assertTrue($review->fresh()->isStale());
        $this->actingAs($this->author)->put($this->pageRoute('pages.publish-changes', $page), ['notify' => false])->assertSessionHasErrors('review');
    }

    public function test_presence_shares_the_draft_hash_with_editors_only(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->viewer)->postJson($this->pageRoute('pages.presence', $page))->assertOk()->assertJsonMissingPath('draft_hash');
        $this->actingAs($this->editor)->postJson($this->pageRoute('pages.presence', $page))->assertOk()->assertJsonPath('draft_hash', $page->fresh()->draftHash());
    }

    public function test_review_required_cannot_be_set_on_a_whiteboard(): void
    {
        $board = $this->draftPage(['content' => ['type' => 'excalidraw', 'elements' => [], 'appState' => []]]);

        $this->actingAs($this->admin)->put($this->pageRoute('pages.reviews.settings', $board), ['review_required' => true])->assertForbidden();
    }
}
