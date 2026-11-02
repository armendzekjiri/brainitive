<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ItemStatusChangedNotification;
use App\Support\MyDay\MyDayPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\ClientRepository;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 My Work + My Day (BRAI-396): the assigned work of every project the user can open
 * (shared query with ext/v1/my-work), due filters by the user's local date, cursor pages, done /
 * undone through the web's logic, and My Day (add, remove, done, carry over).
 */
class MyWorkTest extends TestCase
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
        $this->list = TaskList::factory()->create(['project_id' => $this->project->id, 'name' => 'Errands']);
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'UTC'));
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(array $attributes = []): TaskListItem
    {
        return TaskListItem::factory()->create($attributes + ['task_list_id' => $this->list->id, 'created_by' => $this->editor->id]);
    }

    private function work(string $query = '', ?string $token = null)
    {
        return $this->getJson('/api/v1/me/work'.($query !== '' ? '?'.$query : ''), $this->appHeaders($token ?? $this->token));
    }

    /** @return list<string> */
    private function ids(string $query = ''): array
    {
        return array_column($this->work($query)->assertOk()->json('data'), 'id');
    }

    // ── My Work ─────────────────────────────────────────────────────────────────────────────

    public function test_lists_assigned_open_issues_and_tasks_soonest_due_first(): void
    {
        $late = $this->item(['title' => 'Late', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-08', 'due_time' => '09:30']);
        $task = $this->task(['title' => 'Buy milk', 'assignee_id' => $this->editor->id, 'due_date' => '2026-11-10']);
        $undated = $this->item(['title' => 'Someday', 'assigned_to' => $this->editor->id]);
        $this->item(['title' => 'Not mine', 'assigned_to' => $this->viewer->id, 'due_date' => '2026-11-09']);
        $this->item(['title' => 'Done already', 'assigned_to' => $this->editor->id, 'column_id' => $this->column('done')]);
        $this->task(['title' => 'Done task', 'assignee_id' => $this->editor->id, 'status' => 'done']);

        // another workspace's project the editor isn't in
        $hidden = Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id])->id]);
        Item::factory()->create(['board_id' => $hidden->id, 'assigned_to' => $this->editor->id]);

        $response = $this->work()->assertOk()->assertJsonPath('next_cursor', null);
        $this->assertSame(["issue:{$late->id}", "task:{$task->id}", "issue:{$undated->id}"], array_column($response->json('data'), 'id'));

        $first = $response->json('data.0');
        $this->assertSame([
            'id', 'type', 'key', 'title', 'done', 'status', 'priority', 'due_date', 'due_time', 'project', 'workspace', 'source', 'url', 'updated_at',
        ], array_keys($first));
        $this->assertSame('issue', $first['type']);
        $this->assertSame($late->key, $first['key']);
        $this->assertFalse($first['done']);
        $this->assertSame('2026-11-08', $first['due_date']);
        $this->assertSame('09:30', $first['due_time']);
        $this->assertSame(['id' => $this->project->id, 'name' => $this->project->name, 'key' => 'BRAI', 'color' => $this->project->color], $first['project']);
        $this->assertSame($this->workspace->slug, $first['workspace']['slug']);
        $this->assertSame(['type' => 'board', 'id' => $this->board->id, 'name' => $this->board->name], $first['source']);
        $this->assertSame(route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $late->id]), $first['url']);

        $taskRow = $response->json('data.1');
        $this->assertSame(['task', 'BRAI-L'.$task->id, 'To do', null], [$taskRow['type'], $taskRow['key'], $taskRow['status'], $taskRow['priority']]);
        $this->assertSame(['type' => 'list', 'id' => $this->list->id, 'name' => 'Errands'], $taskRow['source']);
    }

    public function test_due_filters_use_the_users_local_date(): void
    {
        // 2026-11-10 12:00 UTC is already 2026-11-11 in Auckland
        $this->editor->update(['timezone' => 'Pacific/Auckland']);
        $overdue = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-10']);
        $today = $this->task(['assignee_id' => $this->editor->id, 'due_date' => '2026-11-11']);
        $upcoming = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-20']);
        $this->item(['assigned_to' => $this->editor->id]); // no due date: only without a filter

        $this->assertSame(["issue:{$overdue->id}"], $this->ids('due=overdue'));
        $this->assertSame(["task:{$today->id}"], $this->ids('due=today'));
        $this->assertSame(["issue:{$upcoming->id}"], $this->ids('due=upcoming'));
        $this->assertSame(["issue:{$overdue->id}", "task:{$today->id}"], $this->ids('due=overdue,today'));
        $this->assertCount(4, $this->ids());

        $this->work('due=later')->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('due');
        $this->work('status=closed')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_pages_with_a_cursor_across_both_kinds(): void
    {
        $expected = [];
        foreach (range(1, 3) as $day) {
            $expected[] = 'issue:'.$this->item(['assigned_to' => $this->editor->id, 'due_date' => "2026-11-0{$day}"])->id;
            $expected[] = 'task:'.$this->task(['assignee_id' => $this->editor->id, 'due_date' => "2026-11-0{$day}"])->id;
        }
        $expected[] = 'task:'.$this->task(['assignee_id' => $this->editor->id])->id;

        $seen = [];
        $cursor = null;
        do {
            $page = $this->work('limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $cursor = $page->json('next_cursor');
        } while ($cursor !== null);

        $this->assertSame($expected, $seen);
    }

    public function test_updated_since_with_status_all_reports_work_completed_since(): void
    {
        $old = $this->item(['assigned_to' => $this->editor->id]);
        $finished = $this->task(['assignee_id' => $this->editor->id]);
        Item::whereKey($old->id)->update(['updated_at' => now()->subDay()]);
        TaskListItem::whereKey($finished->id)->update(['updated_at' => now()->subDay()]);

        Carbon::setTestNow(now()->addMinutes(10));
        $since = now()->subMinute()->toIso8601ZuluString();
        $finished->fresh()->update(['status' => 'done']);

        $this->assertSame([], $this->ids('updated_since='.urlencode($since)));
        $rows = $this->work('status=all&updated_since='.urlencode($since))->assertOk()->json('data');
        $this->assertSame(["task:{$finished->id}"], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['done']);
        $this->assertSame(["task:{$finished->id}"], $this->ids('status=done'));
    }

    public function test_marks_an_issue_done_and_open_again_like_the_web_circle(): void
    {
        Notification::fake();
        $watcher = $this->projectMember('editor');
        $item = $this->item(['assigned_to' => $this->editor->id]);
        $item->addWatcher($watcher);

        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => true], $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.done', true)
            ->assertJsonPath('data.status', 'Done');
        $this->assertSame($this->column('done'), $item->fresh()->column_id);
        $this->assertNotNull($item->fresh()->completed_at);
        Notification::assertSentTo($watcher, ItemStatusChangedNotification::class);

        // done again changes nothing; reopen → the board's first open column
        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => true], $this->appHeaders($this->token))->assertOk();
        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => false], $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.done', false);
        $this->assertSame($this->column('to-do'), $item->fresh()->column_id);
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_completing_a_repeating_task_moves_it_to_its_next_date(): void
    {
        $once = $this->task(['assignee_id' => $this->editor->id]);
        $weekly = $this->task(['assignee_id' => $this->editor->id, 'due_date' => '2026-11-10', 'recurrence' => 'weekly']);

        $this->patchJson("/api/v1/me/work/task:{$once->id}", ['done' => true], $this->appHeaders($this->token))->assertOk()->assertJsonPath('data.done', true);
        $this->assertSame('done', $once->fresh()->status);

        $this->patchJson("/api/v1/me/work/task:{$weekly->id}", ['done' => true], $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.done', false)
            ->assertJsonPath('data.due_date', '2026-11-17');

        $this->patchJson("/api/v1/me/work/task:{$once->id}", ['done' => false], $this->appHeaders($this->token))->assertOk();
        $this->assertSame('todo', $once->fresh()->status);
    }

    public function test_done_needs_edit_rights_and_visible_work(): void
    {
        $item = $this->item(['assigned_to' => $this->viewer->id]);
        $viewerToken = $this->appToken($this->viewer);

        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => true], $this->appHeaders($viewerToken))->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => true], $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->patchJson('/api/v1/me/work/issue:999999', ['done' => true], $this->appHeaders($this->token))->assertNotFound();
        $this->patchJson('/api/v1/me/work/nonsense', ['done' => true], $this->appHeaders($this->token))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->patchJson("/api/v1/me/work/issue:{$item->id}", [], $this->appHeaders($this->token))->assertUnprocessable()->assertJsonValidationErrors('done');
        $this->assertNull($item->fresh()->completed_at);

        // a board without a done column
        $this->board->columns()->update(['is_done_column' => false]);
        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => true], $this->appHeaders($this->token))->assertUnprocessable()->assertJsonValidationErrors('done');
    }

    // ── My Day ──────────────────────────────────────────────────────────────────────────────

    public function test_my_day_add_done_remove_with_the_web_rules(): void
    {
        $item = $this->item(['title' => 'Plan me']);
        $task = $this->task(['title' => 'And me']);
        $headers = $this->appHeaders($this->token);

        $this->getJson('/api/v1/me/my-day', $headers)->assertOk()
            ->assertExactJson(['data' => ['date' => '2026-11-10', 'items' => [], 'carry_over_count' => 0]]);

        $this->postJson('/api/v1/me/my-day/items', ['id' => "task:{$task->id}"], $headers)->assertOk();
        $day = $this->postJson('/api/v1/me/my-day/items', ['id' => "issue:{$item->id}"], $headers)->assertOk();
        $this->postJson('/api/v1/me/my-day/items', ['id' => "issue:{$item->id}"], $headers)->assertOk(); // twice: no-op
        $this->assertSame(["task:{$task->id}", "issue:{$item->id}"], array_column($day->json('data.items'), 'id'));
        $this->assertSame(2, MyDayEntry::count());
        $this->assertSame(['list', 'board'], MyDayEntry::orderBy('position')->pluck('work_type')->all());

        // the web's My Day reads the same plan
        $this->assertSame(["list:{$task->id}", "board:{$item->id}"], MyDayPlan::forDate($this->editor, '2026-11-10')
            ->map(fn (array $row) => $row['entry']->work_type.':'.$row['entry']->work_id)->all());

        $this->patchJson("/api/v1/me/my-day/items/issue:{$item->id}", ['done' => true], $headers)->assertOk()
            ->assertJsonPath('data.items.1.done', true);
        $this->assertNotNull($item->fresh()->completed_at);

        $this->deleteJson("/api/v1/me/my-day/items/task:{$task->id}", [], $headers)->assertOk()
            ->assertJsonPath('data.items.0.id', "issue:{$item->id}")
            ->assertJsonCount(1, 'data.items');
        $this->assertSame('todo', $task->fresh()->status); // the task itself is untouched
        $this->deleteJson('/api/v1/me/my-day/items/bad', [], $headers)->assertNotFound();
    }

    public function test_my_day_only_takes_work_the_user_can_open(): void
    {
        $hidden = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id])->id])->id]);

        $this->postJson('/api/v1/me/my-day/items', ['id' => "issue:{$hidden->id}"], $this->appHeaders($this->token))
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson('/api/v1/me/my-day/items', ['id' => 'issue:abc'], $this->appHeaders($this->token))->assertUnprocessable();
        $this->assertSame(0, MyDayEntry::count());

        // an entry whose work the user lost access to disappears from the list
        $item = $this->item();
        MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => '2026-11-10', 'position' => 1]);
        $this->project->users()->detach($this->editor->id);
        $this->getJson('/api/v1/me/my-day', $this->appHeaders($this->token))->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_carry_over_brings_unfinished_work_of_earlier_days(): void
    {
        $open = $this->item();
        $done = $this->item(['column_id' => $this->column('done')]);
        foreach ([$open, $done] as $i => $work) {
            MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $work->id, 'plan_date' => '2026-11-09', 'position' => $i]);
        }

        $this->getJson('/api/v1/me/my-day', $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.carry_over_count', 1)->assertJsonCount(0, 'data.items');

        $this->postJson('/api/v1/me/my-day/carry-over', [], $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.carry_over_count', 0)
            ->assertJsonPath('data.items.0.id', "issue:{$open->id}")
            ->assertJsonCount(1, 'data.items');
    }

    // ── isolation ───────────────────────────────────────────────────────────────────────────

    public function test_tokens_of_other_apis_and_unverified_users_are_refused(): void
    {
        $this->getJson('/api/v1/me/work')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/me/work', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->getJson('/api/v1/me/my-day', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->getJson('/api/v1/me/work', $this->appHeaders($this->appToken($unverified)))->assertForbidden();
    }

    public function test_the_extension_my_work_still_uses_the_same_query(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id]);
        $this->item(['assigned_to' => $this->editor->id, 'column_id' => $this->column('done')]);
        $task = $this->task(['assignee_id' => $this->editor->id]);

        $this->usePassportKeys();
        app(ClientRepository::class)->createPersonalAccessGrantClient('ext', 'users');
        $token = $this->editor->createToken('ext', ['capture'])->accessToken;

        $rows = $this->getJson('/api/ext/v1/my-work', $this->appHeaders($token))->assertOk()->json('items');
        $this->assertEqualsCanonicalizing(["issue:{$item->id}", "task:{$task->id}"], array_map(fn ($r) => $r['type'].':'.$r['id'], $rows));
    }
}
