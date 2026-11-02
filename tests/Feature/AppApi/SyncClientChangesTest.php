<?php

namespace Tests\Feature\AppApi;

use App\Models\BillingActivity;
use App\Models\Board;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkspaceUsage;
use App\Models\WorkTimer;
use App\Support\AppApi\AppApiErrors;
use App\Support\Billing\WorkTimers;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API sync change types for the desktop tray / New-task window and later mobile: the focus timer
 * (`timer.start|stop|discard`, clamped device times, conflict / invalid with the activity picker / gone),
 * the inbox (`notification.read`, `notifications.read_all` with `before`) and `issue.create` with a Markdown
 * description and a type — each idempotent per request_id, through the web's offline engine.
 */
class SyncClientChangesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->setUpBoard();
        Notification::fake();
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function sync(array $changes, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/sync', ['changes' => $changes], $this->appHeaders($token ?? $this->token));
    }

    /** @param  array<string, mixed>  $data */
    private function change(string $type, array $data, ?string $id = null): array
    {
        return ['request_id' => $id ?? (string) Str::uuid(), 'type' => $type, 'data' => $data];
    }

    private function notify(User $user, array $data, ?Carbon $at = null): string
    {
        $id = (string) Str::uuid();
        $row = $user->notifications()->create(['id' => $id, 'type' => 'App\\Notifications\\Test', 'data' => $data]);
        if ($at) {
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $id;
    }

    // ── timer.start ─────────────────────────────────────────────────────────────────────────

    public function test_timer_start_clamps_the_device_time_and_conflicts_while_another_runs(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $start = $this->change('timer.start', ['issue_id' => $item->id, 'description' => 'Reproduce first', 'started_at' => now()->subHours(20)->toIso8601ZuluString()]);

        $result = $this->sync([$start])->assertOk()->json('data.0');
        $this->assertSame(['applied', 200, 'timer'], [$result['status'], $result['http_status'], $result['record_type']]);
        $this->assertTrue($result['entity']['running']);
        $this->assertSame('Reproduce first', $result['entity']['timer']['description']);
        $this->assertSame($item->id, $result['entity']['timer']['issue']['id']);
        $timer = WorkTimer::where('user_id', $this->editor->id)->sole();
        $this->assertTrue($timer->started_at->equalTo(now()->subHours(12)), 'at most 12 hours back');

        // the same change again: the stored result, nothing new
        $again = $this->sync([$start])->assertOk()->json('data.0');
        $this->assertSame(['applied', true], [$again['status'], $again['replayed']]);
        $this->assertSame(1, WorkTimer::count());

        // another start while it runs: conflict, with the running timer
        $other = $this->item(['title' => 'Other']);
        $conflict = $this->sync([$this->change('timer.start', ['issue_id' => $other->id, 'started_at' => now()->toIso8601ZuluString()])])->json('data.0');
        $this->assertSame(['conflict', 409, 'timer'], [$conflict['status'], $conflict['http_status'], $conflict['record_type']]);
        $this->assertSame($timer->id, $conflict['entity']['timer']['id']);
        $this->assertSame($item->id, $conflict['entity']['timer']['issue']['id']);
        $this->assertSame('A timer is already running — stop it first.', $conflict['message']);
        $this->assertSame($item->id, WorkTimer::sole()->item_id);
    }

    public function test_a_future_start_is_now_and_bad_starts_are_refused(): void
    {
        $item = $this->item();
        $results = $this->sync([
            $this->change('timer.start', ['task_id' => 5, 'started_at' => now()->toIso8601ZuluString()]),
            $this->change('timer.start', ['issue_id' => $item->id]),
            $this->change('timer.start', ['issue_id' => 999999, 'started_at' => now()->toIso8601ZuluString()]),
            $this->change('timer.start', ['issue_id' => $item->id, 'started_at' => now()->addHour()->toIso8601ZuluString()]),
        ])->assertOk()->json('data');

        $this->assertSame(['invalid', 'invalid', 'deleted', 'applied'], array_column($results, 'status'));
        $this->assertArrayHasKey('task_id', $results[0]['errors']);
        $this->assertArrayHasKey('started_at', $results[1]['errors']);
        $this->assertTrue(WorkTimer::sole()->started_at->equalTo(now()), 'never in the future');

        // a viewer may not log work on the issue
        $viewer = $this->sync([$this->change('timer.start', ['issue_id' => $item->id, 'started_at' => now()->toIso8601ZuluString()])], $this->appToken($this->viewer))->json('data.0');
        $this->assertSame(['forbidden', 403], [$viewer['status'], $viewer['http_status']]);
    }

    // ── timer.stop / timer.discard ──────────────────────────────────────────────────────────

    public function test_timer_stop_logs_once_on_the_start_day_and_clamps_the_stop(): void
    {
        $this->editor->forceFill(['timezone' => 'America/New_York'])->save();
        $item = $this->item();
        // started at 02:00 UTC = 21:00 the day before in New York
        $timer = WorkTimers::start($this->editor, $item, now()->subHours(10), 'Pairing');
        $stop = $this->change('timer.stop', ['timer_id' => $timer->id, 'stopped_at' => now()->addHours(3)->toIso8601ZuluString()]);

        $result = $this->sync([$stop])->assertOk()->json('data.0');
        $this->assertSame(['applied', 'timer'], [$result['status'], $result['record_type']]);
        $this->assertFalse($result['entity']['running']);
        $this->assertSame(0, $result['entity']['today_logged_seconds'], 'logged on the start day, not on today');

        $entry = TimeEntry::sole();
        $this->assertSame(600, $entry->minutes, 'a stop in the future is now');
        $this->assertSame('2026-11-09', $entry->spent_on->toDateString(), 'the local day of the start');
        $this->assertSame('Pairing', $entry->note, 'the description is the note');
        $this->assertSame(TimeEntry::SOURCE_TIMER, $entry->source);

        $again = $this->sync([$stop])->json('data.0');
        $this->assertSame(['applied', true], [$again['status'], $again['replayed']]);
        $gone = $this->sync([$this->change('timer.stop', ['timer_id' => $timer->id, 'stopped_at' => now()->toIso8601ZuluString()])])->json('data.0');
        $this->assertSame(['gone', 410, null], [$gone['status'], $gone['http_status'], $gone['entity']]);
        $this->assertSame(1, TimeEntry::count());
    }

    public function test_a_stop_before_the_start_logs_a_minute(): void
    {
        $timer = WorkTimers::start($this->editor, $this->item(), now()->subMinutes(30));

        $this->sync([$this->change('timer.stop', ['timer_id' => $timer->id, 'stopped_at' => now()->subHours(2)->toIso8601ZuluString()])])
            ->assertOk()->assertJsonPath('data.0.status', 'applied');

        $this->assertSame(1, TimeEntry::sole()->minutes);
    }

    public function test_a_stop_without_a_required_activity_is_invalid_with_the_picker(): void
    {
        $activity = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Programming', 'pricing_type' => 'hourly', 'rate_cents' => 12000]);
        $timer = WorkTimers::start($this->editor, $this->item(), now()->subHour());

        $result = $this->sync([$this->change('timer.stop', ['timer_id' => $timer->id, 'stopped_at' => now()->toIso8601ZuluString()])])->json('data.0');
        $this->assertSame(['invalid', 422], [$result['status'], $result['http_status']]);
        $this->assertArrayHasKey('activity_id', $result['errors']);
        $this->assertSame([['id' => $activity->id, 'name' => 'Programming']], $result['activities']);
        $this->assertArrayHasKey('default_activity_id', $result);
        $this->assertStringNotContainsString('12000', json_encode($result), 'never a rate');
        $this->assertTrue(WorkTimer::whereKey($timer->id)->exists(), 'the timer runs on');
        $this->assertSame(0, TimeEntry::count());

        $picked = $this->sync([$this->change('timer.stop', ['timer_id' => $timer->id, 'stopped_at' => now()->toIso8601ZuluString(), 'activity_id' => $activity->id])])->json('data.0');
        $this->assertSame(['applied', null, null], [$picked['status'], $picked['activities'], $picked['default_activity_id']]);
        $this->assertSame($activity->id, TimeEntry::sole()->billing_activity_id);
        $this->assertSame(3600, $picked['entity']['today_logged_seconds']);
    }

    public function test_timer_discard_logs_nothing_and_is_gone_afterwards(): void
    {
        $timer = WorkTimers::start($this->editor, $this->item());
        $discard = $this->change('timer.discard', ['timer_id' => $timer->id]);

        $this->sync([$discard])->assertOk()->assertJsonPath('data.0.status', 'applied')->assertJsonPath('data.0.entity.running', false);
        $this->assertSame(0, WorkTimer::count() + TimeEntry::count());
        $this->sync([$discard])->assertJsonPath('data.0.replayed', true);
        $this->sync([$this->change('timer.discard', ['timer_id' => $timer->id])])->assertJsonPath('data.0.status', 'gone')->assertJsonPath('data.0.http_status', 410);

        // someone else's timer is never touched
        $theirs = WorkTimers::start($this->viewer, $this->item());
        $this->sync([$this->change('timer.discard', ['timer_id' => $theirs->id])])->assertJsonPath('data.0.status', 'gone');
        $this->assertTrue(WorkTimer::whereKey($theirs->id)->exists());
    }

    // ── notifications ───────────────────────────────────────────────────────────────────────

    public function test_notification_read_marks_one_and_is_idempotent(): void
    {
        $id = $this->notify($this->editor, ['type' => 'item_assigned', 'actor_name' => 'Ada', 'item_key' => 'BRAI-1', 'item_title' => 'Fix login', 'item_url' => '/nope']);
        $this->notify($this->editor, ['type' => 'mentioned', 'actor_name' => 'Bob', 'context_title' => 'Roadmap']);
        $theirs = $this->notify($this->viewer, ['type' => 'item_assigned']);
        $read = $this->change('notification.read', ['notification_id' => $id]);

        $result = $this->sync([$read])->assertOk()->json('data.0');
        $this->assertSame(['applied', 'notification'], [$result['status'], $result['record_type']]);
        $this->assertSame($id, $result['entity']['id']);
        $this->assertTrue($result['entity']['read']);
        $this->assertSame('Ada assigned you to BRAI-1', $result['entity']['summary']);
        $this->assertNotNull($this->editor->notifications()->find($id)->read_at);

        $this->sync([$read])->assertJsonPath('data.0.replayed', true)->assertJsonPath('data.0.entity.id', $id);
        $gone = $this->sync([$this->change('notification.read', ['notification_id' => $theirs])])->json('data.0');
        $this->assertSame(['gone', 410], [$gone['status'], $gone['http_status']]);
        $this->assertNull($this->viewer->notifications()->find($theirs)->read_at);
        $this->sync([$this->change('notification.read', ['notification_id' => 'nope'])])->assertJsonPath('data.0.status', 'invalid');
    }

    public function test_read_all_only_marks_what_arrived_before(): void
    {
        $old = $this->notify($this->editor, ['type' => 'item_assigned'], now()->subHour());
        $edge = $this->notify($this->editor, ['type' => 'item_assigned'], now()->subMinutes(10));
        $later = $this->notify($this->editor, ['type' => 'item_assigned'], now());
        $read = $this->change('notifications.read_all', ['before' => now()->subMinutes(10)->toIso8601ZuluString()]);

        $result = $this->sync([$read])->assertOk()->json('data.0');
        $this->assertSame(['applied', 'unread_count', 1], [$result['status'], $result['record_type'], $result['entity']['unread_count']]);
        $this->assertNotNull($this->editor->notifications()->find($old)->read_at);
        $this->assertNotNull($this->editor->notifications()->find($edge)->read_at);
        $this->assertNull($this->editor->notifications()->find($later)->read_at, 'arrived after: stays unread');

        $this->sync([$read])->assertJsonPath('data.0.replayed', true);
        $this->sync([$this->change('notifications.read_all', [])])->assertJsonPath('data.0.status', 'invalid');
    }

    // ── issue.create ────────────────────────────────────────────────────────────────────────

    public function test_issue_create_takes_a_markdown_description_and_a_type_of_the_project(): void
    {
        $bug = ItemType::create(['project_id' => $this->project->id, 'name' => 'Bug', 'slug' => 'bug-'.Str::random(4), 'is_active' => true]);
        $foreign = ItemType::create(['project_id' => Project::factory()->create()->id, 'name' => 'Foreign', 'slug' => 'foreign-'.Str::random(4), 'is_active' => true]);
        $create = $this->change('issue.create', [
            'board_id' => $this->board->id,
            'title' => 'Written offline',
            'description' => "**Steps** for @[{$this->viewer->name}](user:{$this->viewer->id})",
            'item_type_id' => $bug->id,
            'assigned_to' => $this->editor->id,
        ]);

        $result = $this->sync([$create])->assertOk()->json('data.0');
        $this->assertSame(['applied', 'work'], [$result['status'], $result['record_type']]);
        $item = Item::where('title', 'Written offline')->sole();
        $this->assertSame($bug->id, $item->item_type_id);
        $this->assertStringContainsString('<strong>Steps</strong>', (string) $item->description);
        $this->assertStringContainsString('data-mention', (string) $item->description);
        $this->assertSame($this->editor->id, $item->reporter_id);
        $this->assertSame('issue:'.$item->id, $result['entity']['id']);

        $this->sync([$create])->assertJsonPath('data.0.replayed', true);
        $this->assertSame(1, Item::where('title', 'Written offline')->count());

        $invalid = $this->sync([$this->change('issue.create', ['board_id' => $this->board->id, 'title' => 'Typed elsewhere', 'item_type_id' => $foreign->id])])->json('data.0');
        $this->assertSame(['invalid', 422], [$invalid['status'], $invalid['http_status']]);
        $this->assertArrayHasKey('item_type_id', $invalid['errors']);
        $this->assertSame(0, Item::where('title', 'Typed elsewhere')->count());
    }

    /** Codex review, finding 5: the content limit holds for offline creates exactly as for the REST create. */
    public function test_issue_create_respects_the_content_limit_like_the_rest_create(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'content_bytes' => 1000]));
        $usage = WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'content_bytes' => 999]);

        // under the limit: both work
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Online, room left'], $this->appHeaders($this->token))->assertCreated();
        $this->assertSame('applied', $this->sync([$this->change('issue.create', ['board_id' => $this->board->id, 'title' => 'Offline, room left'])])->json('data.0.status'));

        // full: REST 402, sync the same answer per change, nothing created
        $usage->update(['content_bytes' => 1000]);
        $rest = $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Online, full'], $this->appHeaders($this->token))
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached')->json();
        $change = $this->change('issue.create', ['board_id' => $this->board->id, 'title' => 'Offline, full']);
        $result = $this->sync([$change])->assertOk()->json('data.0');

        $this->assertSame(0, Item::whereIn('title', ['Online, full', 'Offline, full'])->count(), 'nothing created over the limit');
        $this->assertSame(['limit_reached', 402], [$result['status'], $result['http_status']]);
        $this->assertSame('limit_reached', $result['code'] ?? null);
        $this->assertSame($rest['limit'], $result['limit'] ?? null);
        $this->assertSame($rest['message'], $result['message']);

        // not stored as final: once there is room again, the same change applies
        $usage->update(['content_bytes' => 10]);
        $this->assertSame('applied', $this->sync([$change])->json('data.0.status'));
        $this->assertSame(1, Item::where('title', 'Offline, full')->count());
    }

    public function test_issue_create_without_type_or_column_uses_the_defaults(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id]);

        $this->sync([$this->change('issue.create', ['board_id' => $board->id, 'title' => 'Plain'])])->assertJsonPath('data.0.status', 'applied');

        $item = Item::where('title', 'Plain')->sole();
        $this->assertSame($board->columns()->value('id'), $item->column_id);
        $this->assertNull($item->description);
    }

    // ── scope ───────────────────────────────────────────────────────────────────────────────

    public function test_a_tv_token_cannot_sync(): void
    {
        $tv = $this->appDevice($this->editor, ['client' => 'tv', 'platform' => 'android_tv', 'name' => 'Living room TV']);
        $timer = WorkTimers::start($this->editor, $this->item());

        $this->sync([$this->change('timer.discard', ['timer_id' => $timer->id])], $this->appToken($this->editor, $tv))
            ->assertForbidden()
            ->assertJsonPath('code', AppApiErrors::CLIENT_NOT_ALLOWED);
        $this->assertTrue(WorkTimer::whereKey($timer->id)->exists());
    }
}
