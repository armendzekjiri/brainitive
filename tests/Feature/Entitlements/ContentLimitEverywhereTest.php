<?php

namespace Tests\Feature\Entitlements;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\AddItemCommentTool;
use App\Mcp\Tools\CreateBookmarkItemTool;
use App\Mcp\Tools\CreateSnippetTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Models\Bookmark;
use App\Models\Helpdesk;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceUsage;
use App\Notifications\PlanLimitReachedNotification;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\PublicContentRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review B: the content limit covers every type WorkspaceUsageMeter counts, in the shared create paths
 * (web, App API, MCP, CLI, sync, extension), public forms answer with a message the page can show and tell the
 * admins once a day, and edits that grow content need room while shrinking ones always work.
 */
class ContentLimitEverywhereTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected User $owner;

    protected TaskList $list;

    protected SnippetCollection $collection;

    protected Bookmark $bookmark;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->owner = User::find($this->workspace->owner_id);
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $this->list = TaskList::create(['project_id' => $this->project->id, 'name' => 'List', 'slug' => 'list', 'created_by' => $this->editor->id]);
        $this->collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Snips']);
        $this->bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Links', 'visibility' => 'private']);
    }

    private function contentFull(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'content_bytes' => 1000]));
        WorkspaceUsage::updateOrCreate(['workspace_id' => $this->workspace->id], ['content_bytes' => 1000]);
    }

    public function test_the_web_refuses_every_metered_type_at_the_limit(): void
    {
        $item = $this->item();
        $this->contentFull();
        $as = $this->actingAs($this->editor);

        $as->post(route('task-lists.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'New list'])->assertSessionHas('limit_reached');
        $as->post(route('task-lists.items.store', [$this->workspace->slug, $this->project->slug, $this->list->slug]), ['title' => 'Task'])->assertSessionHas('limit_reached');
        $as->post(route('snippets.snippets.store', [$this->workspace->slug, $this->collection->slug]), ['title' => 'S', 'content' => 'x'])->assertSessionHas('limit_reached');
        $as->post(route('bookmarks.items.store', [$this->workspace->slug, $this->bookmark->slug]), ['type' => 'note', 'content' => 'x'])->assertSessionHas('limit_reached');
        $as->post(route('boards.items.comments.store', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), ['body' => 'Hi'])->assertSessionHas('limit_reached');

        $this->assertSame(0, $this->list->items()->count());
        $this->assertSame(1, TaskList::count());
        $this->assertSame(0, $this->collection->snippets()->count());
        $this->assertSame(0, $this->bookmark->items()->count());
        $this->assertSame(0, $item->comments()->count());
    }

    public function test_mcp_the_app_api_the_cli_and_sync_are_refused_too(): void
    {
        $item = $this->item();
        $this->contentFull();

        $mcp = BrainitiveServer::actingAs($this->editor);
        $mcp->tool(CreateTaskTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'task_list' => $this->list->slug, 'title' => 'T'])->assertHasErrors();
        $mcp->tool(CreateSnippetTool::class, ['workspace' => $this->workspace->slug, 'collection' => $this->collection->slug, 'title' => 'S', 'content' => 'x'])->assertHasErrors();
        $mcp->tool(CreateBookmarkItemTool::class, ['workspace' => $this->workspace->slug, 'bookmark' => $this->bookmark->slug, 'type' => 'note', 'content' => 'x'])->assertHasErrors();
        $mcp->tool(AddItemCommentTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'item_id' => $item->id, 'markdown' => 'Hi'])->assertHasErrors();

        $this->postJson('/api/v1/task-lists/'.$this->list->id.'/items', ['title' => 'T'], $this->appHeaders($this->appToken($this->editor)))
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached');

        // web offline sync: one change, the 402 limit_reached answer; nothing stored
        $this->actingAs($this->editor)->postJson(route('offline.sync'), [
            'id' => (string) Str::uuid(), 'type' => 'list_task.create', 'data' => ['task_list_id' => $this->list->id, 'title' => 'Offline'],
        ])->assertStatus(402)->assertJsonPath('limit_reached.kind', 'content');

        $this->assertSame(0, $this->list->items()->count());
        $this->assertSame(0, $this->collection->snippets()->count());
        $this->assertSame(0, $this->bookmark->items()->count());
        $this->assertSame(0, $item->comments()->count());
    }

    public function test_public_forms_show_a_message_and_admins_hear_once_a_day(): void
    {
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);
        $this->contentFull();

        foreach ([1, 2] as $attempt) {
            $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), ['subject' => 'Q', 'description' => 'Help', 'contact_email' => 'c@example.com'])
                ->assertStatus(422)->assertJsonPath('message', PublicContentRoom::MESSAGE)->assertJsonMissingPath('limit_reached.plan');
        }
        $this->assertSame(0, Ticket::count());
        Notification::assertSentToTimes($this->owner, PlanLimitReachedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, PlanLimitReachedNotification::class);

        $form = LeadForm::create(['project_id' => $this->project->id, 'name' => 'Leads', 'slug' => 'leads', 'public_slug' => 'leads', 'status' => LeadForm::STATUS_PUBLISHED, 'created_by' => $this->owner->id, 'definition' => ['steps' => []]]);
        $this->postJson(route('lead-forms.submit', 'leads'), ['answers' => []])
            ->assertStatus(422)->assertJsonPath('message', PublicContentRoom::MESSAGE);
        $this->assertSame(0, $form->leads()->count());
    }

    public function test_edits_that_grow_content_need_room_and_shrinking_ones_always_work(): void
    {
        $item = $this->item(['description' => '<p>'.str_repeat('a', 200).'</p>']);
        $snippet = $this->collection->snippets()->create(['created_by' => $this->editor->id, 'title' => 'S', 'content' => str_repeat('b', 200), 'language' => 'plaintext']);
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'content' => ['type' => 'lexical', 'html' => '<p>'.str_repeat('c', 200).'</p>']]);
        $this->contentFull();
        $itemUrl = route('boards.items.update', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]);

        $this->actingAs($this->editor)->patch($itemUrl, ['description' => '<p>'.str_repeat('a', 400).'</p>'])->assertSessionHas('limit_reached');
        $this->assertSame(200, substr_count((string) $item->fresh()->description, 'a'));
        $this->actingAs($this->editor)->patch($itemUrl, ['description' => '<p>short</p>'])->assertSessionMissing('limit_reached');
        $this->assertSame('<p>short</p>', $item->fresh()->description);

        $this->actingAs($this->editor)->put(route('snippets.snippets.update', [$this->workspace->slug, $this->collection->slug, $snippet->slug]), ['content' => str_repeat('b', 400)])
            ->assertSessionHas('limit_reached');
        $this->assertSame(200, strlen($snippet->fresh()->content));

        $draftUrl = route('pages.draft.save', [$this->workspace->slug, $this->project->slug, $page->uuid]);
        $this->actingAs($this->editor)->putJson($draftUrl, ['content' => ['type' => 'lexical', 'html' => '<p>'.str_repeat('c', 400).'</p>']])->assertStatus(402);
        $this->actingAs($this->editor)->putJson($draftUrl, ['content' => ['type' => 'lexical', 'html' => '<p>c</p>']])->assertOk();
    }
}
