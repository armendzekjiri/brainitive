<?php

namespace Tests\Feature\AppApi;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 task lists + to-dos (BRAI-397): the web's rules (TaskListPolicy; viewers read, editors
 * write), lists / projects the user can't open are 404, the shared create / reorder (TaskLists) and
 * the repeating-task roll-over (WorkCompletion), notes as Markdown, request_id retries.
 */
class TaskListsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private TaskList $list;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->list = TaskList::factory()->create(['project_id' => $this->project->id, 'name' => 'Errands', 'slug' => 'errands', 'position' => 1, 'created_by' => $this->editor->id]);
        $this->token = $this->appToken($this->editor);
    }

    private function task(array $attributes = []): TaskListItem
    {
        return TaskListItem::factory()->create($attributes + ['task_list_id' => $this->list->id, 'created_by' => $this->editor->id]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($user ? $this->appToken($user) : $this->token);
    }

    // ── lists ───────────────────────────────────────────────────────────────────────────────

    public function test_lists_the_task_lists_of_a_project_with_counts(): void
    {
        $second = TaskList::factory()->create(['project_id' => $this->project->id, 'name' => 'Shopping', 'position' => 2]);
        $this->task(['title' => 'Milk']);
        $this->task(['title' => 'Bread', 'status' => 'done']);
        TaskList::factory()->create(); // another project

        $response = $this->getJson("/api/v1/projects/{$this->project->id}/task-lists", $this->headers($this->viewer))
            ->assertOk()
            ->assertJsonPath('next_cursor', null)
            ->assertJsonCount(2, 'data');

        $this->assertSame([$this->list->id, $second->id], array_column($response->json('data'), 'id'));
        $this->assertSame([
            'id', 'uuid', 'slug', 'name', 'description', 'position', 'counts', 'project', 'workspace', 'url', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.counts', ['todo' => 1, 'done' => 1])
            ->assertJsonPath('data.0.project.id', $this->project->id)
            ->assertJsonPath('data.0.workspace.slug', $this->workspace->slug)
            ->assertJsonPath('data.0.url', route('task-lists.show', [$this->workspace->slug, $this->project->slug, 'errands']));

        // cursor pages
        $page = $this->getJson("/api/v1/projects/{$this->project->id}/task-lists?limit=1", $this->headers())->assertOk();
        $this->assertSame([$this->list->id], array_column($page->json('data'), 'id'));
        $next = $this->getJson("/api/v1/projects/{$this->project->id}/task-lists?limit=1&cursor=".urlencode($page->json('next_cursor')), $this->headers())->assertOk();
        $this->assertSame([$second->id], array_column($next->json('data'), 'id'));
    }

    public function test_projects_and_lists_the_user_cant_open_are_not_found(): void
    {
        $foreign = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $foreignList = TaskList::factory()->create(['project_id' => $foreign->id]);

        $this->getJson("/api/v1/projects/{$foreign->id}/task-lists", $this->headers())->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/task-lists/{$foreignList->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/task-lists/{$foreignList->id}/items", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/task-lists/{$foreignList->id}/items", [], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/projects/{$foreign->id}/task-lists", [], $this->headers())->assertNotFound();

        // a workspace member who isn't in the project
        $member = User::factory()->create();
        $this->workspace->addUser($member, 'member');
        $this->getJson("/api/v1/task-lists/{$this->list->id}", $this->headers($member))->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/task-lists", $this->headers($member))->assertNotFound();

        // malformed ids on writes: 404, not 405 / 500
        $this->postJson('/api/v1/task-lists/abc/items', ['title' => 'x'], $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/abc", ['title' => 'x'], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/task-lists/99999999999999999999/items/1', [], $this->headers())->assertNotFound();
    }

    public function test_creates_a_list_like_the_web_and_retries_with_request_id(): void
    {
        TaskList::factory()->create(['project_id' => $this->project->id, 'name' => 'Old', 'slug' => 'groceries'])->delete();

        $requestId = '0f8b2c7e-5d0c-4a59-9f7e-2c1f3c3c1a11';
        $created = $this->postJson("/api/v1/projects/{$this->project->id}/task-lists", ['name' => 'Groceries', 'description' => 'Weekly', 'request_id' => $requestId], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Groceries')
            ->assertJsonPath('data.slug', 'groceries-1') // the deleted list keeps its slug
            ->assertJsonPath('data.counts', ['todo' => 0, 'done' => 0])
            ->assertJsonPath('data.can.manage_items', true)
            ->assertJsonPath('data.watching', true);

        // the same request again: the same list, 200
        $this->postJson("/api/v1/projects/{$this->project->id}/task-lists", ['name' => 'Groceries', 'description' => 'Weekly', 'request_id' => $requestId], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, TaskList::where('name', 'Groceries')->count());

        // the same id for something else: 409
        $this->postJson("/api/v1/projects/{$this->project->id}/task-lists", ['name' => 'Other', 'request_id' => $requestId], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'conflict');

        $this->postJson("/api/v1/projects/{$this->project->id}/task-lists", ['name' => ''], $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/projects/{$this->project->id}/task-lists", ['name' => 'Nope'], $this->headers($this->viewer))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_shows_a_list_with_what_the_user_may_do(): void
    {
        $this->getJson("/api/v1/task-lists/{$this->list->id}", $this->headers($this->viewer))
            ->assertOk()
            ->assertJsonPath('data.id', $this->list->id)
            ->assertJsonPath('data.can', ['update' => false, 'delete' => false, 'manage_items' => false])
            ->assertJsonPath('data.watching', false);
    }

    // ── tasks ───────────────────────────────────────────────────────────────────────────────

    public function test_lists_tasks_in_order_with_a_status_filter(): void
    {
        $b = $this->task(['title' => 'B', 'position' => 2, 'assignee_id' => $this->editor->id, 'due_date' => '2026-11-20', 'due_time' => '09:30', 'recurrence' => 'weekly', 'reminder_minutes' => 60]);
        $a = $this->task(['title' => 'A', 'position' => 1, 'description' => ['type' => 'lexical', 'html' => '<p>Buy <strong>oat</strong> milk</p>']]);
        $done = $this->task(['title' => 'C', 'position' => 3, 'status' => 'done']);

        $response = $this->getJson("/api/v1/task-lists/{$this->list->id}/items", $this->headers($this->viewer))->assertOk();
        $this->assertSame([$a->id, $b->id, $done->id], array_column($response->json('data'), 'id'));
        $this->assertSame([
            'id', 'work_id', 'list_id', 'title', 'status', 'done', 'notes', 'assignee', 'due_date', 'due_time', 'start_date', 'start_time',
            'recurrence', 'reminder_minutes', 'position', 'url', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.notes', 'Buy **oat** milk')
            ->assertJsonPath('data.0.work_id', "task:{$a->id}")
            ->assertJsonPath('data.1.assignee', ['id' => $this->editor->id, 'name' => $this->editor->name, 'avatar_url' => $this->editor->avatar_display])
            ->assertJsonPath('data.1.due_date', '2026-11-20')
            ->assertJsonPath('data.1.due_time', '09:30')
            ->assertJsonPath('data.1.recurrence', 'weekly')
            ->assertJsonPath('data.1.reminder_minutes', 60);

        $this->assertSame([$a->id, $b->id], array_column($this->getJson("/api/v1/task-lists/{$this->list->id}/items?status=todo", $this->headers())->json('data'), 'id'));
        $this->assertSame([$done->id], array_column($this->getJson("/api/v1/task-lists/{$this->list->id}/items?status=done", $this->headers())->json('data'), 'id'));
        $this->getJson("/api/v1/task-lists/{$this->list->id}/items?status=nope", $this->headers())->assertUnprocessable();

        $first = $this->getJson("/api/v1/task-lists/{$this->list->id}/items?limit=2", $this->headers())->assertOk();
        $this->assertCount(2, $first->json('data'));
        $rest = $this->getJson("/api/v1/task-lists/{$this->list->id}/items?limit=2&cursor=".urlencode($first->json('next_cursor')), $this->headers());
        $this->assertSame([$done->id], array_column($rest->json('data'), 'id'));
    }

    public function test_adds_a_task_with_markdown_notes_and_the_web_rules(): void
    {
        $this->task(['position' => 4]);

        $response = $this->postJson("/api/v1/task-lists/{$this->list->id}/items", [
            'title' => 'Water plants',
            'notes' => "Use the **green** can\n\n- balcony\n- kitchen",
            'assignee_id' => $this->viewer->id,
            'due_date' => '2026-11-12',
            'due_time' => '18:00',
            'recurrence' => 'daily',
            'reminder_minutes' => 10,
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.title', 'Water plants')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.position', 5)
            ->assertJsonPath('data.assignee.id', $this->viewer->id)
            ->assertJsonPath('data.recurrence', 'daily');

        $task = TaskListItem::findOrFail($response->json('data.id'));
        $this->assertSame('lexical', $task->description['type']);
        $this->assertStringContainsString('<strong>green</strong>', $task->description['html']);
        $this->assertSame($this->editor->id, $task->created_by);

        // assignees must be able to open the project; reminders only of the web's choices
        $stranger = User::factory()->create();
        $this->workspace->addUser($stranger, 'member');
        $this->postJson("/api/v1/task-lists/{$this->list->id}/items", ['title' => 'x', 'assignee_id' => $stranger->id, 'reminder_minutes' => 5], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['assignee_id', 'reminder_minutes']);
        $this->postJson("/api/v1/task-lists/{$this->list->id}/items", ['title' => 'x'], $this->headers($this->viewer))->assertForbidden();

        // request_id: a retry creates nothing new
        $payload = ['title' => 'Once', 'request_id' => '5d7b8c9e-1f2a-4b3c-8d4e-5f6a7b8c9d0e'];
        $id = $this->postJson("/api/v1/task-lists/{$this->list->id}/items", $payload, $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/task-lists/{$this->list->id}/items", $payload, $this->headers())->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, TaskListItem::where('title', 'Once')->count());
    }

    public function test_updates_completes_and_rolls_a_repeating_task_on(): void
    {
        $task = $this->task(['title' => 'Bins', 'recurrence' => 'weekly', 'due_date' => '2026-11-10', 'description' => ['type' => 'lexical', 'html' => '<p>old</p>']]);

        // completing a repeating task keeps it open on its next date (WorkCompletion, as the web)
        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/{$task->id}", ['status' => 'done'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.due_date', '2026-11-17')
            ->assertJsonPath('data.title', 'Bins')
            ->assertJsonPath('data.notes', 'old');

        // only the sent fields change; null clears
        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/{$task->id}", ['title' => 'Bins out', 'notes' => null, 'recurrence' => null], $this->headers())
            ->assertOk()->assertJsonPath('data.title', 'Bins out')->assertJsonPath('data.notes', null)->assertJsonPath('data.recurrence', null)
            ->assertJsonPath('data.due_date', '2026-11-17');
        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/{$task->id}", ['status' => 'done'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.done', true);

        // a task of another list through this list: 404
        $other = TaskListItem::factory()->create(['task_list_id' => TaskList::factory()->create(['project_id' => $this->project->id])->id]);
        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/{$other->id}", ['title' => 'x'], $this->headers())->assertNotFound();

        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/{$task->id}", ['status' => 'maybe', 'title' => ''], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'title']);
        $this->patchJson("/api/v1/task-lists/{$this->list->id}/items/{$task->id}", ['title' => 'x'], $this->headers($this->viewer))->assertForbidden();
    }

    public function test_deletes_and_reorders_tasks(): void
    {
        $a = $this->task(['position' => 1]);
        $b = $this->task(['position' => 2]);
        $c = $this->task(['position' => 3]);
        $foreign = TaskListItem::factory()->create(['position' => 9]);

        $this->postJson("/api/v1/task-lists/{$this->list->id}/items/reorder", ['ids' => [$c->id, $a->id, $b->id, $foreign->id]], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $this->list->id);
        $this->assertSame([1, 2, 3], [$c->fresh()->position, $a->fresh()->position, $b->fresh()->position]);
        $this->assertSame(9, $foreign->fresh()->position);
        $this->postJson("/api/v1/task-lists/{$this->list->id}/items/reorder", ['ids' => []], $this->headers())->assertUnprocessable();

        $this->deleteJson("/api/v1/task-lists/{$this->list->id}/items/{$b->id}", [], $this->headers($this->viewer))->assertForbidden();
        $this->deleteJson("/api/v1/task-lists/{$this->list->id}/items/{$b->id}", [], $this->headers())->assertNoContent();
        $this->assertSoftDeleted($b);
        $this->deleteJson("/api/v1/task-lists/{$this->list->id}/items/{$b->id}", [], $this->headers())->assertNotFound();
    }

    // ── isolation + the shared code ─────────────────────────────────────────────────────────

    public function test_tokens_of_other_apis_and_unverified_users_are_refused(): void
    {
        $this->getJson("/api/v1/task-lists/{$this->list->id}")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/task-lists/{$this->list->id}", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->postJson("/api/v1/task-lists/{$this->list->id}/items", ['title' => 'x'], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->getJson("/api/v1/projects/{$this->project->id}/task-lists", $this->appHeaders($this->appToken($unverified)))->assertForbidden();

        // and an app token is no CLI token
        $this->getJson('/api/cli/lists', $this->headers())->assertUnauthorized();
    }

    public function test_the_cli_lists_only_lists_of_projects_the_user_can_open(): void
    {
        $hidden = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        TaskList::factory()->create(['project_id' => $hidden->id, 'name' => 'Secret']);

        $lists = $this->getJson('/api/cli/lists', $this->appHeaders($this->passportToken($this->editor, [])))->assertOk()->json('lists');
        $this->assertSame([$this->list->id], array_column($lists, 'id'));

        $created = $this->postJson("/api/cli/lists/{$this->list->slug}/items", ['title' => 'From the CLI'], $this->appHeaders($this->passportToken($this->editor, [])));
        $created->assertCreated()->assertJsonPath('item.title', 'From the CLI');
    }
}
