<?php

namespace Tests\Feature\Extension;

use App\Jobs\FetchLinkMetadataJob;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

class ExtensionApiTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesPassportKeys;

    protected TaskList $taskList;

    protected Bookmark $bookmark;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();

        $this->usePassportKeys();

        $this->taskList = TaskList::create([
            'project_id' => $this->project->id,
            'name' => 'Inbox',
            'slug' => 'inbox',
            'created_by' => $this->editor->id,
        ]);
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $this->bookmark = Bookmark::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Reading',
            'visibility' => 'workspace',
            'created_by' => $this->editor->id,
        ]);
    }

    private function asExtension(User $user): void
    {
        Passport::actingAs($user, ['capture']);
    }

    public function test_extension_api_requires_the_capture_scope(): void
    {
        $this->getJson(route('ext.me'))->assertUnauthorized();

        Passport::actingAs($this->editor, ['mcp:use']);
        $this->getJson(route('ext.me'))->assertForbidden();

        $this->asExtension($this->editor);
        $this->getJson(route('ext.me'))
            ->assertOk()
            ->assertJsonPath('user.email', $this->editor->email);
    }

    public function test_capture_tokens_are_rejected_by_mcp_and_the_cli_api(): void
    {
        $this->asExtension($this->editor);

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
        $this->getJson('/api/cli/me')->assertForbidden();

        Passport::actingAs($this->editor);
        $this->getJson('/api/cli/me')->assertOk();
    }

    public function test_destinations_only_list_targets_the_user_can_write_to(): void
    {
        $this->asExtension($this->editor);

        $workspace = $this->getJson(route('ext.destinations'))->assertOk()->json('workspaces.0');

        $this->assertSame($this->workspace->id, $workspace['id']);
        $this->assertSame([$this->board->id], array_column($workspace['projects'][0]['boards'], 'id'));
        $this->assertCount(3, $workspace['projects'][0]['boards'][0]['columns']);
        $this->assertSame([$this->taskList->id], array_column($workspace['projects'][0]['task_lists'], 'id'));
        $this->assertTrue($workspace['projects'][0]['can_create_pages']);
        $this->assertSame([$this->bookmark->id], array_column($workspace['bookmarks'], 'id'));

        $this->asExtension($this->viewer);
        $this->assertSame([], $this->getJson(route('ext.destinations'))->json('workspaces'));

        $this->asExtension($this->outsider);
        $this->assertSame([], $this->getJson(route('ext.destinations'))->json('workspaces'));
    }

    public function test_editor_creates_an_issue_with_a_screenshot(): void
    {
        Storage::fake('public');
        $this->asExtension($this->editor);

        $response = $this->post(route('ext.boards.items.store', $this->board->id), [
            'title' => 'Checkout button overlaps footer',
            'description' => "From https://example.com/cart\n\n> The button is cut off",
            'column_id' => $this->column('in-progress'),
            'assign_to_me' => '1',
            'screenshot' => UploadedFile::fake()->image('shot.png', 800, 600),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();

        $item = Item::findOrFail($response->json('item_id'));
        $this->assertSame($item->key, $response->json('item_key'));
        $this->assertStringStartsWith('BRAI-', $item->key);
        $this->assertSame($this->column('in-progress'), $item->column_id);
        $this->assertSame($this->editor->id, $item->assigned_to);
        $this->assertStringContainsString('<blockquote>', $item->description);
        $this->assertCount(1, $item->attachments);
        Storage::disk('public')->assertExists($item->attachments->first()->path);
        $this->assertStringContainsString('#item-'.$item->id, $response->json('location.url'));
    }

    public function test_issue_column_must_belong_to_the_board(): void
    {
        $other = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->asExtension($this->editor);

        $this->postJson(route('ext.boards.items.store', $this->board->id), [
            'title' => 'Wrong column',
            'column_id' => $other->columns()->first()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('column_id');
    }

    public function test_viewers_and_outsiders_cannot_capture(): void
    {
        foreach ([$this->viewer, $this->outsider] as $user) {
            $this->asExtension($user);

            $this->postJson(route('ext.boards.items.store', $this->board->id), ['title' => 'Nope'])->assertForbidden();
            $this->postJson(route('ext.task-lists.items.store', $this->taskList->id), ['title' => 'Nope'])->assertForbidden();
            $this->postJson(route('ext.bookmarks.items.store', $this->bookmark->id), ['type' => 'link', 'url' => 'https://example.com'])->assertForbidden();
        }

        $this->asExtension($this->outsider);
        $this->postJson(route('ext.projects.pages.store', $this->project->id), ['title' => 'Nope'])->assertNotFound();

        $this->asExtension($this->viewer);
        $this->postJson(route('ext.projects.pages.store', $this->project->id), ['title' => 'Nope'])->assertForbidden();

        $this->assertSame(0, Item::count());
    }

    public function test_save_link_to_a_bookmark_group(): void
    {
        Queue::fake();
        $this->asExtension($this->editor);

        $this->postJson(route('ext.bookmarks.items.store', $this->bookmark->id), [
            'type' => 'link',
            'title' => 'Laravel docs',
            'url' => 'https://laravel.com/docs',
        ])->assertCreated();

        $this->assertDatabaseHas('bookmark_items', ['bookmark_id' => $this->bookmark->id, 'url' => 'https://laravel.com/docs']);
        Queue::assertPushed(FetchLinkMetadataJob::class);

        $this->postJson(route('ext.bookmarks.items.store', $this->bookmark->id), ['type' => 'link', 'url' => 'javascript:alert(1)'])
            ->assertJsonValidationErrors('url');
    }

    public function test_clip_to_a_doc_creates_a_private_draft(): void
    {
        $this->asExtension($this->editor);

        $response = $this->postJson(route('ext.projects.pages.store', $this->project->id), [
            'title' => 'How queues work',
            'markdown' => "# Queues\n\nSource: https://example.com\n\nSome **bold** text.",
        ])->assertCreated();

        $page = Page::where('uuid', $response->json('page_uuid'))->firstOrFail();
        $this->assertSame($this->editor->id, $page->created_by);
        $this->assertStringContainsString('<strong>bold</strong>', $page->content['html']);
        $this->assertNotSame('published', $page->status);
    }

    public function test_quick_task_with_notes(): void
    {
        $this->asExtension($this->editor);

        $response = $this->postJson(route('ext.task-lists.items.store', $this->taskList->id), [
            'title' => 'Read the RFC',
            'notes' => 'https://example.com/rfc',
            'due_date' => '2026-11-01',
            'assign_to_me' => true,
        ])->assertCreated();

        $task = $this->taskList->items()->findOrFail($response->json('task_id'));
        $this->assertSame('lexical', $task->description['type']);
        $this->assertSame($this->editor->id, $task->assignee_id);
        $this->assertSame('2026-11-01', $task->due_date->toDateString());
    }

    public function test_my_work_lists_open_assigned_issues_only(): void
    {
        $mine = $this->item(['title' => 'Mine', 'assigned_to' => $this->editor->id, 'column_id' => $this->column('to-do')]);
        $this->item(['title' => 'Done', 'assigned_to' => $this->editor->id, 'column_id' => $this->column('done')]);
        $this->item(['title' => 'Someone else', 'assigned_to' => $this->viewer->id, 'column_id' => $this->column('to-do')]);

        $this->asExtension($this->editor);

        $items = $this->getJson(route('ext.my-work'))->assertOk()->json('items');

        $this->assertSame(['Mine'], array_column($items, 'title'));
        $this->assertSame($mine->id, $items[0]['id']);
    }

    public function test_search_is_scoped_to_the_users_workspaces(): void
    {
        $this->item(['title' => 'Payment gateway bug']);

        $this->asExtension($this->editor);
        $this->assertCount(1, $this->getJson(route('ext.search', ['q' => 'gateway']))->assertOk()->json('items'));

        $this->asExtension($this->outsider);
        $this->assertCount(0, $this->getJson(route('ext.search', ['q' => 'gateway']))->json('items'));
    }
}
