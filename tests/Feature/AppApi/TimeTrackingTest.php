<?php

namespace Tests\Feature\AppApi;

use App\Models\BillingActivity;
use App\Models\BillingReport;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkTimer;
use App\Support\Billing\WorkTimers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 time tracking (BRAI-400): the focus timer shared with the web (one per user, start needs edit
 * rights, stop logs once), own work logs with the web timesheet's rules (activities, billed = read-only,
 * issues' totals), my timesheet, and never a rate.
 */
class TimeTrackingTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private BillingActivity $programming;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00', 'UTC')); // a Thursday, ISO week 43
        $this->programming = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Programming', 'pricing_type' => 'hourly', 'rate_cents' => 12000]);
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($user ? $this->appToken($user) : $this->token);
    }

    private function entry(array $attributes = []): TimeEntry
    {
        return TimeEntry::create($attributes + [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->editor->id,
            'billing_activity_id' => $this->programming->id,
            'spent_on' => '2026-10-21',
            'minutes' => 60,
            'billable' => true,
            'source' => TimeEntry::SOURCE_MANUAL,
        ]);
    }

    // ── timer ───────────────────────────────────────────────────────────────────────────────

    public function test_starts_shows_and_stops_the_timer_logging_once(): void
    {
        $item = $this->item(['title' => 'Fix login']);

        $this->getJson('/api/v1/me/timer', $this->headers())->assertOk()
            ->assertJsonPath('data.running', false)->assertJsonPath('data.timer', null)->assertJsonPath('data.activities', []);

        $started = $this->postJson('/api/v1/me/timer/start', ['id' => "issue:{$item->id}"], $this->headers())->assertCreated()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.timer.issue.work_id', "issue:{$item->id}")
            ->assertJsonPath('data.timer.issue.title', 'Fix login')
            ->assertJsonPath('data.activity_required', true)
            ->assertJsonPath('data.activities.0.name', 'Programming');
        $this->assertArrayNotHasKey('rate_cents', $started->json('data.activities.0'));
        $timerId = $started->json('data.timer.id');

        // one timer per user: a second start is a conflict
        $this->postJson('/api/v1/me/timer/start', ['id' => "issue:{$item->id}"], $this->headers())->assertStatus(409)->assertJsonPath('code', 'conflict');

        // 25 minutes later: an activity is required, the timer keeps running without one
        Carbon::setTestNow(now()->addMinutes(25));
        $this->token = $this->appToken($this->editor); // access tokens live 15 minutes
        $this->getJson('/api/v1/me/timer', $this->headers())->assertOk()->assertJsonPath('data.timer.elapsed_minutes', 25);
        $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $timerId], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('activity_id')
            // the picker's list: only what can be chosen, never a rate
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('activities', [['id' => $this->programming->id, 'name' => 'Programming']])
            ->assertJsonPath('default_activity_id', null);
        $this->assertTrue(WorkTimer::whereKey($timerId)->exists());

        $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $timerId, 'activity_id' => $this->programming->id, 'note' => 'login fix'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.result', 'logged')
            ->assertJsonPath('data.work_log.minutes', 25)
            ->assertJsonPath('data.work_log.source', 'timer')
            ->assertJsonPath('data.work_log.issue.key', $item->key)
            ->assertJsonPath('data.work_log.activity.name', 'Programming');
        $this->assertSame(25, $item->fresh()->time_spent);

        // a retry logs nothing
        $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $timerId, 'activity_id' => $this->programming->id], $this->headers())
            ->assertOk()->assertJsonPath('data.result', 'already_stopped')->assertJsonPath('data.work_log', null);
        $this->assertSame(1, TimeEntry::where('item_id', $item->id)->count());
    }

    public function test_an_unusable_activity_on_stop_also_lists_the_allowed_ones_and_archived_ones_are_left_out(): void
    {
        $archived = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Old', 'pricing_type' => 'hourly', 'rate_cents' => 100, 'archived_at' => now()]);
        $timer = WorkTimers::start($this->editor, $this->item());

        $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $timer->id, 'activity_id' => $archived->id], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('activity_id')
            ->assertJsonPath('activities', [['id' => $this->programming->id, 'name' => 'Programming']]);
        $this->assertTrue(WorkTimer::whereKey($timer->id)->exists());
    }

    public function test_a_validation_error_without_activity_keeps_the_plain_shape(): void
    {
        $timer = WorkTimers::start($this->editor, $this->item());

        $response = $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $timer->id, 'time' => 'nonsense', 'activity_id' => $this->programming->id], $this->headers());
        $response->assertUnprocessable();
        $this->assertArrayNotHasKey('activities', $response->json());
    }

    public function test_the_timer_needs_an_issue_the_user_may_edit(): void
    {
        $item = $this->item();
        $task = TaskListItem::factory()->create(['task_list_id' => TaskList::factory()->create(['project_id' => $this->project->id])->id]);
        $foreign = Item::factory()->create();

        $this->postJson('/api/v1/me/timer/start', ['id' => "issue:{$item->id}"], $this->headers($this->viewer))->assertForbidden();
        $this->postJson('/api/v1/me/timer/start', ['id' => "task:{$task->id}"], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson('/api/v1/me/timer/start', ['id' => "issue:{$foreign->id}"], $this->headers())->assertNotFound();
        $this->postJson('/api/v1/me/timer/start', ['id' => 'nonsense'], $this->headers())->assertNotFound();
        $this->assertSame(0, WorkTimer::count());
    }

    public function test_discards_the_timer_and_shares_it_with_the_web(): void
    {
        $item = $this->item();
        // started on the web (the same WorkTimers::start) → running in the app
        $timer = WorkTimers::start($this->editor, $item);
        $this->getJson('/api/v1/me/timer', $this->headers())->assertOk()->assertJsonPath('data.timer.id', $timer->id);

        // a stale id discards nothing
        $this->postJson('/api/v1/me/timer/discard', ['timer_id' => $timer->id + 1], $this->headers())->assertOk()->assertJsonPath('data.running', true);
        $this->postJson('/api/v1/me/timer/discard', ['timer_id' => $timer->id], $this->headers())->assertOk()->assertJsonPath('data.running', false);
        $this->assertSame(0, TimeEntry::count());

        // another user's timer is never touched
        $theirs = WorkTimer::create(['user_id' => $this->viewer->id, 'item_id' => $item->id, 'started_at' => now()]);
        $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $theirs->id, 'activity_id' => $this->programming->id], $this->headers())
            ->assertOk()->assertJsonPath('data.result', 'already_stopped');
        $this->assertTrue($theirs->exists());
    }

    // ── work logs ───────────────────────────────────────────────────────────────────────────

    public function test_logs_time_on_an_issue_and_on_a_project_like_the_web(): void
    {
        $item = $this->item(['title' => 'Release']);

        $logged = $this->postJson('/api/v1/work-logs', [
            'project_id' => $this->project->id, 'issue_id' => $item->id, 'spent_on' => '2026-10-21', 'time' => '1h 30m',
            'activity_id' => $this->programming->id, 'note' => 'deploy', 'request_id' => '3e4f5a6b-7c8d-4e9f-8a0b-1c2d3e4f5a6b',
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.minutes', 90)
            ->assertJsonPath('data.duration', '1h 30m')
            ->assertJsonPath('data.issue.work_id', "issue:{$item->id}")
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.workspace.slug', $this->workspace->slug);
        $this->assertSame([
            'id', 'spent_on', 'minutes', 'duration', 'note', 'billable', 'source', 'locked', 'project', 'issue', 'activity', 'workspace', 'created_at', 'updated_at',
        ], array_keys($logged->json('data')));
        $this->assertSame(90, $item->fresh()->time_spent);

        // a retry creates nothing new
        $this->postJson('/api/v1/work-logs', [
            'project_id' => $this->project->id, 'issue_id' => $item->id, 'spent_on' => '2026-10-21', 'time' => '1h 30m',
            'activity_id' => $this->programming->id, 'note' => 'deploy', 'request_id' => '3e4f5a6b-7c8d-4e9f-8a0b-1c2d3e4f5a6b',
        ], $this->headers())->assertOk()->assertJsonPath('data.id', $logged->json('data.id'));
        $this->assertSame(90, $item->fresh()->time_spent);

        // on the project only, plain minutes
        $this->postJson('/api/v1/work-logs', ['project_id' => $this->project->id, 'spent_on' => '2026-10-22', 'time' => '45', 'activity_id' => $this->programming->id], $this->headers())
            ->assertCreated()->assertJsonPath('data.minutes', 45)->assertJsonPath('data.issue', null);

        // the web's rules: activity required, no future days, loggable projects / issues only
        $this->postJson('/api/v1/work-logs', ['project_id' => $this->project->id, 'spent_on' => '2026-10-21', 'time' => '1h'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('activity_id');
        $this->postJson('/api/v1/work-logs', ['project_id' => $this->project->id, 'spent_on' => '2026-12-01', 'time' => '1h', 'activity_id' => $this->programming->id], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('spent_on');
        $foreign = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $this->postJson('/api/v1/work-logs', ['project_id' => $foreign->id, 'spent_on' => '2026-10-21', 'time' => '1h'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $otherIssue = Item::factory()->create();
        $this->postJson('/api/v1/work-logs', ['project_id' => $this->project->id, 'issue_id' => $otherIssue->id, 'spent_on' => '2026-10-21', 'time' => '1h', 'activity_id' => $this->programming->id], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('issue_id');
        $this->postJson('/api/v1/work-logs', ['project_id' => $this->project->id, 'spent_on' => '2026-10-21', 'time' => '1h', 'activity_id' => $this->programming->id], $this->headers($this->viewer))
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_lists_changes_and_deletes_only_own_unbilled_logs(): void
    {
        $item = $this->item();
        $mine = $this->entry(['item_id' => $item->id, 'item_key' => $item->key, 'item_title' => $item->title, 'spent_on' => '2026-10-20']);
        $newer = $this->entry(['spent_on' => '2026-10-22']);
        $theirs = $this->entry(['user_id' => $this->viewer->id]);

        $list = $this->getJson('/api/v1/work-logs', $this->headers())->assertOk();
        $this->assertSame([$newer->id, $mine->id], array_column($list->json('data'), 'id'));
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/work-logs?from=2026-10-19&to=2026-10-21', $this->headers())->json('data'), 'id'));
        $this->getJson('/api/v1/work-logs?workspace=nope', $this->headers())->assertNotFound();
        $page = $this->getJson('/api/v1/work-logs?limit=1', $this->headers());
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/work-logs?limit=1&cursor='.urlencode($page->json('next_cursor')), $this->headers())->json('data'), 'id'));

        $this->getJson("/api/v1/work-logs/{$theirs->id}", $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/work-logs/{$theirs->id}", ['note' => 'mine'], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/work-logs/{$theirs->id}", [], $this->headers())->assertNotFound();

        // only the sent fields change; the issue's total follows
        $this->patchJson("/api/v1/work-logs/{$mine->id}", ['time' => '2h', 'note' => 'longer'], $this->headers())
            ->assertOk()->assertJsonPath('data.minutes', 120)->assertJsonPath('data.note', 'longer')->assertJsonPath('data.spent_on', '2026-10-20')
            ->assertJsonPath('data.issue.id', $item->id);
        $this->assertSame(120, $item->fresh()->time_spent);
        $this->patchJson("/api/v1/work-logs/{$mine->id}", ['issue_id' => null], $this->headers())->assertOk()->assertJsonPath('data.issue', null);
        $this->assertSame(0, $item->fresh()->time_spent);

        // billed entries are read-only
        $report = BillingReport::create(['workspace_id' => $this->workspace->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized']);
        $newer->forceFill(['billing_report_id' => $report->id])->save();
        $this->getJson("/api/v1/work-logs/{$newer->id}", $this->headers())->assertOk()->assertJsonPath('data.locked', true);
        $this->patchJson("/api/v1/work-logs/{$newer->id}", ['note' => 'x'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('entry');
        $this->deleteJson("/api/v1/work-logs/{$newer->id}", [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('entry');

        $this->deleteJson("/api/v1/work-logs/{$mine->id}", [], $this->headers())->assertNoContent();
        $this->assertModelMissing($mine);
        $this->deleteJson('/api/v1/work-logs/abc', [], $this->headers())->assertNotFound();
    }

    public function test_my_timesheet_shows_a_week_without_money(): void
    {
        $this->entry(['spent_on' => '2026-10-20', 'minutes' => 30]);
        $this->entry(['spent_on' => '2026-10-22', 'minutes' => 90]);
        $this->entry(['spent_on' => '2026-10-13', 'minutes' => 15]); // the week before
        $this->entry(['user_id' => $this->viewer->id, 'spent_on' => '2026-10-20']);

        $sheet = $this->getJson("/api/v1/me/timesheet?workspace={$this->workspace->slug}", $this->headers())->assertOk()
            ->assertJsonPath('data.week.start', '2026-10-19')
            ->assertJsonPath('data.week.end', '2026-10-25')
            ->assertJsonPath('data.week.number', 43)
            ->assertJsonPath('data.total_minutes', 120)
            ->assertJsonPath('data.days.1', ['date' => '2026-10-20', 'minutes' => 30])
            ->assertJsonPath('data.days.3', ['date' => '2026-10-22', 'minutes' => 90])
            ->assertJsonPath('data.projects.0.id', $this->project->id)
            ->assertJsonPath('data.activity_required', true)
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonCount(7, 'data.days');
        $this->assertStringNotContainsString('rate', $sheet->getContent());

        $this->getJson("/api/v1/me/timesheet?workspace={$this->workspace->slug}&week=2026-10-15", $this->headers())->assertOk()
            ->assertJsonPath('data.week.start', '2026-10-12')->assertJsonPath('data.total_minutes', 15);
        $this->getJson('/api/v1/me/timesheet?workspace='.Workspace::factory()->create()->slug, $this->headers())->assertNotFound();
        $this->getJson('/api/v1/me/timesheet', $this->headers())->assertUnprocessable()->assertJsonValidationErrors('workspace');
    }

    public function test_tokens_of_other_apis_and_unverified_users_are_refused(): void
    {
        $this->getJson('/api/v1/me/timer')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/me/timer', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->getJson('/api/v1/work-logs', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->getJson('/api/v1/work-logs', $this->appHeaders($this->appToken($unverified)))->assertForbidden();
    }

    public function test_today_logged_seconds_counts_the_users_local_today_without_the_running_timer(): void
    {
        // 2026-10-22 12:00 UTC is already 01:00 on the 23rd in Auckland (UTC+13)
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00', 'UTC'));
        $this->editor->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $this->entry(['spent_on' => '2026-10-23', 'minutes' => 30]);
        $this->entry(['spent_on' => '2026-10-23', 'minutes' => 15, 'billing_activity_id' => null]);
        $this->entry(['spent_on' => '2026-10-22', 'minutes' => 120]); // the UTC date: yesterday in Auckland
        $this->entry(['spent_on' => '2026-10-23', 'minutes' => 999, 'user_id' => $this->viewer->id]); // someone else
        WorkTimers::start($this->editor, $this->item(), now()->subHours(3));

        $this->getJson('/api/v1/me/timer', $this->headers($this->editor))->assertOk()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.today_logged_seconds', 45 * 60);

        // in UTC the 22nd is today
        $this->editor->forceFill(['timezone' => 'UTC'])->save();
        $this->getJson('/api/v1/me/timer', $this->headers($this->editor))->assertJsonPath('data.today_logged_seconds', 120 * 60);
    }

    public function test_the_timer_tag_changes_when_time_is_logged(): void
    {
        $first = $this->getJson('/api/v1/me/timer', $this->headers())->assertOk()->assertJsonPath('data.today_logged_seconds', 0);
        $tag = $first->headers->get('ETag');

        $this->entry(['spent_on' => '2026-10-22', 'minutes' => 20]);
        $this->getJson('/api/v1/me/timer', $this->headers() + ['If-None-Match' => $tag])->assertOk()->assertJsonPath('data.today_logged_seconds', 1200);
    }

    public function test_a_description_given_at_the_start_is_the_default_note(): void
    {
        $item = $this->item();
        $timer = $this->postJson('/api/v1/me/timer/start', ['id' => 'issue:'.$item->id, 'description' => 'Pairing with Ada'], $this->headers())
            ->assertCreated()->assertJsonPath('data.timer.description', 'Pairing with Ada')->json('data.timer.id');

        $this->postJson('/api/v1/me/timer/stop', ['timer_id' => $timer, 'time' => '30m', 'activity_id' => $this->programming->id], $this->headers())
            ->assertOk()->assertJsonPath('data.result', 'logged');
        $this->assertSame('Pairing with Ada', TimeEntry::where('item_id', $item->id)->sole()->note);
    }
}
