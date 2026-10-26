<?php

namespace Tests\Feature\Search;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\SearchTool;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Plan;
use App\Models\TaskList;
use App\Models\TaskListItem;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\Feature\Concerns\UsesPassportKeys;

/**
 * The search page, the command palette endpoint and the MCP `search` / `fetch` tools (BRAI-55).
 */
class SearchHttpTest extends SearchTestCase
{
    use UsesPassportKeys;

    public function test_the_search_page_renders_results_counts_and_filter_choices(): void
    {
        $item = $this->item(['title' => 'Gecko launch']);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Gecko notes', 'status' => 'published']);

        $this->assertFileExists(resource_path('js/Pages/Search/Index.tsx'));
        $this->actingAs($this->editor)
            ->get(route('search', ['q' => 'gecko', 'type' => 'issues', 'status' => 'bogus', 'project' => ['x', $this->project->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Index')
                ->where('filters.q', 'gecko')
                ->where('filters.type', 'issues')
                ->where('filters.status', null)
                ->where('filters.project', [$this->project->id])
                ->where('search.total', 1)
                ->where('search.counts.issues', 1)
                ->where('search.counts.docs', 1)
                ->where('search.results.0.id', "item:{$item->id}")
                ->where('search.results.0.url', route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]).'#item-'.$item->id)
                ->where('options.projects.0.id', $this->project->id)
                ->has('options.people')
                ->has('groups', 10));

        // no query: tips, no search
        $this->actingAs($this->editor)->get(route('search'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Search/Index')->where('search', null));
    }

    public function test_guests_are_sent_away(): void
    {
        $this->get(route('search', ['q' => 'gecko']))->assertRedirect(route('login'));
        $this->getJson(route('search.quick', ['q' => 'koala']))->assertUnauthorized();
    }

    public function test_the_palette_gets_top_results_and_places(): void
    {
        $item = $this->item(['title' => 'Koala checkout']);
        $this->board->update(['name' => 'Koala board']);

        $this->actingAs($this->editor)->getJson(route('search.quick', ['q' => 'koala']))
            ->assertOk()
            ->assertJsonPath('results.0.id', "item:{$item->id}")
            ->assertJsonPath('total', 1)
            ->assertJsonPath('boards.0.title', 'Koala board');

        $this->actingAs($this->editor)->getJson(route('search.quick', ['q' => 'k']))
            ->assertOk()->assertJsonPath('results', [])->assertJsonPath('boards', []);
        $this->actingAs($this->outsider)->getJson(route('search.quick', ['q' => 'koala']))
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('boards', []);
    }

    public function test_the_browser_extension_searches_titles_only_in_its_old_shape(): void
    {
        $item = $this->item(['title' => 'Capybara rollout', 'description' => '<p>secret budget numbers</p>']);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Capybara doc', 'status' => 'published', 'content' => ['type' => 'lexical', 'html' => '<p>secret budget numbers</p>']]);
        $this->usePassportKeys();
        Passport::actingAs($this->editor, ['capture']);

        $res = $this->getJson(route('ext.search', ['q' => 'capybara']))->assertOk();
        $this->assertSame([$item->id], array_column($res->json('items'), 'id'));
        $this->assertCount(1, $res->json('pages'));
        $res->assertJsonStructure(['items', 'pages', 'projects', 'bookmarks', 'snippets', 'workspaces']);

        // body text is not searchable with a capture token
        $this->getJson(route('ext.search', ['q' => 'budget']))->assertOk()->assertJsonPath('items', [])->assertJsonPath('pages', []);
    }

    public function test_the_mcp_search_tool_uses_the_index_and_its_handles_are_fetchable(): void
    {
        $item = $this->item(['title' => 'Mongoose rollout']);
        $comment = Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => 'mongoose comment']);
        $ticket = $this->ticket(['subject' => 'Mongoose ticket']);
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Mongoose plan']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->owner->id, 'title' => 'Mongoose task']);
        $capture = Capture::factory()->create(['user_id' => $this->editor->id, 'text' => 'Mongoose capture']);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Mongoose draft', 'status' => 'draft']);

        $handles = [];
        BrainitiveServer::actingAs($this->editor)
            ->tool(SearchTool::class, ['query' => 'mongoose'])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$handles) {
                $json->has('results', 6);
                $handles = array_column($json->toArray()['results'], 'id');
                $json->etc();
            });

        // the comment is read through its issue; the admin's draft isn't there
        $this->assertEqualsCanonicalizing(["item:{$item->id}", "item:{$item->id}", "ticket:{$ticket->id}", "plan:{$plan->id}", "task:{$task->id}", "capture:{$capture->id}"], $handles);
        $this->assertNotNull($comment);

        foreach (array_unique($handles) as $handle) {
            BrainitiveServer::actingAs($this->editor)->tool(FetchTool::class, ['id' => $handle])->assertOk()->assertSee('Mongoose');
        }
        // someone else's capture, and content of projects one can't open, can't be fetched
        BrainitiveServer::actingAs($this->owner)->tool(FetchTool::class, ['id' => "capture:{$capture->id}"])->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(FetchTool::class, ['id' => "ticket:{$ticket->id}"])->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(FetchTool::class, ['id' => "task:{$task->id}"])->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(FetchTool::class, ['id' => "plan:{$plan->id}"])->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(SearchTool::class, ['query' => 'mongoose'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('results', 0));
    }
}
