<?php

namespace Tests\Feature\Workload;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkloadAllocation;
use App\Models\WorkloadCapacityOverride;
use App\Support\Workload\WorkloadFormat;
use App\Support\Workload\WorkloadPlanner;
use App\Support\Workload\WorkloadWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Workload planning (BRAI-75): capacity vs explicit allocations per person and week.
 */
class WorkloadTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $planner;

    /** Mondays around "now" (Wednesday 2026-11-04). */
    private const PREV = '2026-10-26';

    private const WEEK = '2026-11-02';

    private const NEXT = '2026-11-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->planner = $this->projectMember('admin');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));
    }

    private function r(string $name, array $query = [], ?Project $project = null): string
    {
        return route($name, [$this->workspace->slug, ($project ?? $this->project)->slug] + $query);
    }

    /** @return array<string, mixed> */
    private function props(?User $user = null, array $query = []): array
    {
        $props = [];
        $this->actingAs($user ?? $this->planner)->get($this->r('workload.index', $query))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $page->component('Workload/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** @return array<string, mixed>|null */
    private function cell(array $props, User $member, string $week): ?array
    {
        $row = collect($props['members'])->firstWhere('id', $member->id);

        return $row ? collect($row['cells'])->firstWhere('week', $week) : null;
    }

    private function capacity(User $member, int|float|null $minutes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->planner)->put($this->r('workload.capacity.update'), ['user_id' => $member->id, 'weekly_minutes' => $minutes]);
    }

    private function override(User $member, string $week, ?int $minutes, int $weeks = 1): TestResponse
    {
        return $this->actingAs($this->planner)->put($this->r('workload.overrides.update'), ['user_id' => $member->id, 'week_start' => $week, 'weeks' => $weeks, 'minutes' => $minutes]);
    }

    private function plan(Item $item, User $member, string $week, int $minutes, ?int $expected = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->planner)->put($this->r('workload.allocations.update'), [
            'item_id' => $item->id, 'user_id' => $member->id, 'week_start' => $week, 'minutes' => $minutes, 'expected_minutes' => $expected,
        ]);
    }

    public function test_twenty_hours_capacity_with_twenty_five_planned_is_five_hours_over(): void
    {
        $this->capacity($this->editor, 20 * 60)->assertSessionHasNoErrors();
        $a = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 15 * 60]);
        $b = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 10 * 60]);
        $this->plan($a, $this->editor, self::WEEK, 15 * 60)->assertSessionHasNoErrors();
        $this->plan($b, $this->editor, self::WEEK, 10 * 60)->assertSessionHasNoErrors();

        $cell = $this->cell($this->props(), $this->editor, self::WEEK);
        $this->assertSame(20 * 60, $cell['capacity_minutes']);
        $this->assertSame(25 * 60, $cell['allocated_minutes']);
        $this->assertSame(5 * 60, $cell['over_minutes']);
        $this->assertSame('over', $cell['state']);
        $this->assertSame(125, $cell['load_percent']);
        $this->assertSame('5h', WorkloadFormat::hours($cell['over_minutes']));
        $this->assertCount(2, $cell['allocations']);

        // the following week still has the full default capacity free
        $next = $this->cell($this->props(), $this->editor, self::NEXT);
        $this->assertSame(['ok', 0, 1200], [$next['state'], $next['allocated_minutes'], $next['free_minutes']]);
    }

    public function test_zero_capacity_unset_capacity_and_unknown_estimates_are_distinct(): void
    {
        $this->capacity($this->editor, 40 * 60);
        $this->override($this->editor, self::WEEK, 0)->assertSessionHasNoErrors(); // on leave
        $this->override($this->editor, self::NEXT, 0);
        $unestimated = $this->item(['assigned_to' => $this->editor->id]);
        $this->plan($unestimated, $this->editor, self::NEXT, 3 * 60)->assertSessionHasNoErrors();

        $props = $this->props();
        $leave = $this->cell($props, $this->editor, self::WEEK);
        $this->assertSame(['unavailable', 0, null, 'override'], [$leave['state'], $leave['capacity_minutes'], $leave['load_percent'], $leave['capacity_source']]);

        // planned while unavailable: all of it is over, and there is no percentage
        $over = $this->cell($props, $this->editor, self::NEXT);
        $this->assertSame(['over', 180, null], [$over['state'], $over['over_minutes'], $over['load_percent']]);
        // unestimated work is counted (the planner chose 3h) but reported separately
        $this->assertSame(180, $over['unestimated_minutes']);
        $this->assertNull($over['allocations'][0]['item']['remaining_minutes']);

        // nobody declared the viewer's capacity: unset, not zero
        $unset = $this->cell($props, $this->viewer, self::WEEK);
        $this->assertSame(['unset', null, null, 0, null], [$unset['state'], $unset['capacity_minutes'], $unset['load_percent'], $unset['over_minutes'], $unset['free_minutes']]);

        // open work: unknown effort is a count, never 0h
        $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 300, 'time_spent' => 60]);
        $work = collect($this->props()['members'])->firstWhere('id', $this->editor->id)['work'];
        $this->assertSame(['estimated_items' => 1, 'remaining_minutes' => 240, 'unplanned_minutes' => 240, 'unestimated_items' => 1], $work);

        // clearing the override brings the default back; clearing the default = unset
        $this->override($this->editor, self::WEEK, null);
        $this->assertSame('default', $this->cell($this->props(), $this->editor, self::WEEK)['capacity_source']);
        $this->capacity($this->editor, null);
        $this->assertSame('unset', $this->cell($this->props(), $this->editor, self::WEEK)['state']);
    }

    public function test_an_override_covers_several_weeks_at_once(): void
    {
        $this->override($this->editor, self::WEEK, 16 * 60, 3)->assertSessionHasNoErrors();
        $this->assertSame([self::WEEK, self::NEXT, '2026-11-16'], WorkloadCapacityOverride::orderBy('week_start')->get()->map(fn ($o) => $o->week_start->toDateString())->all());

        $this->override($this->editor, self::NEXT, 0, 27)->assertSessionHasErrors('weeks');
        $this->override($this->editor, '2026-11-03', 0)->assertSessionHasErrors('week_start'); // a Tuesday
        $this->override($this->editor, self::NEXT, null, 2);
        $this->assertSame([self::WEEK], WorkloadCapacityOverride::pluck('week_start')->map->toDateString()->all());
    }

    public function test_an_issue_and_its_subtasks_are_never_both_planned(): void
    {
        $parent = $this->item(['original_estimate' => 600]);
        $child = $this->item(['parent_id' => $parent->id, 'original_estimate' => 240]);
        $grandchild = $this->item(['parent_id' => $child->id, 'original_estimate' => 120]);
        $sibling = $this->item(['parent_id' => $parent->id]);

        $this->plan($child, $this->editor, self::WEEK, 240)->assertSessionHasNoErrors();
        $this->plan($parent, $this->editor, self::NEXT, 600)->assertSessionHasErrors('item_id');
        $this->plan($grandchild, $this->editor, self::WEEK, 60)->assertSessionHasErrors('item_id');
        // a sibling subtask is separate work
        $this->plan($sibling, $this->editor, self::WEEK, 60)->assertSessionHasNoErrors();
        // removing the subtask's plan frees the parent... once the sibling's plan is gone too
        $this->plan($child, $this->editor, self::WEEK, 0, 240)->assertSessionHasNoErrors();
        $this->plan($parent, $this->editor, self::NEXT, 600)->assertSessionHasErrors('item_id');
        $this->plan($sibling, $this->editor, self::WEEK, 0, 60);
        $this->plan($parent, $this->editor, self::NEXT, 600)->assertSessionHasNoErrors();

        // open work: a parent whose open subtasks carry estimates is a container (not counted twice)
        $parent->update(['assigned_to' => $this->editor->id]);
        $child->update(['assigned_to' => $this->editor->id]);
        $grandchild->update(['original_estimate' => null]);
        $work = collect($this->props()['members'])->firstWhere('id', $this->editor->id)['work'];
        $this->assertSame(1, $work['estimated_items']);
        $this->assertSame(240, $work['remaining_minutes']);
    }

    public function test_remaining_estimate_suggestion_and_work_logs_subtract_once(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 8 * 60, 'time_spent' => 2 * 60, 'title' => 'Checkout redesign']);

        $found = $this->actingAs($this->planner)->getJson($this->r('workload.items', ['user_id' => $this->editor->id, 'q' => 'checkout']))->assertOk()->json();
        $this->assertSame([6 * 60, 8 * 60, 2 * 60, 0], [$found[0]['remaining_minutes'], $found[0]['estimate_minutes'], $found[0]['logged_minutes'], $found[0]['open_planned_minutes']]);

        // planned work in a past week is history: it doesn't eat into the remaining estimate again
        $this->plan($item, $this->editor, self::PREV, 2 * 60)->assertSessionHasNoErrors();
        $this->plan($item, $this->editor, self::WEEK, 6 * 60)->assertSessionHasNoErrors();
        $this->assertSame(6 * 60, $this->actingAs($this->planner)->getJson($this->r('workload.items', ['q' => 'checkout']))->json('0.open_planned_minutes'));
        $props = $this->props();
        $this->assertSame([], $props['review']);
        $this->assertSame(0, collect($props['members'])->firstWhere('id', $this->editor->id)['work']['unplanned_minutes']);

        // more work logged: remaining drops to 5h, the 6h plan stays as it is and is flagged
        $item->update(['time_spent' => 3 * 60]);
        $review = $this->props()['review'];
        $this->assertSame(['over_remaining'], array_column($review, 'kind'));
        $this->assertStringContainsString('6h planned from this week on, but only 5h remaining', $review[0]['message']);
        $this->assertSame(6 * 60, WorkloadAllocation::where('week_start', self::WEEK)->value('minutes'));

        // logged more than estimated: remaining is 0, never negative
        $item->update(['time_spent' => 9 * 60]);
        $this->assertSame(0, WorkloadPlanner::remaining($item->fresh()));
    }

    public function test_week_rules_at_year_and_dst_boundaries(): void
    {
        // ISO weeks: 2026 has 53, and the week of New Year belongs to the year of its Thursday
        $this->assertSame('2026-12-28', WorkloadWeeks::monday('2027-01-03'));
        $this->assertSame(['week' => 53, 'year' => 2026], array_intersect_key(WorkloadWeeks::describe('2026-12-28'), ['week' => 1, 'year' => 1]));
        $this->assertSame('2027-01-03', WorkloadWeeks::describe('2026-12-28')['end']);
        $this->assertSame('2027-01-04', WorkloadWeeks::add('2026-12-28', 1));
        $this->assertSame(['week' => 1, 'year' => 2027], array_intersect_key(WorkloadWeeks::describe('2027-01-04'), ['week' => 1, 'year' => 1]));
        $this->assertSame('2024-12-30', WorkloadWeeks::monday('2025-01-01'));
        $this->assertSame(1, WorkloadWeeks::describe('2024-12-30')['week']); // W1 2025 starts in 2024
        $this->assertSame(2025, WorkloadWeeks::describe('2024-12-30')['year']);

        // DST (EU: 29 March and 25 October 2026): whole weeks, no hour shifts
        $this->assertSame(['2026-10-19', '2026-10-26', '2026-11-02'], WorkloadWeeks::range('2026-10-19', 3));
        $this->assertSame(['2026-03-23', '2026-03-30'], WorkloadWeeks::range('2026-03-23', 2));
        $this->assertSame('2026-10-26', WorkloadWeeks::add('2026-11-02', -1));

        // "this week" is the viewer's own: Sunday 23:30 UTC is already Monday in Berlin
        $berlin = User::factory()->make(['timezone' => 'Europe/Berlin']);
        $utc = User::factory()->make(['timezone' => null]);
        $sunday = CarbonImmutable::parse('2026-10-25 23:30:00', 'UTC');
        $this->assertSame('2026-10-26', WorkloadWeeks::current($berlin, $sunday));
        $this->assertSame('2026-10-19', WorkloadWeeks::current($utc, $sunday));
        $this->assertSame('2026-03-30', WorkloadWeeks::current($berlin, CarbonImmutable::parse('2026-03-29 22:30:00', 'UTC'))); // 00:30 CEST

        $this->assertTrue(WorkloadWeeks::isMonday('2026-12-28'));
        foreach (['2026-12-29', '2026-02-30', '28.12.2026', '', null, 20261228] as $bad) {
            $this->assertFalse(WorkloadWeeks::isMonday($bad));
        }

        // the page: any day opens its week; planning across New Year lands in the right weeks
        $this->capacity($this->editor, 40 * 60);
        $item = $this->item(['original_estimate' => 20 * 60]);
        $this->plan($item, $this->editor, '2026-12-28', 8 * 60)->assertSessionHasNoErrors();
        $this->plan($item, $this->editor, '2027-01-04', 12 * 60)->assertSessionHasNoErrors();
        $this->plan($item, $this->editor, '2027-01-01', 60)->assertSessionHasErrors('week_start');
        $props = $this->props(null, ['from' => '2026-12-31', 'weeks' => 4]);
        $this->assertSame('2026-12-28', $props['from']);
        $this->assertSame(['W53', 'W1', 'W2', 'W3'], array_column($props['weeks'], 'label'));
        $this->assertSame([2026, 2027, 2027, 2027], array_column($props['weeks'], 'year'));
        $this->assertSame(480, $this->cell($props, $this->editor, '2026-12-28')['allocated_minutes']);
        $this->assertSame(720, $this->cell($props, $this->editor, '2027-01-04')['allocated_minutes']);
        $this->assertSame(['2026-11-30', '2027-01-25'], [$props['previousFrom'], $props['nextFrom']]);

        // nonsense parameters fall back to this week and the default span
        $props = $this->props(null, ['from' => '2026-02-30', 'weeks' => 99]);
        $this->assertSame([self::WEEK, 6], [$props['from'], $props['span']]);
        $this->assertTrue($props['weeks'][0]['is_current']);
    }

    public function test_members_view_planners_change_outsiders_see_nothing(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id]);

        foreach ([$this->viewer, $this->editor] as $member) {
            $props = $this->props($member);
            $this->assertFalse($props['canPlan']);
            $this->assertSame('project', $props['scope']);
            $this->capacity($this->editor, 600, $member)->assertForbidden();
            $this->plan($item, $member, self::WEEK, 60, null, $member)->assertForbidden();
            $this->actingAs($member)->put($this->r('workload.overrides.update'), ['user_id' => $member->id, 'week_start' => self::WEEK, 'minutes' => 0])->assertForbidden();
            $this->actingAs($member)->getJson($this->r('workload.items'))->assertForbidden();
        }
        $this->actingAs($this->outsider)->get($this->r('workload.index'))->assertForbidden();
        $this->actingAs($this->outsider)->put($this->r('workload.capacity.update'), ['user_id' => $this->outsider->id, 'weekly_minutes' => 60])->assertForbidden();

        // project admins and workspace admins plan
        $this->assertTrue($this->props($this->planner)['canPlan']);
        $workspaceAdmin = User::find($this->workspace->owner_id);
        $this->assertTrue($this->props($workspaceAdmin)['canPlan']);
        $this->plan($item, $this->editor, self::WEEK, 60, null, $workspaceAdmin)->assertSessionHasNoErrors();
        // workspace admins are in the grid too (they can open every project)
        $this->assertNotNull($this->cell($this->props(), $workspaceAdmin, self::WEEK));
        $this->assertNull($this->cell($this->props(), $this->outsider, self::WEEK));
    }

    public function test_plans_stay_inside_their_project(): void
    {
        $item = $this->item();
        // another project of the same workspace, where the planner is no admin
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->planner->id]);
        $other->users()->attach($this->editor->id, ['role' => 'editor']);
        $other->users()->attach($this->planner->id, ['role' => 'viewer']);
        $otherBoard = Board::factory()->create(['project_id' => $other->id, 'created_by' => $this->planner->id]);
        $foreignItem = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->planner->id]);

        // an issue of the other project, a person outside this one
        $this->plan($foreignItem, $this->editor, self::WEEK, 60)->assertSessionHasErrors('item_id');
        $this->plan($item, $this->outsider, self::WEEK, 60)->assertSessionHasErrors('user_id');
        $this->capacity($this->outsider, 60)->assertSessionHasErrors('user_id');
        // viewing the other project's workload is fine, planning there isn't
        $this->actingAs($this->planner)->put($this->r('workload.capacity.update', [], $other), ['user_id' => $this->editor->id, 'weekly_minutes' => 60])->assertForbidden();

        // the same person's capacity and plans in the other project don't leak into this one
        $workspaceAdmin = User::find($this->workspace->owner_id);
        $this->actingAs($workspaceAdmin)->put($this->r('workload.capacity.update', [], $other), ['user_id' => $this->editor->id, 'weekly_minutes' => 600])->assertSessionHasNoErrors();
        $this->actingAs($workspaceAdmin)->put($this->r('workload.allocations.update', [], $other), ['item_id' => $foreignItem->id, 'user_id' => $this->editor->id, 'week_start' => self::WEEK, 'minutes' => 300, 'expected_minutes' => null])->assertSessionHasNoErrors();
        $cell = $this->cell($this->props(), $this->editor, self::WEEK);
        $this->assertSame(['unset', 0], [$cell['state'], $cell['allocated_minutes']]);

        // an allocation can't be removed through another project's URL
        $foreign = WorkloadAllocation::sole();
        $this->actingAs($this->planner)->delete($this->r('workload.allocations.destroy', ['allocation' => $foreign->id]))->assertNotFound();
        $this->assertModelExists($foreign);
        // the issue search lists this project's issues only
        $ids = array_column($this->actingAs($this->planner)->getJson($this->r('workload.items'))->json(), 'id');
        $this->assertContains($item->id, $ids);
        $this->assertNotContains($foreignItem->id, $ids);
    }

    public function test_removed_members_leave_the_grid_and_their_plans_are_flagged(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 600]);
        $this->capacity($this->editor, 2400);
        $this->plan($item, $this->editor, self::NEXT, 300)->assertSessionHasNoErrors();

        $this->project->removeUser($this->editor);

        $props = $this->props();
        $this->assertNull($this->cell($props, $this->editor, self::NEXT));
        $this->assertSame(['not_member'], array_column($props['review'], 'kind'));
        $this->assertStringContainsString('no longer in this project', $props['review'][0]['message']);
        // no new plans for them, but the old one can be removed
        $this->plan($this->item(), $this->editor, self::NEXT, 60)->assertSessionHasErrors('user_id');
        $this->actingAs($this->planner)->delete($this->r('workload.allocations.destroy', ['allocation' => $props['review'][0]['allocation']['id']]))->assertRedirect();
        $this->assertSame(0, WorkloadAllocation::count());
        $this->assertSame([], $this->props()['review']);
    }

    public function test_deleted_and_completed_issues(): void
    {
        $this->capacity($this->editor, 2400);
        $deleted = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 600]);
        $this->plan($deleted, $this->editor, self::WEEK, 600)->assertSessionHasNoErrors();
        $deleted->delete();

        $cell = $this->cell($this->props(), $this->editor, self::WEEK);
        $this->assertSame([0, []], [$cell['allocated_minutes'], $cell['allocations']]);
        $this->plan($deleted, $this->editor, self::NEXT, 60)->assertSessionHasErrors('item_id');
        $deleted->restore();
        $this->assertSame(600, $this->cell($this->props(), $this->editor, self::WEEK)['allocated_minutes']);

        // a trashed board hides its issues too
        $this->board->delete();
        $this->assertSame(0, $this->cell($this->props(), $this->editor, self::WEEK)['allocated_minutes']);
        $this->board->restore();

        // done this week: this week's plan counts, next week's doesn't and is flagged
        $done = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 300]);
        $this->plan($done, $this->editor, self::WEEK, 120)->assertSessionHasNoErrors();
        $this->plan($done, $this->editor, self::NEXT, 180)->assertSessionHasNoErrors();
        $done->update(['completed_at' => now(), 'column_id' => $this->column('done')]);

        $props = $this->props();
        $this->assertSame(720, $this->cell($props, $this->editor, self::WEEK)['allocated_minutes']);
        $next = $this->cell($props, $this->editor, self::NEXT);
        $this->assertSame(0, $next['allocated_minutes']);
        $this->assertFalse($next['allocations'][0]['counts']);
        $this->assertSame(['done'], $next['allocations'][0]['flags']);
        $this->assertSame(['done'], array_column($props['review'], 'kind'));
        // nothing new on a done issue; done issues aren't offered and aren't open work
        $this->plan($done, $this->editor, '2026-11-16', 60)->assertSessionHasErrors('item_id');
        $this->assertNotContains($done->id, array_column($this->actingAs($this->planner)->getJson($this->r('workload.items'))->json(), 'id'));
        $this->assertSame(1, collect($props['members'])->firstWhere('id', $this->editor->id)['work']['estimated_items']);
    }

    public function test_rounding_works_on_minutes(): void
    {
        $this->capacity($this->editor, 180);
        foreach (range(1, 3) as $i) {
            $this->plan($this->item(), $this->editor, self::WEEK, 20)->assertSessionHasNoErrors();
        }
        $cell = $this->cell($this->props(), $this->editor, self::WEEK);
        $this->assertSame([60, 33, 120], [$cell['allocated_minutes'], $cell['load_percent'], $cell['free_minutes']]);

        $this->capacity($this->editor, 450); // 7.5h
        $this->assertSame(13, $this->cell($this->props(), $this->editor, self::WEEK)['load_percent']);
        $this->assertSame(['7h 30m', '20m', '0h', '25h', '-1h 5m'], array_map(WorkloadFormat::hours(...), [450, 20, 0, 1500, -65]));

        $this->capacity($this->editor, 2.5)->assertSessionHasErrors('weekly_minutes'); // whole minutes only
        $this->capacity($this->editor, -1)->assertSessionHasErrors('weekly_minutes');
        $this->capacity($this->editor, WorkloadPlanner::MAX_MINUTES + 1)->assertSessionHasErrors('weekly_minutes');
        $this->plan($this->item(), $this->editor, self::WEEK, WorkloadPlanner::MAX_MINUTES + 1)->assertSessionHasErrors('minutes');
    }

    public function test_concurrent_changes_are_not_overwritten(): void
    {
        $item = $this->item(['original_estimate' => 600]);
        $this->plan($item, $this->editor, self::WEEK, 120)->assertSessionHasNoErrors();
        // a second planner still saw an empty cell
        $this->plan($item, $this->editor, self::WEEK, 300, null)->assertSessionHasErrors('minutes');
        // and one who saw the old value can't overwrite a newer one
        $this->plan($item, $this->editor, self::WEEK, 240, 120)->assertSessionHasNoErrors();
        $this->plan($item, $this->editor, self::WEEK, 60, 120)->assertSessionHasErrors('minutes');
        $this->plan($item, $this->editor, self::WEEK, 0, 120)->assertSessionHasErrors('minutes');
        $this->assertSame(240, WorkloadAllocation::sole()->minutes);

        // writes without a screen behind them (no expectation) still land on the one row
        WorkloadPlanner::allocate($this->project, $item, $this->editor, self::WEEK, 90, $this->planner, false);
        WorkloadPlanner::allocate($this->project, $item, $this->editor, self::WEEK, 90, $this->planner, false);
        $this->assertSame(90, WorkloadAllocation::sole()->minutes);

        $this->expectException(ValidationException::class);
        WorkloadPlanner::allocate($this->project, $item, $this->editor, self::WEEK, 30, $this->planner, true, 45);
    }

    public function test_source_changes_are_flagged_never_applied(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id, 'original_estimate' => 480]);
        $this->plan($item, $this->editor, self::WEEK, 480)->assertSessionHasNoErrors();

        // estimate raised + reassigned: the plan keeps its 8h for the editor, both changes are flagged
        $item->update(['original_estimate' => 720, 'assigned_to' => $this->viewer->id]);
        $review = $this->props()['review'];
        $this->assertEqualsCanonicalizing(['estimate_changed', 'assignee_changed'], array_column($review, 'kind'));
        $byKind = collect($review)->keyBy('kind');
        $this->assertStringContainsString('from 8h to 12h', $byKind['estimate_changed']['message']);
        $this->assertStringContainsString('now assigned to '.$this->viewer->name, $byKind['assignee_changed']['message']);
        $this->assertSame(480, WorkloadAllocation::sole()->minutes);
        $this->assertSame($this->editor->id, WorkloadAllocation::sole()->user_id);

        // "Keep": saving the same hours again records the reviewed estimate
        $this->plan($item, $this->editor, self::WEEK, 480, 480)->assertSessionHasNoErrors();
        $this->assertSame(['assignee_changed'], array_column($this->props()['review'], 'kind'));

        // an unassigned issue planned for someone isn't a conflict
        $unassigned = $this->item(['original_estimate' => 60]);
        $this->plan($unassigned, $this->editor, self::NEXT, 60)->assertSessionHasNoErrors();
        $this->assertSame(['assignee_changed'], array_column($this->props()['review'], 'kind'));

        // past weeks are history: no flags there
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
        $this->assertSame([], $this->props()['review']);
        $this->assertSame(1, WorkloadAllocation::where('item_id', $item->id)->count());
    }

    public function test_an_issue_moved_to_another_project_is_planned_afresh_there(): void
    {
        $item = $this->item(['original_estimate' => 600]);
        $this->plan($item, $this->editor, self::WEEK, 300)->assertSessionHasNoErrors();

        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->planner->id]);
        $other->users()->attach($this->editor->id, ['role' => 'editor']);
        $otherBoard = Board::factory()->create(['project_id' => $other->id, 'created_by' => $this->planner->id]);
        $item->update(['board_id' => $otherBoard->id, 'project_id' => $other->id]);

        // gone from the old project's grid and review
        $props = $this->props();
        $this->assertSame(0, $this->cell($props, $this->editor, self::WEEK)['allocated_minutes']);
        $this->assertSame([], $props['review']);

        // planned in the new project without a phantom conflict (and without revealing the old 5h)
        $admin = User::find($this->workspace->owner_id);
        $this->actingAs($admin)->put($this->r('workload.allocations.update', [], $other), [
            'item_id' => $item->id, 'user_id' => $this->editor->id, 'week_start' => self::WEEK, 'minutes' => 120, 'expected_minutes' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame([[$other->id, 120]], WorkloadAllocation::get()->map(fn ($a) => [$a->project_id, $a->minutes])->all());
    }

    public function test_past_weeks_dont_block_splitting_a_parent_into_subtasks(): void
    {
        $parent = $this->item(['original_estimate' => 600]);
        $child = $this->item(['parent_id' => $parent->id, 'original_estimate' => 240]);
        $this->plan($parent, $this->editor, self::PREV, 300)->assertSessionHasNoErrors();

        $this->plan($child, $this->editor, self::WEEK, 240)->assertSessionHasNoErrors();
        // but the past week itself still can't hold both
        $this->plan($child, $this->editor, self::PREV, 60)->assertSessionHasErrors('item_id');
        // and a trashed board's issues block nothing
        $this->board->delete();
        $this->assertSame(0, WorkloadAllocation::where('week_start', '>=', self::WEEK)->whereIn('item_id', WorkloadPlanner::projectItems($this->project)->select('items.id'))->count());
    }

    public function test_more_permission_and_validation_edges(): void
    {
        $item = $this->item();
        $this->plan($item, $this->editor, self::WEEK, 60)->assertSessionHasNoErrors();
        $allocation = WorkloadAllocation::sole();

        foreach ([$this->viewer, $this->editor, $this->outsider] as $user) {
            $this->actingAs($user)->delete($this->r('workload.allocations.destroy', ['allocation' => $allocation->id]))->assertForbidden();
        }
        $this->actingAs($this->outsider)->put($this->r('workload.overrides.update'), ['user_id' => $this->editor->id, 'week_start' => self::WEEK, 'minutes' => 0])->assertForbidden();
        $this->actingAs($this->outsider)->getJson($this->r('workload.items'))->assertForbidden();
        $this->override($this->outsider, self::WEEK, 0)->assertSessionHasErrors('user_id');
        $this->actingAs($this->planner)->getJson($this->r('workload.items', ['q' => str_repeat('x', 101)]))->assertUnprocessable();
        $this->actingAs($this->planner)->getJson($this->r('workload.items', ['user_id' => $this->outsider->id]))->assertUnprocessable();
        $this->actingAs($this->planner)->delete($this->r('workload.allocations.destroy', ['allocation' => $allocation->id + 1000]))->assertNotFound();
        $this->assertModelExists($allocation);

        // absence reasons and former members' names are for planners only
        $this->actingAs($this->planner)->put($this->r('workload.overrides.update'), ['user_id' => $this->editor->id, 'week_start' => self::WEEK, 'minutes' => 0, 'note' => 'Parental leave'])->assertSessionHasNoErrors();
        $this->assertSame('Parental leave', $this->cell($this->props(), $this->editor, self::WEEK)['override_note']);
        $this->assertNull($this->cell($this->props($this->viewer), $this->editor, self::WEEK)['override_note']);
        $this->project->removeUser($this->editor);
        $this->assertSame(['not_member'], array_column($this->props()['review'], 'kind'));
        $this->assertSame([], $this->props($this->viewer)['review']);
    }

    public function test_the_page_renders_for_a_project_without_people_planned(): void
    {
        $props = $this->props($this->viewer);
        $this->assertSame(self::WEEK, $props['currentWeek']);
        $this->assertCount(6, $props['weeks']);
        $this->assertSame([4, 6, 8, 12], $props['spans']);
        $this->assertSame('UTC', $props['timezone']);
        $this->assertSame($this->project->accessibleUsers()->count(), count($props['members']));
        $this->assertFileExists(resource_path('js/Pages/Workload/Index.tsx'));
    }
}
