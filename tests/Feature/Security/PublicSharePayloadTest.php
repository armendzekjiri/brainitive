<?php

namespace Tests\Feature\Security;

use App\Models\Bookmark;
use App\Models\Plan;
use App\Models\SnippetCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review, item A: a public plan share sends an explicit whitelist — only what the public chart shows,
 * respecting the plan's column settings; no internal issue ids.
 */
class PublicSharePayloadTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function sharedPlan(array $columns): Plan
    {
        $plan = Plan::factory()->create([
            'project_id' => $this->project->id, 'created_by' => $this->editor->id, 'share_token' => str()->random(40),
        ]);
        // raw write: older documents can hold keys the normaliser no longer keeps
        $plan->forceFill([
            'settings' => ['columns' => $columns],
            'data' => ['rows' => [[
                'id' => 'r1', 'name' => 'Launch', 'level' => 0, 'who' => 'Jane', 'status' => 'at_risk', 'notes' => 'Budget cut',
                'itemId' => 987654, 'collapsed' => true, 'secret' => 'not a plan field',
                'bars' => [['id' => 'b1', 'start' => '2026-11-01', 'end' => '2026-11-05', 'type' => 'bar', 'color' => '#0E83DC', 'label' => 'Build', 'progress' => 40, 'extra' => 'x']],
            ]], 'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b1', 'note' => 'x']]],
        ])->saveQuietly();

        return $plan;
    }

    public function test_hidden_status_and_internal_issue_ids_are_not_sent(): void
    {
        $plan = $this->sharedPlan(['status' => false, 'who' => true, 'notes' => false]);

        $this->get(route('plans.public', $plan->share_token))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.data.rows.0', [
                    'id' => 'r1', 'name' => 'Launch', 'level' => 0, 'collapsed' => true, 'who' => 'Jane',
                    'bars' => [['id' => 'b1', 'start' => '2026-11-01', 'end' => '2026-11-05', 'type' => 'bar', 'color' => '#0E83DC', 'label' => 'Build', 'progress' => 40]],
                ])
                ->where('plan.data.dependencies', [['id' => 'd1', 'from' => 'b1', 'to' => 'b1']])
            );
    }

    public function test_visible_columns_are_sent(): void
    {
        $plan = $this->sharedPlan(['status' => true, 'who' => false, 'notes' => true]);

        $this->get(route('plans.public', $plan->share_token))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.data.rows.0.status', 'at_risk')
                ->where('plan.data.rows.0.notes', 'Budget cut')
                ->missing('plan.data.rows.0.who')
                ->missing('plan.data.rows.0.itemId')
            );
    }

    public function test_public_snippets_and_bookmarks_send_only_what_the_page_shows(): void
    {
        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Public', 'visibility' => 'public']);
        $snippet = $collection->snippets()->create(['created_by' => $this->editor->id, 'title' => 'Hello', 'content' => 'echo 1', 'language' => 'bash', 'visibility' => 'inherit']);

        $this->get(route('snippet-collections.public.show', $collection->uuid))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('collection.name', 'Public')
                ->where('collection.snippets.0.content', 'echo 1')
                ->missing('collection.workspace')
                ->missing('collection.workspace_id')
                ->missing('collection.created_by')
                ->missing('collection.snippets.0.created_by')
            );
        $this->get(route('snippets.public.show', $snippet->uuid))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('snippet.title', 'Hello')
                ->missing('snippet.collection_id')
                ->missing('snippet.created_by')
                ->missing('collection.workspace_id')
            );

        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Links', 'visibility' => 'public', 'settings' => ['internal' => 'x']]);
        $bookmark->items()->create(['type' => 'link', 'title' => 'Site', 'url' => 'https://example.com', 'created_by' => $this->editor->id, 'metadata' => ['favicon_url' => 'https://example.com/f.ico', 'internal' => 'x']]);

        $this->get(route('bookmarks.public.show', $bookmark->uuid))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bookmark.name', 'Links')
                ->where('bookmark.items.0.url', 'https://example.com')
                ->where('bookmark.items.0.metadata', ['favicon_url' => 'https://example.com/f.ico'])
                ->missing('bookmark.settings')
                ->missing('bookmark.workspace_id')
                ->missing('bookmark.created_by')
                ->missing('bookmark.items.0.created_by')
                ->missing('bookmark.items.0.bookmark_id')
            );
    }
}
