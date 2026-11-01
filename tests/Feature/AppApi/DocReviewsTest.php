<?php

namespace Tests\Feature\AppApi;

use App\Models\ContentReference;
use App\Models\Page;
use App\Models\PageComment;
use App\Models\User;
use App\Support\PageReviews\PageReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 doc history, reviews (threads, decisions), links / backlinks and smart link chips (BRAI-413):
 * PageReviewPolicy (a reviewer sees the snapshot of a draft they can't otherwise open), ContentReferences,
 * SmartLinks visibility.
 */
class DocReviewsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function doc(array $attributes = []): Page
    {
        return Page::factory()->create($attributes + ['project_id' => $this->project->id, 'created_by' => $this->editor->id,
            'content' => ['type' => 'lexical', 'html' => '<p>The quick brown fox jumps.</p>']]);
    }

    public function test_versions_newest_first_with_text(): void
    {
        $doc = $this->doc(['status' => 'published', 'published_at' => now()]);
        $doc->recordVersion($this->editor, 'First');
        $doc->update(['content' => ['type' => 'lexical', 'html' => '<p>Second <em>text</em></p>']]);
        $doc->recordVersion($this->editor, 'Second');

        $list = $this->getJson("/api/v1/pages/{$doc->uuid}/versions?limit=1", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.0.version', 2)->assertJsonPath('data.0.message', 'Second')->assertJsonPath('data.0.author.id', $this->editor->id);
        $this->getJson("/api/v1/pages/{$doc->uuid}/versions?limit=1&cursor=".urlencode($list->json('next_cursor')), $this->headers($this->viewer))
            ->assertOk()->assertJsonPath('data.0.version', 1)->assertJsonPath('next_cursor', null);
        $this->getJson("/api/v1/pages/{$doc->uuid}/versions/1", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.markdown', 'The quick brown fox jumps.');
        $this->getJson("/api/v1/pages/{$doc->uuid}/versions/9", $this->headers($this->viewer))->assertNotFound();
        $this->getJson("/api/v1/pages/{$doc->uuid}/versions", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/pages/{$doc->uuid}/versions", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $draft = $this->doc(['created_by' => $this->viewer->id]);
        $this->getJson("/api/v1/pages/{$draft->uuid}/versions", $this->headers())->assertNotFound(); // someone else's draft
    }

    public function test_a_reviewer_reads_the_snapshot_of_a_draft_they_cant_open_and_decides(): void
    {
        $doc = $this->doc(['name' => 'Spec']);
        $review = PageReviews::request($doc, $this->editor, [$this->viewer->id], 'Please check');

        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers($this->viewer))->assertNotFound(); // the draft itself
        $this->getJson("/api/v1/pages/{$doc->uuid}/reviews", $this->headers($this->viewer))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $review->uuid)->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.reviewers.0.user_id', $this->viewer->id);
        $this->getJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.snapshot_markdown', 'The quick brown fox jumps.')
            ->assertJsonPath('data.message', 'Please check')
            ->assertJsonPath('data.my_decision.decision', 'pending')
            ->assertJsonPath('data.can.decide', true)
            ->assertJsonPath('data.can.open_page', false);

        // someone who is neither editor nor reviewer: 404 everywhere
        $other = $this->projectMember('viewer');
        $this->getJson("/api/v1/pages/{$doc->uuid}/reviews", $this->headers($other))->assertNotFound();
        $this->getJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}", $this->headers($other))->assertNotFound();
        $this->postJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}/decision", ['decision' => 'nope'], $this->headers($other))->assertNotFound();
        $this->postJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}/decision", ['decision' => 'approved'], $this->headers($this->outsider))->assertNotFound();
        // the author sees it but doesn't decide
        $this->postJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}/decision", ['decision' => 'approved'], $this->headers())->assertForbidden();

        $this->postJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}/decision", ['decision' => 'maybe'], $this->headers($this->viewer))
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson("/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}/decision", ['decision' => 'approved', 'comment' => 'LGTM'], $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.my_decision.comment', 'LGTM');

        // the approval lets a review-required doc publish
        $doc->forceFill(['review_required' => true])->saveQuietly();
        $this->postJson("/api/v1/pages/{$doc->uuid}/publish", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_threads_on_a_passage_replies_and_resolve(): void
    {
        $doc = $this->doc();
        $review = PageReviews::request($doc, $this->editor, [$this->viewer->id], null);
        $base = "/api/v1/pages/{$doc->uuid}/reviews/{$review->uuid}";

        $this->postJson("{$base}/comments", ['body' => 'Which fox?', 'quote' => 'a cat'], $this->headers($this->viewer))
            ->assertUnprocessable()->assertJsonValidationErrors('quote');
        $requestId = (string) Str::uuid();
        $thread = $this->postJson("{$base}/comments", ['body' => 'Which fox?', 'quote' => 'brown fox', 'prefix' => 'The quick ', 'suffix' => ' jumps', 'request_id' => $requestId], $this->headers($this->viewer))
            ->assertCreated()
            ->assertJsonPath('data.quote', 'brown fox')
            ->assertJsonPath('data.outdated', false)
            ->assertJsonPath('data.comments.0.body', 'Which fox?')
            ->assertJsonPath('data.comments.0.author.id', $this->viewer->id);
        $this->postJson("{$base}/comments", ['body' => 'Which fox?', 'quote' => 'brown fox', 'prefix' => 'The quick ', 'suffix' => ' jumps', 'request_id' => $requestId], $this->headers($this->viewer))
            ->assertOk()->assertJsonPath('data.uuid', $thread->json('data.uuid'));
        $this->assertSame(1, PageComment::count());

        $this->postJson("{$base}/comments", ['body' => 'The red one', 'parent_uuid' => $thread->json('data.uuid')], $this->headers())
            ->assertCreated()->assertJsonCount(2, 'data.comments')->assertJsonPath('data.comments.1.body', 'The red one');
        $this->postJson("{$base}/comments", ['body' => 'x', 'parent_uuid' => (string) Str::uuid()], $this->headers())->assertNotFound();

        $this->patchJson("/api/v1/pages/{$doc->uuid}/review-threads/{$thread->json('data.uuid')}", ['resolved' => true], $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.resolved', true);
        $this->getJson("{$base}", $this->headers())->assertJsonPath('data.threads.0.resolved', true)->assertJsonCount(2, 'data.threads.0.comments');
        $this->patchJson("/api/v1/pages/{$doc->uuid}/review-threads/{$thread->json('data.uuid')}", ['resolved' => false], $this->headers($this->outsider))->assertNotFound();

        // a cancelled review takes no new threads (replies still)
        PageReviews::cancel($review->fresh());
        $this->postJson("{$base}/comments", ['body' => 'Late'], $this->headers($this->viewer))->assertForbidden();
    }

    public function test_links_and_backlinks_of_visible_content_only(): void
    {
        $doc = $this->doc(['status' => 'published', 'published_at' => now()]);
        $item = $this->item(['title' => 'Login bug']);
        $private = $this->doc(['created_by' => $this->viewer->id, 'name' => 'Viewer notes']);
        ContentReference::create(['project_id' => $this->project->id, 'source_type' => 'item', 'source_id' => $item->id, 'target_type' => 'page', 'target_id' => $doc->id, 'origin' => 'manual', 'created_by' => $this->editor->id]);
        ContentReference::create(['project_id' => $this->project->id, 'source_type' => 'page', 'source_id' => $private->id, 'target_type' => 'page', 'target_id' => $doc->id, 'origin' => 'manual', 'created_by' => $this->viewer->id]);

        $this->getJson("/api/v1/pages/{$doc->uuid}/links", $this->headers())->assertOk()
            ->assertJsonCount(0, 'data.related')
            ->assertJsonCount(1, 'data.referenced_by')
            ->assertJsonPath('data.referenced_by.0.type', 'item')
            ->assertJsonPath('data.referenced_by.0.title', 'Login bug')
            ->assertJsonPath('data.referenced_by.0.key', $item->key)
            ->assertJsonPath('data.referenced_by.0.origin', 'manual');
        // the viewer sees their own draft among the backlinks
        $this->getJson("/api/v1/pages/{$doc->uuid}/links", $this->headers($this->viewer))->assertOk()->assertJsonCount(2, 'data.referenced_by');
        $this->getJson("/api/v1/pages/{$doc->uuid}/links", $this->headers($this->outsider))->assertNotFound();
    }

    public function test_smart_link_chips_by_ref_and_by_url(): void
    {
        $item = $this->item(['title' => 'Login bug']);
        $doc = $this->doc(['status' => 'published', 'published_at' => now(), 'name' => 'Guide']);
        $private = $this->doc(['created_by' => $this->viewer->id]);
        $url = route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]);
        $query = http_build_query(['workspace' => $this->workspace->slug, 'refs' => ["item:{$item->id}", "page:{$private->uuid}", 'page:nope'], 'urls' => [$url, 'https://example.com/x', route('pages.show', [$this->workspace->slug, $this->project->slug, $doc->uuid])]]);

        $data = $this->getJson("/api/v1/links/resolve?{$query}", $this->headers())->assertOk()->json('data');
        $this->assertSame(['ok', 'restricted', 'not_found', 'ok', 'not_found', 'ok'], array_column($data, 'state'));
        $this->assertSame(['Login bug', null, null, 'Login bug', null, 'Guide'], array_column($data, 'title'));
        $this->assertSame($url, $data[3]['requested']);
        $this->assertSame("item:{$item->id}", $data[3]['ref']);

        $this->getJson("/api/v1/links/resolve?workspace={$this->workspace->slug}&refs[]=item:{$item->id}", $this->headers($this->outsider))->assertNotFound();
        $this->getJson('/api/v1/links/resolve', $this->headers())->assertUnprocessable()->assertJsonValidationErrors('workspace');
    }
}
