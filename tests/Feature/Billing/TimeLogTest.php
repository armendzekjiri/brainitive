<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingActivity;
use App\Models\BillingActivityDefault;
use App\Models\ItemType;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkTimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Logging time with an activity (v1.38.0, BRAI-99): "Log work" and the focus timer write one
 * TimeEntry through Item::logWork(); the activity is required once the workspace has any, and
 * members never see a price.
 */
class TimeLogTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activity(string $name = 'Programming', array $attributes = [], ?Workspace $workspace = null): BillingActivity
    {
        return BillingActivity::create($attributes + [
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'name' => $name,
            'pricing_type' => BillingActivity::HOURLY,
            'rate_cents' => 12000,
        ]);
    }

    private function logWork($item, array $data)
    {
        return $this->actingAs($this->editor)->post($this->itemRoute('boards.items.work-logs.store', $item), $data);
    }

    public function test_log_work_creates_a_time_entry_with_the_activity(): void
    {
        $activity = $this->activity();
        $item = $this->item(['title' => 'Checkout bug', 'time_spent' => 30]); // 30m from before the ledger

        $this->logWork($item, ['time' => '1h 30m', 'note' => 'Debugging', 'activity_id' => $activity->id, 'spent_on' => '2026-10-20'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Work logged: 1h 30m.');

        $entry = TimeEntry::sole();
        $this->assertSame([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'item_id' => $item->id,
            'user_id' => $this->editor->id, 'billing_activity_id' => $activity->id, 'minutes' => 90, 'note' => 'Debugging',
            'billable' => true, 'source' => TimeEntry::SOURCE_MANUAL, 'item_key' => $item->key, 'item_title' => 'Checkout bug',
        ], collect($entry->only(['workspace_id', 'project_id', 'item_id', 'user_id', 'billing_activity_id', 'minutes', 'note', 'billable', 'source', 'item_key', 'item_title']))->map(fn ($v) => is_numeric($v) && ! is_bool($v) ? (int) $v : $v)->all());
        $this->assertSame('2026-10-20', $entry->spent_on->toDateString());
        $this->assertSame(120, $item->fresh()->time_spent); // incremented once, by the entry

        $log = ActivityLog::where('action', 'work_logged')->sole();
        $this->assertSame(['minutes' => 90, 'total_minutes' => 120, 'note' => 'Debugging', 'time_entry_id' => $entry->id, 'activity' => 'Programming'], $log->metadata);
    }

    public function test_the_activity_is_required_only_when_the_workspace_has_activities(): void
    {
        $item = $this->item();

        $this->logWork($item, ['time' => '45m'])->assertSessionHasNoErrors();
        $this->assertNull(TimeEntry::sole()->billing_activity_id); // workspaces that don't bill aren't blocked

        $this->activity();
        $this->logWork($item, ['time' => '45m'])->assertSessionHasErrors('activity_id');
        $this->assertSame([1, 45], [TimeEntry::count(), $item->fresh()->time_spent]);

        // archived activities don't count: back to optional
        BillingActivity::query()->update(['archived_at' => now()]);
        $this->logWork($item, ['time' => '15m'])->assertSessionHasNoErrors();
        $this->assertSame(60, $item->fresh()->time_spent);
    }

    public function test_archived_and_foreign_activities_are_rejected(): void
    {
        $this->activity();
        $archived = $this->activity('Old', ['archived_at' => now()]);
        $foreign = $this->activity('Elsewhere', [], Workspace::factory()->create(['owner_id' => User::factory()->create()->id]));
        $item = $this->item();

        foreach ([$archived->id, $foreign->id, 'abc', 999999] as $id) {
            $this->logWork($item, ['time' => '1h', 'activity_id' => $id])->assertSessionHasErrors('activity_id');
        }
        $this->assertSame([0, 0], [TimeEntry::count(), $item->fresh()->time_spent]);
    }

    public function test_billable_defaults_from_the_activity_and_viewers_cannot_log(): void
    {
        $internal = $this->activity('Internal meeting', ['billable' => false]);
        $item = $this->item();

        $this->logWork($item, ['time' => '30m', 'activity_id' => $internal->id])->assertSessionHasNoErrors();
        $this->assertFalse(TimeEntry::sole()->billable);

        $this->actingAs($this->viewer)->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '1h', 'activity_id' => $internal->id])->assertForbidden();
        $this->assertSame(1, TimeEntry::count());
    }

    public function test_the_day_defaults_to_today_in_the_users_zone_and_cannot_be_in_the_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 23:30', 'UTC'));
        $this->editor->forceFill(['timezone' => 'Europe/Berlin'])->save();
        $item = $this->item();

        $this->logWork($item, ['time' => '1h'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-06', TimeEntry::sole()->spent_on->toDateString()); // already the 6th in Berlin

        $this->logWork($item, ['time' => '1h', 'spent_on' => '2026-11-20'])->assertSessionHasErrors('spent_on');
        $this->logWork($item, ['time' => '1h', 'spent_on' => '6.11.2026'])->assertSessionHasErrors('spent_on');
    }

    public function test_the_type_default_then_the_last_used_activity_is_preselected(): void
    {
        $programming = $this->activity('Programming');
        $testing = $this->activity('Testing', ['rate_cents' => 4500]);
        $bugType = ItemType::create(['project_id' => $this->project->id, 'name' => 'Bug', 'slug' => 'bug-'.uniqid(), 'icon' => 'bug', 'color' => '#f00']);
        $bug = $this->item(['item_type_id' => $bugType->id]);
        $task = $this->item();
        $url = fn ($item) => route('timesheet.activities', [$this->workspace->slug, 'item_id' => $item->id]);

        $this->actingAs($this->editor)->getJson($url($bug))->assertOk()->assertJsonPath('default_activity_id', null)->assertJsonPath('required', true);

        BillingActivityDefault::create(['workspace_id' => $this->workspace->id, 'item_type_id' => $bugType->id, 'billing_activity_id' => $testing->id]);
        $this->getJson($url($bug))->assertJsonPath('default_activity_id', $testing->id);

        // no type default: the activity this user used last
        $this->logWork($task, ['time' => '1h', 'activity_id' => $programming->id]);
        $this->getJson($url($task))->assertJsonPath('default_activity_id', $programming->id);
        $this->getJson($url($bug))->assertJsonPath('default_activity_id', $testing->id);

        // an archived default is skipped
        $testing->update(['archived_at' => now()]);
        $this->getJson($url($bug))->assertJsonPath('default_activity_id', $programming->id);
    }

    public function test_members_never_receive_rates(): void
    {
        $this->activity('Programming', ['rate_cents' => 12345]);
        $this->activity('SSM setup', ['pricing_type' => BillingActivity::FIXED, 'rate_cents' => 45678]);
        $item = $this->item();

        $json = $this->actingAs($this->editor)->getJson(route('timesheet.activities', [$this->workspace->slug, 'item_id' => $item->id]))
            ->assertOk()
            ->assertJsonCount(2, 'activities')
            ->assertJsonPath('activities.1.pricing_label', 'Fixed price')
            ->getContent();
        $this->assertStringNotContainsString('rate', $json);
        $this->assertStringNotContainsString('12345', $json);
        $this->assertStringNotContainsString('45678', $json);

        $page = $this->get(route('timesheet.index', $this->workspace->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('rate_cents', $page);
        $this->assertStringNotContainsString('12345', $page);

        $this->actingAs($this->outsider)->getJson(route('timesheet.activities', $this->workspace->slug))->assertForbidden();
    }

    public function test_stopping_the_timer_asks_for_the_activity_and_keeps_the_timer_until_it_is_valid(): void
    {
        $activity = $this->activity();
        $item = $this->item();
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $item->id]);
        $id = WorkTimer::sole()->id;

        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('activeTimer.item.workspace', $this->workspace->slug));

        $this->post(route('work-timer.stop'), ['timer_id' => $id, 'time' => '25m'])->assertSessionHasErrors('activity_id');
        $this->assertSame([1, 0], [WorkTimer::count(), TimeEntry::count()]); // still running

        $this->post(route('work-timer.stop'), ['timer_id' => $id, 'time' => '25m', 'activity_id' => $activity->id, 'note' => 'Focus'])->assertSessionHasNoErrors();
        $entry = TimeEntry::sole();
        $this->assertSame([TimeEntry::SOURCE_TIMER, $activity->id, 25], [$entry->source, (int) $entry->billing_activity_id, $entry->minutes]);
        $this->assertSame([0, 25], [WorkTimer::count(), $item->fresh()->time_spent]);
    }
}
