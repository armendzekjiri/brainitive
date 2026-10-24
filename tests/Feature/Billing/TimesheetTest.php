<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingActivity;
use App\Models\BillingReport;
use App\Models\Board;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * My timesheet (v1.38.0, BRAI-99): your own week of entries — add, edit, delete — with
 * items.time_spent kept right, billed entries locked, and nobody else's entries in reach.
 */
class TimesheetTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private BillingActivity $programming;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00', 'UTC')); // a Thursday, ISO week 43
        $this->programming = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Programming', 'pricing_type' => 'hourly', 'rate_cents' => 12000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $name, array $params = []): string
    {
        return route($name, [$this->workspace->slug, ...$params]);
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

    private function payload(array $data = []): array
    {
        return $data + [
            'project_id' => $this->project->id,
            'item_id' => null,
            'spent_on' => '2026-10-21',
            'time' => '1h',
            'activity_id' => $this->programming->id,
            'note' => null,
        ];
    }

    public function test_the_week_shows_only_your_own_entries_with_the_issue(): void
    {
        $item = $this->item(['title' => 'Checkout bug']);
        $mine = $this->entry(['item_id' => $item->id, 'item_key' => $item->key, 'item_title' => 'Old title', 'minutes' => 90, 'note' => 'Fixed it']);
        $this->entry(['user_id' => $this->viewer->id]);              // someone else's
        $this->entry(['spent_on' => '2026-10-12']);                  // another week
        $item->update(['title' => 'Checkout bug (renamed)']);

        $this->actingAs($this->editor)->get($this->url('timesheet.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Timesheet/Index')
                ->where('week.start', '2026-10-19')
                ->where('week.end', '2026-10-25')
                ->where('week.number', 43)
                ->has('entries', 1)
                ->where('entries.0.id', $mine->id)
                ->where('entries.0.minutes', 90)
                ->where('entries.0.item.title', 'Checkout bug (renamed)') // live title while the issue exists
                ->where('entries.0.item.url', $this->itemRoute('boards.items.show', $item))
                ->where('entries.0.activity.name', 'Programming')
                ->missing('entries.0.activity.rate_cents')
                ->where('entries.0.locked', false)
                ->where('activityRequired', true)
                ->has('projects', 1));

        $this->get($this->url('timesheet.index', ['week' => '2026-10-14']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('week.start', '2026-10-12')->has('entries', 1));
        $this->get($this->url('timesheet.index', ['week' => 'nonsense']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('week.start', '2026-10-19'));

        $this->actingAs($this->outsider)->get($this->url('timesheet.index'))->assertForbidden();
    }

    public function test_adding_entries_with_and_without_an_issue(): void
    {
        $item = $this->item();

        $this->actingAs($this->editor)->post($this->url('timesheet.entries.store'), $this->payload(['item_id' => $item->id, 'time' => '1h 30m', 'note' => 'Review']))
            ->assertSessionHasNoErrors();
        $this->post($this->url('timesheet.entries.store'), $this->payload(['time' => '2h', 'note' => 'Planning']))->assertSessionHasNoErrors();

        $this->assertSame(90, $item->fresh()->time_spent);
        $this->assertSame(1, ActivityLog::where('action', 'work_logged')->count()); // only the issue entry has history
        $withoutIssue = TimeEntry::whereNull('item_id')->sole();
        $this->assertSame([120, 'Planning', TimeEntry::SOURCE_MANUAL], [$withoutIssue->minutes, $withoutIssue->note, $withoutIssue->source]);

        // validation: activity, duration, day, and only projects / issues you can log on
        $this->post($this->url('timesheet.entries.store'), $this->payload(['activity_id' => null]))->assertSessionHasErrors('activity_id');
        $this->post($this->url('timesheet.entries.store'), $this->payload(['time' => 'soon']))->assertSessionHasErrors('time');
        $this->post($this->url('timesheet.entries.store'), $this->payload(['spent_on' => null]))->assertSessionHasErrors('spent_on');

        $readOnly = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->workspace->owner_id]);
        $readOnly->users()->attach($this->editor->id, ['role' => 'viewer']);
        $this->post($this->url('timesheet.entries.store'), $this->payload(['project_id' => $readOnly->id]))->assertSessionHasErrors('project_id');

        $otherBoard = Board::factory()->create(['project_id' => $readOnly->id, 'created_by' => $this->workspace->owner_id]);
        $foreignItem = $this->item(['board_id' => $otherBoard->id]);
        $this->post($this->url('timesheet.entries.store'), $this->payload(['item_id' => $foreignItem->id]))->assertSessionHasErrors('item_id');

        $this->assertSame(2, TimeEntry::count());
    }

    public function test_editing_moving_and_deleting_keep_the_issue_totals_right(): void
    {
        $a = $this->item(['time_spent' => 15]); // 15m from before the ledger
        $b = $this->item();
        $this->actingAs($this->editor)->post($this->url('timesheet.entries.store'), $this->payload(['item_id' => $a->id, 'time' => '1h']));
        $entry = TimeEntry::sole();
        $this->assertSame(75, $a->fresh()->time_spent);

        $this->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['item_id' => $a->id, 'time' => '2h', 'note' => 'More']))->assertSessionHasNoErrors();
        $this->assertSame(135, $a->fresh()->time_spent);

        $this->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['item_id' => $b->id, 'time' => '2h']))->assertSessionHasNoErrors();
        $this->assertSame([15, 120], [$a->fresh()->time_spent, $b->fresh()->time_spent]);
        $this->assertSame($b->key, $entry->fresh()->item_key);

        $this->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['item_id' => null, 'time' => '2h']))->assertSessionHasNoErrors();
        $this->assertSame([0, null], [$b->fresh()->time_spent, $entry->fresh()->item_key]);

        $this->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['item_id' => $b->id, 'time' => '45m']))->assertSessionHasNoErrors();
        $this->delete($this->url('timesheet.entries.destroy', [$entry->id]))->assertSessionHasNoErrors();
        $this->assertSame([15, 0, 0], [$a->fresh()->time_spent, $b->fresh()->time_spent, TimeEntry::count()]);

        $this->assertSame(
            ['work_logged', 'work_log_updated', 'work_log_removed'],
            ActivityLog::where('loggable_id', $a->id)->where('action', 'like', 'work_%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_locked_entries_cannot_change(): void
    {
        $item = $this->item();
        $this->actingAs($this->editor)->post($this->url('timesheet.entries.store'), $this->payload(['item_id' => $item->id]));
        $entry = TimeEntry::sole();
        $report = BillingReport::create(['workspace_id' => $this->workspace->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized']);
        $entry->forceFill(['billing_report_id' => $report->id])->save();

        $this->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['item_id' => $item->id, 'time' => '3h']))->assertSessionHasErrors('entry');
        $this->delete($this->url('timesheet.entries.destroy', [$entry->id]))->assertSessionHasErrors('entry');
        $this->actingAs($this->workspace->owner)->delete($this->url('timesheet.entries.destroy', [$entry->id]))->assertSessionHasErrors('entry');

        $this->assertSame([60, 1], [$item->fresh()->time_spent, TimeEntry::count()]);
        $this->actingAs($this->editor)->get($this->url('timesheet.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('entries.0.locked', true));
    }

    public function test_other_peoples_entries_are_refused_but_workspace_admins_may_change_them(): void
    {
        $entry = $this->entry(['user_id' => $this->viewer->id]);

        $this->actingAs($this->editor)->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['time' => '5h']))->assertForbidden();
        $this->actingAs($this->editor)->delete($this->url('timesheet.entries.destroy', [$entry->id]))->assertForbidden();
        $this->assertSame(60, $entry->fresh()->minutes);

        $this->actingAs($this->workspace->owner)->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['time' => '30m']))->assertSessionHasNoErrors();
        $this->assertSame([30, $this->viewer->id], [$entry->fresh()->minutes, (int) $entry->fresh()->user_id]);

        // an entry of another workspace is not found through this one
        $elsewhere = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $elsewhere->id]);
        $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $elsewhere->id]);
        $foreign = $this->entry(['workspace_id' => $otherWorkspace->id, 'project_id' => $otherProject->id, 'user_id' => $this->editor->id, 'billing_activity_id' => null]);
        $this->actingAs($this->editor)->delete($this->url('timesheet.entries.destroy', [$foreign->id]))->assertNotFound();
    }

    public function test_legacy_entries_keep_their_minutes_and_archived_activities_stay_on_their_entries(): void
    {
        $item = $this->item(['time_spent' => 45]);
        $legacy = $this->entry(['item_id' => $item->id, 'minutes' => 45, 'source' => TimeEntry::SOURCE_LEGACY, 'billing_activity_id' => null]);

        $this->actingAs($this->editor)->put($this->url('timesheet.entries.update', [$legacy->id]), $this->payload(['item_id' => $item->id, 'time' => '2h']))->assertSessionHasErrors('entry');
        $this->put($this->url('timesheet.entries.update', [$legacy->id]), $this->payload(['item_id' => $item->id, 'time' => '45m', 'note' => 'Old work']))->assertSessionHasNoErrors();
        $this->delete($this->url('timesheet.entries.destroy', [$legacy->id]))->assertSessionHasErrors('entry');
        $this->assertSame(['Old work', $this->programming->id, 45], [$legacy->fresh()->note, (int) $legacy->fresh()->billing_activity_id, $item->fresh()->time_spent]);

        // archived after use: the entry keeps it, nobody can pick it again
        $old = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Support', 'pricing_type' => 'hourly', 'rate_cents' => 5000]);
        $entry = $this->entry(['billing_activity_id' => $old->id]);
        $old->update(['archived_at' => now()]);
        $this->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['activity_id' => $old->id, 'time' => '20m']))->assertSessionHasNoErrors();
        $this->assertSame(20, $entry->fresh()->minutes);
        $this->post($this->url('timesheet.entries.store'), $this->payload(['activity_id' => $old->id]))->assertSessionHasErrors('activity_id');
    }

    public function test_former_members_cannot_change_their_entries_any_more(): void
    {
        $entry = $this->entry();
        $this->workspace->users()->detach($this->editor->id);
        $this->project->users()->detach($this->editor->id);

        $this->actingAs($this->editor)->put($this->url('timesheet.entries.update', [$entry->id]), $this->payload(['time' => '5h']))->assertForbidden();
        $this->delete($this->url('timesheet.entries.destroy', [$entry->id]))->assertForbidden();
        $this->assertSame(60, $entry->fresh()->minutes);
    }

    public function test_issues_you_can_no_longer_open_show_the_snapshot_and_trashed_issues_keep_their_total(): void
    {
        $item = $this->item(['title' => 'Secret now']);
        $this->entry(['item_id' => $item->id, 'item_key' => $item->key, 'item_title' => 'Title when logged']);

        // the issue moves to a project the editor can't open
        $hidden = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->workspace->owner_id]);
        $hiddenBoard = Board::factory()->create(['project_id' => $hidden->id, 'created_by' => $this->workspace->owner_id]);
        $item->forceFill(['board_id' => $hiddenBoard->id, 'project_id' => $hidden->id])->saveQuietly();

        $this->actingAs($this->editor)->get($this->url('timesheet.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('entries.0.item.title', 'Title when logged')
                ->where('entries.0.item.url', null));

        // an entry on a trashed issue still moves its total (right again after a restore)
        $trashed = $this->item(['time_spent' => 60]);
        $onTrashed = $this->entry(['item_id' => $trashed->id, 'minutes' => 30]);
        $this->assertSame(90, $trashed->fresh()->time_spent);
        $trashed->delete();
        $onTrashed->update(['minutes' => 45]);
        $onTrashed->delete();
        $this->assertSame(60, $trashed->fresh()->time_spent);
    }

    public function test_the_issue_picker_lists_issues_of_projects_you_can_log_on(): void
    {
        $this->item(['title' => 'Checkout bug']);
        $this->item(['title' => 'Pricing copy']);

        $this->actingAs($this->editor)->getJson($this->url('timesheet.items', ['project_id' => $this->project->id, 'q' => 'check']))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.title', 'Checkout bug');

        $this->actingAs($this->viewer)->getJson($this->url('timesheet.items', ['project_id' => $this->project->id]))->assertUnprocessable();
    }

    public function test_only_workspace_admins_choose_billable_members_get_the_activity_default(): void
    {
        $internal = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Internal', 'pricing_type' => 'hourly', 'rate_cents' => 0, 'billable' => false]);

        // a member's billable choice is ignored: the activity decides (v1.39.0)
        $this->actingAs($this->editor)->post($this->url('timesheet.entries.store'), $this->payload(['billable' => false]))->assertSessionHasNoErrors();
        $this->post($this->url('timesheet.entries.store'), $this->payload(['activity_id' => $internal->id, 'billable' => true]))->assertSessionHasNoErrors();
        [$programming, $meeting] = TimeEntry::orderBy('id')->get()->all();
        $this->assertTrue($programming->billable);
        $this->assertFalse($meeting->billable);

        // on update the member keeps the flag unless the activity changes
        $programming->update(['billable' => false]); // e.g. set by an admin
        $this->put($this->url('timesheet.entries.update', [$programming->id]), $this->payload(['billable' => true, 'note' => 'x']))->assertSessionHasNoErrors();
        $this->assertFalse($programming->fresh()->billable);
        $this->put($this->url('timesheet.entries.update', [$meeting->id]), $this->payload(['billable' => false]))->assertSessionHasNoErrors();
        $this->assertTrue($meeting->fresh()->billable); // switched to Programming → its default

        // the workspace admin can override it
        $admin = User::find($this->workspace->owner_id);
        $this->actingAs($admin)->post($this->url('timesheet.entries.store'), $this->payload(['billable' => false]))->assertSessionHasNoErrors();
        $this->assertFalse(TimeEntry::latest('id')->first()->billable);
        $own = TimeEntry::latest('id')->first();
        $this->put($this->url('timesheet.entries.update', [$own->id]), $this->payload(['billable' => true]))->assertSessionHasNoErrors();
        $this->assertTrue($own->fresh()->billable);
    }
}
