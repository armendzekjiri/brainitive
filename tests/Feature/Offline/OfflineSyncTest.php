<?php

namespace Tests\Feature\Offline;

use App\Models\Board;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\MyDayEntry;
use App\Models\OfflineMutation;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\MyDay\MyDayPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Offline sync (BRAI-82): one mutation per request, idempotent per client uuid, field-level
 * conflict detection, deleted entities, references to offline creates, same policies as the app.
 */
class OfflineSyncTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
    }

    /** @param array<string, mixed> $mutation */
    private function sync(User $user, string $type, array $mutation, ?string $id = null): TestResponse
    {
        return $this->actingAs($user)->postJson(route('offline.sync'), ['id' => $id ?? (string) Str::uuid(), 'type' => $type] + $mutation);
    }

    private function task(array $attributes = []): TaskListItem
    {
        return TaskListItem::factory()->create($attributes + ['task_list_id' => $this->list->id, 'created_by' => $this->editor->id, 'title' => 'Buy milk']);
    }

    // ── Shell + access ──────────────────────────────────────────────────────

    public function test_the_offline_shell_is_public(): void
    {
        $this->get(route('offline.shell'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Offline/Shell', false));
    }

    public function test_the_offline_shell_never_holds_account_data(): void
    {
        // The device caches this page: even when fetched signed in it must not carry the session.
        $response = $this->actingAs($this->editor)->get(route('offline.shell'))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Offline/Shell', false)->missing('auth')->missing('workspaces'));
        $this->assertStringNotContainsString($this->editor->email, $response->getContent());
        $this->assertStringNotContainsString($this->workspace->name, $response->getContent());
        $response->assertCookieMissing(config('session.cookie'));
    }

    public function test_guests_cannot_sync(): void
    {
        $this->postJson(route('offline.sync'), ['id' => (string) Str::uuid(), 'type' => 'capture.create', 'data' => ['text' => 'x']])
            ->assertUnauthorized();
        $this->assertSame(0, Capture::count());
    }

    public function test_unknown_types_and_bad_ids_are_rejected(): void
    {
        $this->actingAs($this->editor)->postJson(route('offline.sync'), ['id' => 'not-a-uuid', 'type' => 'capture.create', 'data' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->sync($this->editor, 'page.update', ['data' => []])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    // ── Idempotency ─────────────────────────────────────────────────────────

    public function test_a_retried_create_is_applied_once(): void
    {
        $id = (string) Str::uuid();
        $payload = ['data' => ['task_list_id' => $this->list->id, 'title' => 'Written on the train']];

        $first = $this->sync($this->editor, 'list_task.create', $payload, $id)->assertOk()->assertJsonPath('status', 'applied');
        $again = $this->sync($this->editor, 'list_task.create', $payload, $id)->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, $this->list->items()->where('title', 'Written on the train')->count());
        $this->assertSame($first->json('entity.id'), $again->json('entity.id'));
        $this->assertSame($this->editor->id, $this->list->items()->first()->created_by);
    }

    public function test_the_same_id_for_a_different_change_is_refused(): void
    {
        $id = (string) Str::uuid();
        $this->sync($this->editor, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'One']], $id)->assertOk();
        $this->sync($this->editor, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'Two']], $id)
            ->assertUnprocessable()->assertJsonValidationErrors('id');

        $this->assertSame(0, $this->list->items()->where('title', 'Two')->count());
    }

    public function test_client_ids_are_per_user(): void
    {
        $id = (string) Str::uuid();
        $this->sync($this->editor, 'capture.create', ['data' => ['text' => 'Mine']], $id)->assertOk();
        $this->sync($this->viewer, 'capture.create', ['data' => ['text' => 'Theirs']], $id)->assertOk()->assertJsonMissing(['replayed' => true]);

        $this->assertSame(1, Capture::where('user_id', $this->viewer->id)->count());
    }

    // ── Task-list tasks ─────────────────────────────────────────────────────

    public function test_a_rename_made_against_the_current_title_is_applied(): void
    {
        $task = $this->task();

        $this->sync($this->editor, 'list_task.update', [
            'target' => ['id' => $task->id],
            'base' => ['updated_at' => $task->updated_at->toIso8601String(), 'fields' => ['title' => 'Buy milk']],
            'data' => ['title' => 'Buy oat milk'],
        ])->assertOk()->assertJsonPath('entity.title', 'Buy oat milk');

        $this->assertSame('Buy oat milk', $task->fresh()->title);
    }

    public function test_a_rename_after_someone_else_renamed_is_a_conflict_and_never_overwrites(): void
    {
        $task = $this->task();
        $task->update(['title' => 'Buy bread']); // someone else, meanwhile

        $id = (string) Str::uuid();
        $mutation = [
            'target' => ['id' => $task->id],
            'base' => ['fields' => ['title' => 'Buy milk']],
            'data' => ['title' => 'Buy oat milk'],
        ];
        $this->sync($this->editor, 'list_task.update', $mutation, $id)
            ->assertConflict()
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('conflicts.title', ['base' => 'Buy milk', 'mine' => 'Buy oat milk', 'theirs' => 'Buy bread'])
            ->assertJsonPath('entity.title', 'Buy bread');
        $this->assertSame('Buy bread', $task->fresh()->title);

        // a retry gets the stored conflict, still without writing
        $this->sync($this->editor, 'list_task.update', $mutation, $id)->assertConflict()->assertJsonPath('replayed', true);
        $this->assertSame('Buy bread', $task->fresh()->title);

        // "keep mine" = a new change made against their value
        $this->sync($this->editor, 'list_task.update', [
            'target' => ['id' => $task->id],
            'base' => ['fields' => ['title' => 'Buy bread']],
            'data' => ['title' => 'Buy oat milk'],
        ])->assertOk();
        $this->assertSame('Buy oat milk', $task->fresh()->title);
    }

    public function test_fields_without_a_conflict_are_applied_next_to_a_conflicting_one(): void
    {
        $task = $this->task();
        $task->update(['title' => 'Buy bread']);

        $this->sync($this->editor, 'list_task.update', [
            'target' => ['id' => $task->id],
            'base' => ['fields' => ['title' => 'Buy milk', 'status' => 'todo']],
            'data' => ['title' => 'Buy oat milk', 'status' => 'done'],
        ])->assertConflict()->assertJsonPath('applied', ['status'])->assertJsonMissingPath('conflicts.status');

        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('Buy bread', $task->fresh()->title);
    }

    public function test_the_same_change_made_twice_is_no_conflict(): void
    {
        $task = $this->task(['status' => 'done']);

        $this->sync($this->editor, 'list_task.update', [
            'target' => ['id' => $task->id],
            'base' => ['fields' => ['status' => 'todo']],
            'data' => ['status' => 'done'],
        ])->assertOk()->assertJsonPath('entity.status', 'done');
    }

    public function test_completing_a_repeating_task_offline_moves_it_to_the_next_date(): void
    {
        $task = $this->task(['recurrence' => 'weekly', 'due_date' => '2026-11-02']);

        $this->sync($this->editor, 'list_task.update', [
            'target' => ['id' => $task->id], 'base' => ['fields' => ['status' => 'todo']], 'data' => ['status' => 'done'],
        ])->assertOk();

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame('2026-11-09', $task->fresh()->due_date->format('Y-m-d'));
    }

    public function test_every_changed_field_needs_its_base_value(): void
    {
        $task = $this->task();

        $this->sync($this->editor, 'list_task.update', ['target' => ['id' => $task->id], 'data' => ['title' => 'Blind write']])
            ->assertUnprocessable()->assertJsonValidationErrors('base.fields');
        $this->assertSame('Buy milk', $task->fresh()->title);
    }

    public function test_a_task_deleted_meanwhile_answers_gone_and_stores_nothing(): void
    {
        $task = $this->task();
        $task->delete();

        $this->sync($this->editor, 'list_task.update', [
            'target' => ['id' => $task->id], 'base' => ['fields' => ['title' => 'Buy milk']], 'data' => ['title' => 'Oat milk'],
        ])->assertStatus(410)->assertJsonPath('status', 'deleted')->assertJsonPath('entity_type', 'list_task');

        $this->assertSame(0, OfflineMutation::count());
    }

    public function test_task_policies_are_the_list_page_policies(): void
    {
        $task = $this->task();
        $change = ['target' => ['id' => $task->id], 'base' => ['fields' => ['title' => 'Buy milk']], 'data' => ['title' => 'Hacked']];

        $this->sync($this->viewer, 'list_task.update', $change)->assertForbidden();
        $this->sync($this->viewer, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'Nope']])->assertForbidden();
        // outsiders can't even learn that the task exists
        $this->sync($this->outsider, 'list_task.update', $change)->assertStatus(410);
        $this->sync($this->outsider, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'Nope']])->assertStatus(410);

        $this->assertSame('Buy milk', $task->fresh()->title);
        $this->assertSame(1, $this->list->items()->count());
        $this->assertSame(0, OfflineMutation::count());
    }

    public function test_reorder_uses_ids_and_offline_refs_and_skips_tasks_that_are_gone(): void
    {
        $a = $this->task(['title' => 'A', 'position' => 0]);
        $b = $this->task(['title' => 'B', 'position' => 1]);
        $gone = $this->task(['title' => 'Gone', 'position' => 2]);
        $gone->delete();
        $ref = (string) Str::uuid();
        $this->sync($this->editor, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'C']], $ref)->assertOk();
        $c = $this->list->items()->where('title', 'C')->firstOrFail();

        $this->sync($this->editor, 'list_task.reorder', ['data' => [
            'task_list_id' => $this->list->id,
            'items' => [['ref' => $ref], ['id' => $gone->id], ['id' => $b->id], ['id' => $a->id]],
        ]])->assertOk()->assertJsonPath('entity.order', [$c->id, $b->id, $a->id]);

        $this->assertSame([0, 1, 2], [$c->fresh()->position, $b->fresh()->position, $a->fresh()->position]);
    }

    // ── References to offline creates + ordering ───────────────────────────

    public function test_a_change_to_something_created_offline_waits_for_the_create(): void
    {
        $ref = (string) Str::uuid();
        $rename = ['target' => ['ref' => $ref], 'base' => ['fields' => ['title' => 'Draft']], 'data' => ['title' => 'Final']];
        $renameId = (string) Str::uuid();

        // out of order: the rename arrives first → 424, nothing stored, retried later
        $this->sync($this->editor, 'list_task.update', $rename, $renameId)->assertStatus(424)->assertJsonPath('status', 'waiting');
        $this->assertSame(0, OfflineMutation::count());

        $this->sync($this->editor, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'Draft']], $ref)->assertOk();
        $this->sync($this->editor, 'list_task.update', $rename, $renameId)->assertOk()->assertJsonPath('entity.title', 'Final');

        $this->assertSame(['Final'], $this->list->items()->pluck('title')->all());
    }

    public function test_refs_only_resolve_the_users_own_creates(): void
    {
        $ref = (string) Str::uuid();
        $this->sync($this->editor, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'Mine']], $ref)->assertOk();

        $other = $this->projectMember('editor');
        $this->sync($other, 'list_task.update', ['target' => ['ref' => $ref], 'base' => ['fields' => ['title' => 'Mine']], 'data' => ['title' => 'Stolen']])
            ->assertStatus(424);
        $this->assertSame('Mine', $this->list->items()->first()->title);
    }

    // ── Issues ──────────────────────────────────────────────────────────────

    public function test_issue_field_edits_are_applied_with_history(): void
    {
        $item = $this->item(['title' => 'Login bug', 'column_id' => $this->column('to-do')]);
        $priority = ItemPriority::forProject($this->project)->firstOrFail();

        $this->sync($this->editor, 'issue.update', [
            'target' => ['id' => $item->id],
            'base' => ['updated_at' => $item->updated_at->toIso8601String(), 'fields' => [
                'title' => 'Login bug', 'column_id' => $item->column_id, 'priority_id' => $item->priority_id, 'assigned_to' => null, 'due_date' => null,
            ]],
            'data' => [
                'title' => 'Login bug on Safari', 'column_id' => $this->column('done'), 'priority_id' => $priority->id,
                'assigned_to' => $this->editor->id, 'due_date' => '2026-11-05',
            ],
        ])->assertOk()
            ->assertJsonPath('entity.title', 'Login bug on Safari')
            ->assertJsonPath('entity.due_date', '2026-11-05');

        $item->refresh();
        $this->assertSame($this->column('done'), $item->column_id);
        $this->assertNotNull($item->completed_at);
        $this->assertSame($this->editor->id, $item->assigned_to);
        $this->assertSame($priority->id, $item->priority_id);
        $this->assertTrue($item->activities()->exists(), 'the edit is in the issue history');
    }

    public function test_an_issue_moved_meanwhile_is_a_status_conflict(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $item->update(['column_id' => $this->column('in-progress')]); // someone else

        $this->sync($this->editor, 'issue.update', [
            'target' => ['id' => $item->id],
            'base' => ['fields' => ['column_id' => $this->column('to-do')]],
            'data' => ['column_id' => $this->column('done')],
        ])->assertConflict()
            ->assertJsonPath('conflicts.column_id.theirs', $this->column('in-progress'))
            ->assertJsonPath('conflicts.column_id.mine', $this->column('done'));

        $this->assertSame($this->column('in-progress'), $item->fresh()->column_id);
    }

    public function test_due_dates_compare_as_dates(): void
    {
        $item = $this->item(['due_date' => '2026-11-01']);

        $this->sync($this->editor, 'issue.update', [
            'target' => ['id' => $item->id],
            'base' => ['fields' => ['due_date' => '2026-11-01T00:00:00.000000Z']],
            'data' => ['due_date' => '2026-11-03'],
        ])->assertOk();

        $this->assertSame('2026-11-03', $item->fresh()->due_date->format('Y-m-d'));
    }

    public function test_issue_edits_use_the_board_policies_and_validation(): void
    {
        $item = $this->item(['title' => 'Locked']);
        $change = ['target' => ['id' => $item->id], 'base' => ['fields' => ['title' => 'Locked']], 'data' => ['title' => 'Changed']];

        $this->sync($this->viewer, 'issue.update', $change)->assertForbidden();
        $this->sync($this->outsider, 'issue.update', $change)->assertStatus(410);
        $this->sync($this->editor, 'issue.update', [
            'target' => ['id' => $item->id], 'base' => ['fields' => ['assigned_to' => null]], 'data' => ['assigned_to' => $this->outsider->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $otherBoardColumn = Board::factory()->create(['project_id' => $this->project->id])->columns()->value('id');
        $this->sync($this->editor, 'issue.update', [
            'target' => ['id' => $item->id], 'base' => ['fields' => ['column_id' => $item->column_id]], 'data' => ['column_id' => $otherBoardColumn],
        ])->assertUnprocessable()->assertJsonValidationErrors('column_id');

        $this->assertSame('Locked', $item->fresh()->title);
    }

    public function test_an_issue_deleted_meanwhile_answers_gone_and_can_be_recreated(): void
    {
        $item = $this->item(['title' => 'Will vanish']);
        $item->delete();

        $this->sync($this->editor, 'issue.update', [
            'target' => ['id' => $item->id], 'base' => ['fields' => ['title' => 'Will vanish']], 'data' => ['title' => 'Edited offline'],
        ])->assertStatus(410)->assertJsonPath('entity_type', 'issue');

        $this->sync($this->editor, 'issue.create', ['data' => ['board_id' => $this->board->id, 'title' => 'Edited offline']])
            ->assertOk()->assertJsonPath('entity.title', 'Edited offline');
        $this->assertSame(1, Item::where('title', 'Edited offline')->where('board_id', $this->board->id)->count());

        $this->sync($this->viewer, 'issue.create', ['data' => ['board_id' => $this->board->id, 'title' => 'Nope']])->assertForbidden();
    }

    public function test_offline_comments_are_escaped_plain_text_and_posted_once(): void
    {
        $item = $this->item();
        $id = (string) Str::uuid();
        $comment = ['target' => ['id' => $item->id], 'data' => ['body' => "Line one\n<script>alert(1)</script>"]];

        $this->sync($this->editor, 'issue.comment', $comment, $id)->assertOk();
        $this->sync($this->editor, 'issue.comment', $comment, $id)->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, Comment::where('item_id', $item->id)->count());
        $body = Comment::where('item_id', $item->id)->value('body');
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringContainsString('<br>', $body);

        $this->sync($this->viewer, 'issue.comment', ['target' => ['id' => $item->id], 'data' => ['body' => 'Hi']])->assertForbidden();
    }

    // ── Captures + My Day ───────────────────────────────────────────────────

    public function test_offline_captures_land_in_the_users_own_inbox(): void
    {
        $this->sync($this->viewer, 'capture.create', ['data' => ['text' => 'Idea from the plane', 'url' => 'https://example.com']])
            ->assertOk()->assertJsonPath('entity.text', 'Idea from the plane');

        $this->assertSame(1, Capture::where('user_id', $this->viewer->id)->where('text', 'Idea from the plane')->count());
        $this->sync($this->viewer, 'capture.create', ['data' => ['text' => '']])->assertUnprocessable();
    }

    public function test_my_day_add_and_remove_offline(): void
    {
        $item = $this->item();
        $today = MyDayPlan::today($this->viewer);

        $this->sync($this->viewer, 'my_day.add', ['data' => ['work_type' => 'board', 'work_id' => $item->id]])->assertOk();
        $this->assertTrue(MyDayEntry::where(['user_id' => $this->viewer->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => $today])->exists());

        $this->sync($this->viewer, 'my_day.remove', ['data' => ['work_type' => 'board', 'work_id' => $item->id]])->assertOk();
        $this->assertFalse(MyDayEntry::where('user_id', $this->viewer->id)->exists());
        // removing again is fine
        $this->sync($this->viewer, 'my_day.remove', ['data' => ['work_type' => 'board', 'work_id' => $item->id]])->assertOk();

        // only work you can open
        $this->sync($this->outsider, 'my_day.add', ['data' => ['work_type' => 'board', 'work_id' => $item->id]])->assertStatus(410);
        $this->assertFalse(MyDayEntry::where('user_id', $this->outsider->id)->exists());
    }

    public function test_a_task_created_offline_can_go_on_my_day_by_ref(): void
    {
        $ref = (string) Str::uuid();
        $this->sync($this->editor, 'list_task.create', ['data' => ['task_list_id' => $this->list->id, 'title' => 'Plan it']], $ref)->assertOk();

        $this->sync($this->editor, 'my_day.add', ['data' => ['work_type' => 'list', 'work_ref' => $ref]])->assertOk();

        $task = $this->list->items()->where('title', 'Plan it')->firstOrFail();
        $this->assertTrue(MyDayEntry::where(['user_id' => $this->editor->id, 'work_type' => 'list', 'work_id' => $task->id])->exists());
    }

    public function test_old_outcomes_are_pruned_after_30_days(): void
    {
        $this->sync($this->editor, 'capture.create', ['data' => ['text' => 'Old']])->assertOk();
        OfflineMutation::query()->update(['created_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => [OfflineMutation::class]])->assertSuccessful();

        $this->assertSame(0, OfflineMutation::count());
    }
}
