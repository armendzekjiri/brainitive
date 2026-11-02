<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Capture;
use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\Project;
use App\Models\SyncTombstone;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MyDay\MyDayPlan;
use App\Support\Sync\ChangeFeed;
use App\Support\Sync\SyncCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 offline sync (BRAI-425): the change feed (full run, delta runs, keyset pages, deletions from
 * soft deletes and tombstones, only the user's own / visible data) and batched writes through the web's
 * offline engine (per-change results, idempotent request_ids, conflicts, policies).
 */
class SyncTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->setUpBoard();
        Notification::fake();
        $this->list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Errands']);
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function later(int $minutes = 1): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($minutes));
    }

    private function task(array $attributes = []): TaskListItem
    {
        return TaskListItem::factory()->create($attributes + ['task_list_id' => $this->list->id, 'created_by' => $this->editor->id]);
    }

    private function changes(string $query = '', ?string $token = null): TestResponse
    {
        return $this->getJson('/api/v1/sync/changes'.($query !== '' ? '?'.$query : ''), $this->appHeaders($token ?? $this->token));
    }

    /**
     * Walk a whole run.
     *
     * @return array{changes: list<array<string, mixed>>, cursor: string, reset: bool, pages: int, project_ids: list<int>}
     */
    private function syncRun(string $query = '', ?string $cursor = null, ?string $token = null): array
    {
        $changes = [];
        $pages = 0;
        do {
            $params = array_filter([$query, $cursor !== null ? 'cursor='.urlencode($cursor) : null]);
            $page = $this->changes(implode('&', $params), $token)->assertOk()->json();
            $changes = [...$changes, ...$page['data']];
            $cursor = $page['next_cursor'];
            // later pages: only the cursor (+ the page size)
            $query = preg_match('/(?:^|&)(limit=\d+)/', $query, $m) ? $m[1] : '';
            $pages++;
            $this->assertLessThan(50, $pages, 'the run ends');
        } while ($page['has_more']);

        return ['changes' => $changes, 'cursor' => $cursor, 'reset' => $page['reset'], 'pages' => $pages, 'project_ids' => $page['project_ids']];
    }

    /** @param list<array<string, mixed>> $changes */
    private function ids(array $changes, string $type, string $op = 'upsert'): array
    {
        return array_values(array_map(fn (array $c) => $c['id'], array_filter($changes, fn (array $c) => $c['type'] === $type && $c['op'] === $op)));
    }

    /** @param list<array<string, mixed>> $changes */
    private function sync(array $changes, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/sync', ['changes' => $changes], $this->appHeaders($token ?? $this->token));
    }

    // ── Access ───────────────────────────────────────────────────────────────────────────────

    public function test_only_app_tokens_reach_the_sync_endpoints(): void
    {
        $this->getJson('/api/v1/sync/changes')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/sync', ['changes' => []])->assertUnauthorized();

        $passport = $this->passportToken($this->editor);
        $this->getJson('/api/v1/sync/changes', $this->appHeaders($passport))->assertUnauthorized();
        $this->postJson('/api/v1/sync', ['changes' => [['request_id' => (string) Str::uuid(), 'type' => 'capture.create', 'data' => ['text' => 'x']]]], $this->appHeaders($passport))
            ->assertUnauthorized();
        $this->assertSame(0, Capture::count());
    }

    public function test_a_suspended_user_is_refused(): void
    {
        $this->editor->forceFill(['suspended_at' => now()])->save();

        $this->changes()->assertForbidden();
    }

    public function test_bad_scope_parameters_are_422(): void
    {
        $this->changes('scopes=work,pages')->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('scopes');
        $this->changes('task_lists=1,abc')->assertUnprocessable()->assertJsonValidationErrors('task_lists');
        $this->changes('limit=500')->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->sync([])->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->sync(array_fill(0, 51, ['request_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('changes');
    }

    // ── The feed: full run ───────────────────────────────────────────────────────────────────

    public function test_a_full_run_sends_the_offline_copy_and_nothing_of_anybody_else(): void
    {
        $mine = $this->item(['title' => 'Mine', 'assigned_to' => $this->editor->id]);
        $theirs = $this->item(['title' => 'Theirs', 'assigned_to' => $this->viewer->id]);
        $done = $this->item(['title' => 'Done already', 'assigned_to' => $this->editor->id, 'column_id' => $this->column('done')]);
        $myTask = $this->task(['title' => 'Call', 'assignee_id' => $this->editor->id]);
        $listTask = $this->task(['title' => 'Unassigned in the kept list']);
        $capture = Capture::factory()->create(['user_id' => $this->editor->id, 'text' => 'My idea']);
        $otherCapture = Capture::factory()->create(['user_id' => $this->viewer->id, 'text' => 'Not yours']);
        MyDayPlan::add($this->editor, 'board', $theirs->id); // My Day may hold work assigned to others

        // another workspace's project: an issue assigned to the editor there, a list there — never sent
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $otherOwner->id]);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $otherOwner->id]);
        $hidden = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $otherOwner->id, 'assigned_to' => $this->editor->id]);
        $hiddenList = TaskList::factory()->create(['project_id' => $otherProject->id, 'created_by' => $otherOwner->id]);
        TaskListItem::factory()->create(['task_list_id' => $hiddenList->id, 'created_by' => $otherOwner->id, 'assignee_id' => $this->editor->id]);

        $run = $this->syncRun("task_lists={$this->list->id},{$hiddenList->id}");

        $this->assertTrue($run['reset']);
        $this->assertSame([$this->project->id], $run['project_ids']);
        $this->assertEqualsCanonicalizing(["issue:{$mine->id}", "task:{$myTask->id}"], $this->ids($run['changes'], 'work'));
        $this->assertSame([(string) $this->list->id], $this->ids($run['changes'], 'task_list'));
        $this->assertEqualsCanonicalizing([(string) $myTask->id, (string) $listTask->id], $this->ids($run['changes'], 'task'));
        $this->assertSame([$capture->uuid], $this->ids($run['changes'], 'capture'));
        $this->assertSame([], array_filter($run['changes'], fn (array $c) => $c['op'] === 'delete'), 'a full run has no deletions');

        $day = collect($run['changes'])->firstWhere('type', 'my_day');
        $this->assertSame(['issue:'.$theirs->id], array_column($day['data']['items'], 'id'));

        $work = collect($run['changes'])->firstWhere('id', "issue:{$mine->id}");
        $this->assertSame('Mine', $work['data']['title']);
        $this->assertSame($this->project->id, $work['project_id']);
        $this->assertSame('Errands', collect($run['changes'])->firstWhere('type', 'task_list')['data']['name']);
        $this->assertSame('My idea', collect($run['changes'])->firstWhere('type', 'capture')['data']['text']);

        $json = json_encode($run['changes']);
        foreach (["issue:{$hidden->id}", "issue:{$theirs->id}\",\"op", "issue:{$done->id}", $otherCapture->uuid, 'Not yours'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_scopes_limit_what_a_run_sends(): void
    {
        $this->item(['assigned_to' => $this->editor->id]);
        Capture::factory()->create(['user_id' => $this->editor->id]);

        $run = $this->syncRun('scopes=captures');

        $this->assertSame(['capture'], array_values(array_unique(array_column($run['changes'], 'type'))));
    }

    public function test_pages_walk_every_change_once(): void
    {
        $items = collect(range(1, 7))->map(function (int $i) {
            Carbon::setTestNow(Carbon::now()->addSeconds($i % 3)); // ties on updated_at too

            return $this->item(['assigned_to' => $this->editor->id]);
        });

        $run = $this->syncRun('scopes=work&limit=2');

        $this->assertSame(4, $run['pages']);
        $ids = $this->ids($run['changes'], 'work');
        $this->assertCount(7, $ids);
        $this->assertEqualsCanonicalizing($items->map(fn (Item $item) => "issue:{$item->id}")->all(), $ids);
    }

    // ── The feed: delta runs ─────────────────────────────────────────────────────────────────

    public function test_a_delta_run_sends_only_what_changed_since(): void
    {
        $changed = $this->item(['title' => 'Old title', 'assigned_to' => $this->editor->id]);
        $same = $this->item(['assigned_to' => $this->editor->id]);
        $this->later();
        $full = $this->syncRun("task_lists={$this->list->id}");

        $this->later();
        $changed->update(['title' => 'New title']);
        $task = $this->task(['title' => 'New in the list']);

        $delta = $this->syncRun('', $full['cursor']);

        $this->assertFalse($delta['reset']);
        $this->assertSame(["issue:{$changed->id}"], $this->ids($delta['changes'], 'work'));
        $this->assertSame('New title', collect($delta['changes'])->firstWhere('type', 'work')['data']['title']);
        $this->assertSame([(string) $task->id], $this->ids($delta['changes'], 'task'));
        $this->assertSame([(string) $this->list->id], $this->ids($delta['changes'], 'task_list'), 'its counts changed');
        $this->assertSame([], $this->ids($delta['changes'], 'my_day'), 'My Day did not change');
        $this->assertNotContains("issue:{$same->id}", $this->ids($delta['changes'], 'work'));

        // My Day changes → one my_day record; a completed issue comes with done: true
        $this->later();
        MyDayPlan::add($this->editor, 'board', $same->id);
        $changed->update(['column_id' => $this->column('done')]);
        $next = $this->syncRun('', $delta['cursor']);
        $day = collect($next['changes'])->firstWhere('type', 'my_day');
        $this->assertSame(["issue:{$same->id}"], array_column($day['data']['items'], 'id'));
        $this->assertTrue(collect($next['changes'])->firstWhere('id', "issue:{$changed->id}")['data']['done']);
    }

    public function test_deletions_reach_the_client(): void
    {
        $deleted = $this->item(['assigned_to' => $this->editor->id]);
        $reassigned = $this->item(['assigned_to' => $this->editor->id]);
        $bounced = $this->item(['assigned_to' => $this->editor->id]);
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $onTrashedBoard = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id, 'assigned_to' => $this->editor->id]);
        $listTask = $this->task();
        $myTask = $this->task(['assignee_id' => $this->editor->id]);
        $keptList2 = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $capture = Capture::factory()->create(['user_id' => $this->editor->id, 'archived_at' => now()]);
        $this->later();
        $full = $this->syncRun("task_lists={$this->list->id},{$keptList2->id}");

        $this->later();
        $deleted->delete();
        $reassigned->update(['assigned_to' => $this->viewer->id]);
        $bounced->update(['assigned_to' => $this->viewer->id]);
        $bounced->update(['assigned_to' => $this->editor->id]);
        $otherBoard->delete();
        $listTask->delete();
        $myTask->update(['assignee_id' => null]);
        $keptList2->delete();
        $this->deleteJson("/api/v1/captures/{$capture->uuid}", [], $this->appHeaders($this->token))->assertNoContent();

        $this->later();
        $delta = $this->syncRun('', $full['cursor']);

        $this->assertEqualsCanonicalizing(
            ["issue:{$deleted->id}", "issue:{$reassigned->id}", "issue:{$onTrashedBoard->id}", "task:{$myTask->id}"],
            $this->ids($delta['changes'], 'work', 'delete'),
        );
        $this->assertContains("issue:{$bounced->id}", $this->ids($delta['changes'], 'work'), 'reassigned back: an upsert, no delete');
        $this->assertSame([(string) $listTask->id], $this->ids($delta['changes'], 'task', 'delete'));
        $this->assertSame([(string) $keptList2->id], $this->ids($delta['changes'], 'task_list', 'delete'));
        $this->assertSame([$capture->uuid], $this->ids($delta['changes'], 'capture', 'delete'));
        $this->assertNull(collect($delta['changes'])->firstWhere('op', 'delete')['data']);

        // reassignments leave a tombstone for the previous assignee only (the viewer had `bounced` for a moment)
        $this->assertSame(3, SyncTombstone::where('user_id', $this->editor->id)->where('reason', 'unassigned')->count());
        $this->assertSame(["issue:{$bounced->id}"], SyncTombstone::where('user_id', $this->viewer->id)->pluck('entity_id')->all());
        // nothing is deleted twice: the next run is empty
        $this->later();
        $this->assertSame([], $this->syncRun('', $delta['cursor'])['changes']);
    }

    public function test_lost_access_shows_in_project_ids(): void
    {
        $this->item(['assigned_to' => $this->editor->id]);
        $full = $this->syncRun();
        $this->assertSame([$this->project->id], $full['project_ids']);

        $this->project->users()->detach($this->editor->id);
        $this->later();
        $delta = $this->syncRun('', $full['cursor']);

        $this->assertSame([], $delta['project_ids']);
        $this->assertSame([], $this->ids($delta['changes'], 'work'));
    }

    public function test_unknown_old_foreign_or_rescoped_cursors_start_a_full_run(): void
    {
        $this->item(['assigned_to' => $this->editor->id]);
        $full = $this->syncRun('scopes=work');

        $this->assertTrue($this->syncRun('', 'not-a-cursor')['reset']);
        $this->assertTrue($this->syncRun('', base64_encode('{"v":1}'))['reset']);
        $this->assertFalse($this->syncRun('', $full['cursor'])['reset']);

        // scopes changed → full run with the new scope
        $rescoped = $this->syncRun('scopes=work,captures', $full['cursor']);
        $this->assertTrue($rescoped['reset']);

        // another account's cursor
        $this->assertTrue($this->syncRun('', $full['cursor'], $this->appToken($this->viewer))['reset']);

        // older than the tombstones (30 days)
        $this->later(60 * 24 * 31);
        $this->token = $this->appToken($this->editor); // access tokens live 15 minutes
        $this->assertTrue($this->syncRun('', $full['cursor'])['reset']);

        // a forged keyset position never breaks the query
        $forged = new SyncCursor($this->editor->id, false, Carbon::now()->subMinute()->toImmutable(), ['work'], [], startedAt: Carbon::now()->toImmutable(), phase: 2, key: ["x'); drop table items; --", 'y']);
        $this->changes('cursor='.$forged->encode())->assertOk();
    }

    // ── Batched writes ───────────────────────────────────────────────────────────────────────

    public function test_a_batch_applies_each_change_on_its_own(): void
    {
        $task = $this->task(['title' => 'Buy milk']);
        $issue = $this->item(['title' => 'Fix login', 'assigned_to' => $this->editor->id]);
        $ids = array_map(fn () => (string) Str::uuid(), range(0, 5));

        $results = $this->sync([
            ['request_id' => $ids[0], 'type' => 'capture.create', 'data' => ['text' => 'Offline idea']],
            ['request_id' => $ids[1], 'type' => 'list_task.update', 'target' => ['id' => $task->id], 'base' => ['fields' => ['title' => 'Buy milk']], 'data' => ['title' => 'Buy oat milk']],
            ['request_id' => $ids[2], 'type' => 'list_task.update', 'target' => ['id' => $task->id], 'data' => ['title' => 'No base']],
            ['type' => 'capture.create', 'data' => ['text' => 'No request id']],
            ['request_id' => $ids[3], 'type' => 'page.update', 'data' => []],
            ['request_id' => $ids[4], 'type' => 'work.done', 'base' => ['fields' => ['done' => false]], 'data' => ['work_type' => 'board', 'work_id' => $issue->id, 'done' => true]],
            ['request_id' => $ids[5], 'type' => 'list_task.update', 'target' => ['id' => 999999], 'base' => ['fields' => ['title' => 'x']], 'data' => ['title' => 'y']],
        ])->assertOk()->json('data');

        $this->assertSame(['applied', 'applied', 'invalid', 'invalid', 'invalid', 'applied', 'deleted'], array_column($results, 'status'));
        $this->assertSame([200, 200, 422, 422, 422, 200, 410], array_column($results, 'http_status'));
        $this->assertSame([$ids[0], $ids[1], $ids[2], null, $ids[3], $ids[4], $ids[5]], array_column($results, 'request_id'));
        $this->assertArrayHasKey('base.fields', $results[2]['errors']);
        $this->assertArrayHasKey('request_id', $results[3]['errors']);
        $this->assertArrayHasKey('type', $results[4]['errors']);

        $this->assertSame('Buy oat milk', $task->fresh()->title);
        $capture = Capture::where('user_id', $this->editor->id)->sole();
        $this->assertSame('Brainitive Desktop', $capture->source, 'as POST /captures');
        $this->assertTrue((bool) $issue->fresh()->column->is_done_column, 'work.done moved it to the done column');
        $this->assertTrue($results[5]['entity']['done']);
    }

    public function test_request_ids_make_changes_idempotent(): void
    {
        $id = (string) Str::uuid();
        $change = ['request_id' => $id, 'type' => 'capture.create', 'data' => ['text' => 'Once']];

        $first = $this->sync([$change])->assertOk()->json('data.0');
        $again = $this->sync([$change, $change])->assertOk()->json('data');

        $this->assertFalse($first['replayed']);
        $this->assertSame([true, true], array_column($again, 'replayed'));
        $this->assertSame([$first['entity'], $first['entity']], array_column($again, 'entity'));
        $this->assertSame(1, Capture::count());

        $other = $this->sync([['request_id' => $id, 'type' => 'capture.create', 'data' => ['text' => 'Something else']]])->json('data.0');
        $this->assertSame('invalid', $other['status']);
        $this->assertArrayHasKey('id', $other['errors']);
        $this->assertSame(1, Capture::count());
    }

    public function test_a_change_can_refer_to_a_create_earlier_in_the_batch(): void
    {
        $create = (string) Str::uuid();
        $results = $this->sync([
            ['request_id' => $create, 'type' => 'list_task.create', 'data' => ['task_list_id' => $this->list->id, 'title' => 'Draft']],
            ['request_id' => (string) Str::uuid(), 'type' => 'list_task.update', 'target' => ['ref' => $create], 'base' => ['fields' => ['status' => 'todo']], 'data' => ['status' => 'done']],
            ['request_id' => (string) Str::uuid(), 'type' => 'my_day.add', 'data' => ['work_type' => 'list', 'work_ref' => $create]],
            ['request_id' => (string) Str::uuid(), 'type' => 'list_task.update', 'target' => ['ref' => (string) Str::uuid()], 'base' => ['fields' => ['title' => 'a']], 'data' => ['title' => 'b']],
        ])->assertOk()->json('data');

        $this->assertSame(['applied', 'applied', 'applied', 'waiting'], array_column($results, 'status'));
        $this->assertSame(424, $results[3]['http_status']);
        $task = TaskListItem::where('title', 'Draft')->sole();
        $this->assertSame('done', $task->status);
        $this->assertTrue(MyDayEntry::where('user_id', $this->editor->id)->where('work_id', $task->id)->exists());
    }

    public function test_a_conflict_is_reported_per_change_and_never_overwrites(): void
    {
        $task = $this->task(['title' => 'Buy milk']);
        $task->update(['title' => 'Buy bread']); // someone else, meanwhile

        $result = $this->sync([[
            'request_id' => (string) Str::uuid(),
            'type' => 'list_task.update',
            'target' => ['id' => $task->id],
            'base' => ['fields' => ['title' => 'Buy milk', 'status' => 'todo']],
            'data' => ['title' => 'Buy oat milk', 'status' => 'done'],
        ]])->assertOk()->json('data.0');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame(409, $result['http_status']);
        $this->assertSame(['base' => 'Buy milk', 'mine' => 'Buy oat milk', 'theirs' => 'Buy bread'], $result['conflicts']['title']);
        $this->assertSame(['status'], $result['applied']);
        $this->assertSame('Buy bread', $task->fresh()->title);
        $this->assertSame('done', $task->fresh()->status);
        // the server state as the feed's task record (BRAI-441)
        $this->assertSame('task', $result['record_type']);
        $this->assertSame('Buy bread', $result['entity']['title']);
        $this->assertSame('done', $result['entity']['status']);
    }

    public function test_policies_apply_to_every_change(): void
    {
        $viewerToken = $this->appToken($this->viewer);
        $task = $this->task(['title' => 'Read only']);
        $issue = $this->item(['assigned_to' => $this->viewer->id]);

        $results = $this->sync([
            ['request_id' => (string) Str::uuid(), 'type' => 'list_task.update', 'target' => ['id' => $task->id], 'base' => ['fields' => ['title' => 'Read only']], 'data' => ['title' => 'Changed']],
            ['request_id' => (string) Str::uuid(), 'type' => 'work.done', 'base' => ['fields' => ['done' => false]], 'data' => ['work_type' => 'board', 'work_id' => $issue->id, 'done' => true]],
            ['request_id' => (string) Str::uuid(), 'type' => 'capture.create', 'data' => ['text' => 'Viewers capture too']],
        ], $viewerToken)->assertOk()->json('data');

        $this->assertSame(['forbidden', 'forbidden', 'applied'], array_column($results, 'status'));
        $this->assertSame('Read only', $task->fresh()->title);

        // the outsider can't even learn that the task exists: deleted, as for a deleted one
        $outsider = $this->sync([
            ['request_id' => (string) Str::uuid(), 'type' => 'list_task.update', 'target' => ['id' => $task->id], 'base' => ['fields' => ['title' => 'Read only']], 'data' => ['title' => 'Hacked']],
        ], $this->appToken($this->outsider))->json('data.0');
        $this->assertSame('deleted', $outsider['status']);
        $this->assertSame('Read only', $task->fresh()->title);
    }

    public function test_the_batch_budget_is_per_user(): void
    {
        RateLimiter::increment('app-sync:'.$this->editor->id, 60, 240);

        $result = $this->sync([['request_id' => (string) Str::uuid(), 'type' => 'capture.create', 'data' => ['text' => 'Later']]])->json('data.0');

        $this->assertSame('rate_limited', $result['status']);
        $this->assertSame(429, $result['http_status']);
        $this->assertSame(0, Capture::count());
    }

    // ── v2 (BRAI-441) ────────────────────────────────────────────────────────────────────────

    private function v1Cursor(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload + ['v' => 1, 'u' => $this->editor->id])), '+/', '-_'), '=');
    }

    private function decoded(string $cursor): array
    {
        return json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true);
    }

    public function test_sync_results_carry_the_feeds_records(): void
    {
        $issue = $this->item(['title' => 'Fix login', 'assigned_to' => $this->editor->id]);
        $task = $this->task(['title' => 'Buy milk', 'assignee_id' => $this->editor->id]);
        $create = (string) Str::uuid();
        $before = $this->syncRun("task_lists={$this->list->id}");
        $this->later();
        $update = ['request_id' => (string) Str::uuid(), 'type' => 'issue.update', 'target' => ['id' => $issue->id], 'base' => ['fields' => ['title' => 'Fix login']], 'data' => ['title' => 'Fix the login']];

        $results = $this->sync([
            ['request_id' => (string) Str::uuid(), 'type' => 'work.done', 'base' => ['fields' => ['done' => false]], 'data' => ['work_type' => 'board', 'work_id' => $issue->id, 'done' => true]],
            ['request_id' => (string) Str::uuid(), 'type' => 'work.done', 'base' => ['fields' => ['done' => false]], 'data' => ['work_type' => 'list', 'work_id' => $task->id, 'done' => true]],
            ['request_id' => (string) Str::uuid(), 'type' => 'list_task.reorder', 'data' => ['task_list_id' => $this->list->id, 'items' => [['id' => $task->id]]]],
            ['request_id' => $create, 'type' => 'list_task.create', 'data' => ['task_list_id' => $this->list->id, 'title' => 'Draft']],
            ['request_id' => (string) Str::uuid(), 'type' => 'capture.create', 'data' => ['text' => 'Offline idea']],
            ['request_id' => (string) Str::uuid(), 'type' => 'my_day.add', 'data' => ['work_type' => 'board', 'work_id' => $issue->id]],
            ['request_id' => (string) Str::uuid(), 'type' => 'issue.comment', 'target' => ['id' => $issue->id], 'data' => ['body' => 'Looks good']],
            $update,
        ])->assertOk()->json('data');

        $this->assertSame(array_fill(0, 8, 'applied'), array_column($results, 'status'));
        $this->assertSame(['work', 'work', 'task_list', 'task', 'capture', 'my_day', 'comment', 'work'], array_column($results, 'record_type'));
        $this->assertSame("issue:{$issue->id}", $results[0]['entity']['id']);
        $this->assertTrue($results[0]['entity']['done']);
        $this->assertSame("task:{$task->id}", $results[1]['entity']['id']);
        $this->assertTrue($results[1]['entity']['done']);
        $this->assertSame(['update' => true, 'delete' => false, 'manage_items' => true], $results[2]['entity']['can']);
        $this->assertSame('Draft', $results[3]['entity']['title']);
        $this->assertSame('Offline idea', $results[4]['entity']['text']);
        $this->assertSame(["issue:{$issue->id}"], array_column($results[5]['entity']['items'], 'id'));
        $this->assertSame('Looks good', $results[6]['entity']['body']);
        $this->assertSame('Fix the login', $results[7]['entity']['title']);

        // exactly the feed's records
        $this->later();
        $run = $this->syncRun('', $before['cursor']);
        $feed = fn (string $type, string $id) => collect($run['changes'])->first(fn (array $c) => $c['type'] === $type && $c['id'] === $id)['data'];
        $created = TaskListItem::where('title', 'Draft')->sole();
        $this->assertEquals($feed('work', "issue:{$issue->id}"), $results[7]['entity']);
        $this->assertEquals($feed('task', (string) $created->id), $results[3]['entity']);
        $this->assertEquals($feed('capture', $results[4]['entity']['id']), $results[4]['entity']);
        $this->assertEquals($feed('task_list', (string) $this->list->id)['can'], $results[2]['entity']['can']);

        // a replay renders the current state; gone → no entity
        $again = $this->sync([$update])->json('data.0');
        $this->assertTrue($again['replayed']);
        $this->assertEquals($results[7]['entity'], $again['entity']);
        $issue->delete();
        $gone = $this->sync([$update])->json('data.0');
        $this->assertSame(['applied', true, null, null], [$gone['status'], $gone['replayed'], $gone['record_type'], $gone['entity']]);
    }

    public function test_task_list_records_carry_what_the_user_may_do(): void
    {
        $viewerToken = $this->appToken($this->viewer);
        $editor = $this->syncRun("task_lists={$this->list->id}");
        $viewer = $this->syncRun("task_lists={$this->list->id}", null, $viewerToken);

        $can = fn (array $run) => collect($run['changes'])->firstWhere('type', 'task_list')['data']['can'] ?? null;
        $this->assertSame(['update' => true, 'delete' => false, 'manage_items' => true], $can($editor));
        $this->assertSame(['update' => false, 'delete' => false, 'manage_items' => false], $can($viewer));

        // a new role changes no list, but the next run sends the list again
        $this->later();
        $this->project->users()->updateExistingPivot($this->viewer->id, ['role' => 'editor']);
        $delta = $this->syncRun('', $viewer['cursor'], $viewerToken);
        $this->assertSame([(string) $this->list->id], $this->ids($delta['changes'], 'task_list'));
        $this->assertTrue($can($delta)['manage_items']);

        $this->later();
        $this->assertSame([], $this->syncRun('', $delta['cursor'], $viewerToken)['changes'], 'sent once');
    }

    public function test_my_day_is_sent_again_after_work_done(): void
    {
        $issue = $this->item(['assigned_to' => $this->editor->id]);
        MyDayPlan::add($this->editor, 'board', $issue->id);
        $this->later();
        $full = $this->syncRun('scopes=work,my_day');
        $this->assertFalse(collect($full['changes'])->firstWhere('type', 'my_day')['data']['items'][0]['done']);

        $this->later();
        $this->sync([['request_id' => (string) Str::uuid(), 'type' => 'work.done', 'base' => ['fields' => ['done' => false]], 'data' => ['work_type' => 'board', 'work_id' => $issue->id, 'done' => true]]])
            ->assertJsonPath('data.0.status', 'applied');

        $delta = $this->syncRun('', $full['cursor']);
        $day = collect($delta['changes'])->firstWhere('type', 'my_day');
        $this->assertNotNull($day, 'a done change re-sends My Day');
        $this->assertTrue($day['data']['items'][0]['done']);
        $this->assertTrue(collect($delta['changes'])->firstWhere('id', "issue:{$issue->id}")['data']['done']);
    }

    public function test_mine_keeps_the_lists_the_user_works_in_across_workspaces(): void
    {
        // a second workspace the editor works in
        $owner = User::factory()->create();
        $workspace2 = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace2->addUser($owner, 'admin');
        $workspace2->addUser($this->editor, 'member');
        $project2 = Project::factory()->create(['workspace_id' => $workspace2->id, 'created_by' => $owner->id]);
        $project2->users()->attach($this->editor->id, ['role' => 'editor']);
        $list = fn (Project $project, ?User $by = null, array $attributes = []) => TaskList::factory()->create($attributes + ['project_id' => $project->id, 'created_by' => ($by ?? $owner)->id]);
        $taskIn = fn (TaskList $list, array $attributes = []) => TaskListItem::factory()->create($attributes + ['task_list_id' => $list->id, 'created_by' => $this->viewer->id]);

        // a project the editor can't open
        $stranger = User::factory()->create();
        $hiddenWorkspace = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $hiddenProject = Project::factory()->create(['workspace_id' => $hiddenWorkspace->id, 'created_by' => $stranger->id]);
        $hidden = $list($hiddenProject, $stranger);
        $taskIn($hidden, ['title' => 'Secret task', 'assignee_id' => $this->editor->id, 'created_by' => $stranger->id]);

        $taskIn($this->list, ['assignee_id' => $this->editor->id]);                     // open task assigned to me
        $pinned = $list($project2);                                                      // pinned in the sidebar
        $recent = $list($this->project, $this->viewer);
        $taskIn($recent, ['status' => 'done', 'created_by' => $this->editor->id]);      // a task I created, changed recently
        $created = $list($project2, $this->editor);                                      // a list I created recently
        $old = $list($this->project, $this->viewer, ['created_at' => now()->subDays(40)]);
        $oldTask = $taskIn($old, ['status' => 'done', 'assignee_id' => $this->editor->id]);
        TaskListItem::whereKey($oldTask->id)->update(['updated_at' => now()->subDays(40)]);
        $theirs = $list($this->project, $this->viewer);
        $taskIn($theirs, ['title' => 'Their task', 'assignee_id' => $this->viewer->id]);
        $trashed = $list($this->project);
        $taskIn($trashed, ['assignee_id' => $this->editor->id]);
        $trashed->delete();
        $this->editor->forceFill(['sidebar_preferences' => ['projects' => [
            (string) $project2->id => ['pins' => [['type' => 'list', 'id' => $pinned->id]]],
            (string) $hiddenProject->id => ['pins' => [['type' => 'list', 'id' => $hidden->id]]],
        ]]])->save();

        $run = $this->syncRun('scopes=task_lists&task_lists=mine');

        $expected = [$this->list->id, $pinned->id, $recent->id, $created->id];
        sort($expected);
        $this->assertSame($expected, $this->changes('task_lists=mine')->json('task_lists'));
        $this->assertEqualsCanonicalizing(array_map('strval', $expected), $this->ids($run['changes'], 'task_list'));
        $json = json_encode($run['changes']);
        $this->assertStringNotContainsString('Secret task', $json);
        $this->assertStringNotContainsString('Their task', $json);

        // ids and mine together
        $this->assertContains($theirs->id, $this->changes("task_lists=mine,{$theirs->id}")->json('task_lists'));
        // another user's mine is their own: lists they created recently, the task they created in errands
        $this->assertSame(collect([$theirs->id, $recent->id, $this->list->id])->sort()->values()->all(), $this->changes('task_lists=mine', $this->appToken($this->viewer))->json('task_lists'));
    }

    public function test_mine_is_capped(): void
    {
        foreach (range(1, ChangeFeed::MAX_LISTS + 3) as $i) {
            $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id]);
            TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->viewer->id, 'assignee_id' => $this->editor->id]);
        }

        $this->assertCount(ChangeFeed::MAX_LISTS, $this->changes('scopes=task_lists&task_lists=mine&limit=1')->json('task_lists'));
    }

    public function test_other_task_lists_are_additive(): void
    {
        $issue = $this->item(['assigned_to' => $this->editor->id]);
        $this->task(['title' => 'In errands']);
        $groceries = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id, 'name' => 'Groceries']);
        $milk = TaskListItem::factory()->create(['task_list_id' => $groceries->id, 'created_by' => $this->viewer->id, 'title' => 'Milk']);
        $this->later();
        $full = $this->syncRun("task_lists={$this->list->id}");

        // a list added: only it comes in full, the run is no full run
        $this->later();
        $added = $this->syncRun("task_lists={$this->list->id},{$groceries->id}", $full['cursor']);
        $this->assertFalse($added['reset']);
        $this->assertSame([(string) $groceries->id], $this->ids($added['changes'], 'task_list'));
        $this->assertSame([(string) $milk->id], $this->ids($added['changes'], 'task'));
        $this->assertSame([], $this->ids($added['changes'], 'work'), "the other scopes don't start over");
        $this->assertSame([$this->list->id, $groceries->id], $this->changes('cursor='.urlencode($added['cursor']))->json('task_lists'));

        // a list dropped: a delete
        $this->later();
        $dropped = $this->syncRun("task_lists={$groceries->id}", $added['cursor']);
        $this->assertFalse($dropped['reset']);
        $this->assertSame([(string) $this->list->id], $this->ids($dropped['changes'], 'task_list', 'delete'));
        $this->assertSame([], $this->ids($dropped['changes'], 'task'));
        $this->later();
        $this->assertSame([], $this->syncRun('', $dropped['cursor'])['changes']);

        // the mine set changes by itself: a new assigned task in another list → that list in full
        $this->later();
        $mine = $this->syncRun('task_lists=mine', $dropped['cursor']);
        $this->assertFalse($mine['reset']);
        $this->assertSame([(string) $groceries->id], $this->ids($mine['changes'], 'task_list', 'delete'), 'groceries is not mine');
        $this->assertSame([(string) $this->list->id], $this->ids($mine['changes'], 'task_list'), 'errands is (I created it)');
        $this->later();
        $errand = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id]);
        $chore = TaskListItem::factory()->create(['task_list_id' => $errand->id, 'created_by' => $this->viewer->id, 'assignee_id' => $this->editor->id]);
        $old = TaskListItem::factory()->create(['task_list_id' => $errand->id, 'created_by' => $this->viewer->id]);
        TaskListItem::whereKey($old->id)->update(['updated_at' => now()->subDays(3)]);
        $this->later();
        $next = $this->syncRun('', $mine['cursor']);
        $this->assertSame([(string) $errand->id], $this->ids($next['changes'], 'task_list'));
        $this->assertEqualsCanonicalizing([(string) $chore->id, (string) $old->id], $this->ids($next['changes'], 'task'), 'an older task of the new list too');
        $this->assertContains("issue:{$issue->id}", $this->ids($full['changes'], 'work'));
    }

    public function test_lists_can_change_in_the_middle_of_a_run(): void
    {
        foreach (range(1, 3) as $i) {
            $this->item(['assigned_to' => $this->editor->id]);
        }
        $groceries = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->later();
        $full = $this->syncRun("task_lists={$this->list->id}");
        $this->later();
        $this->item(['assigned_to' => $this->editor->id]);
        $this->item(['assigned_to' => $this->editor->id]);

        $first = $this->changes('limit=1&cursor='.urlencode($full['cursor']))->assertOk()->json();
        $this->assertTrue($first['has_more']);
        $rest = $this->syncRun("task_lists={$this->list->id},{$groceries->id}&limit=1", $first['next_cursor']);

        $this->assertFalse($rest['reset']);
        $this->assertSame([(string) $groceries->id], $this->ids($rest['changes'], 'task_list'));
    }

    public function test_v1_cursors_keep_working(): void
    {
        $since = Carbon::now()->subSeconds(10)->format('Y-m-d\TH:i:s\Z');
        $scopes = ['captures', 'my_day', 'task_lists', 'work'];
        $this->later();
        $issue = $this->item(['title' => 'Changed', 'assigned_to' => $this->editor->id]);
        $capture = Capture::factory()->create(['user_id' => $this->editor->id, 'archived_at' => now()]);

        // the end of a v1 run
        $run = $this->syncRun('', $this->v1Cursor(['r' => false, 's' => $since, 't' => null, 'sc' => $scopes, 'l' => [$this->list->id], 'p' => 0, 'k' => null, 'h' => null]));
        $this->assertFalse($run['reset']);
        $this->assertSame(["issue:{$issue->id}"], $this->ids($run['changes'], 'work'));
        $this->assertSame([$capture->uuid], $this->ids($run['changes'], 'capture'), 'v1 cursors keep every capture');
        $list = collect($run['changes'])->firstWhere('type', 'task_list');
        $this->assertSame((string) $this->list->id, $list['id'], 'the kept lists come again, now with can');
        $this->assertTrue($list['data']['can']['manage_items']);
        $this->assertSame(2, $this->decoded($run['cursor'])['v']);

        // in the middle of a v1 run (phase 2 = work issues)
        $middle = $this->syncRun('', $this->v1Cursor(['r' => false, 's' => $since, 't' => Carbon::now()->format('Y-m-d\TH:i:s\Z'), 'sc' => $scopes, 'l' => [], 'p' => 2, 'k' => null, 'h' => null]));
        $this->assertFalse($middle['reset']);
        $this->assertSame(["issue:{$issue->id}"], $this->ids($middle['changes'], 'work'));
        $this->assertSame([], $this->ids($middle['changes'], 'task_list'), 'its list phases had passed');

        // what can't be read resets cleanly
        $this->assertTrue($this->syncRun('', $this->v1Cursor(['r' => false, 's' => $since, 'sc' => $scopes, 'l' => [], 'p' => 99]))['reset']);
        $this->assertTrue($this->syncRun('', $this->v1Cursor(['v' => 3, 's' => $since, 'sc' => $scopes, 'p' => 0]))['reset']);
    }

    public function test_the_inbox_filter_keeps_only_inbox_captures(): void
    {
        $keep = Capture::factory()->create(['user_id' => $this->editor->id]);
        $archive = Capture::factory()->create(['user_id' => $this->editor->id]);
        $process = Capture::factory()->create(['user_id' => $this->editor->id]);
        $archived = Capture::factory()->create(['user_id' => $this->editor->id, 'archived_at' => now()]);
        Capture::factory()->create(['user_id' => $this->viewer->id]);
        $this->later();

        $full = $this->syncRun('scopes=captures&captures=inbox');
        $this->assertEqualsCanonicalizing([$keep->uuid, $archive->uuid, $process->uuid], $this->ids($full['changes'], 'capture'));

        $this->later();
        $this->postJson("/api/v1/captures/{$archive->uuid}/archive", [], $this->appHeaders($this->token))->assertOk();
        $process->update(['processed_at' => now()]);
        $new = Capture::factory()->create(['user_id' => $this->editor->id]);
        $this->later();
        $delta = $this->syncRun('', $full['cursor']);
        $this->assertEqualsCanonicalizing([$archive->uuid, $process->uuid], $this->ids($delta['changes'], 'capture', 'delete'), 'left the inbox');
        $this->assertSame([$new->uuid], $this->ids($delta['changes'], 'capture'));

        // back in the inbox → an upsert again
        $this->later();
        $this->postJson("/api/v1/captures/{$archive->uuid}/restore", [], $this->appHeaders($this->token))->assertOk();
        $this->later();
        $this->assertSame([$archive->uuid], $this->ids($this->syncRun('', $delta['cursor'])['changes'], 'capture'));

        // another filter → a full run; the default stays every capture
        $all = $this->syncRun('captures=all', $delta['cursor']);
        $this->assertTrue($all['reset']);
        $this->assertContains($archived->uuid, $this->ids($all['changes'], 'capture'));
        $this->assertContains($archived->uuid, $this->ids($this->syncRun('scopes=captures')['changes'], 'capture'));
        $this->changes('captures=done')->assertUnprocessable()->assertJsonValidationErrors('captures');
        $this->changes('task_lists=mine,abc')->assertUnprocessable()->assertJsonValidationErrors('task_lists');
    }
}
