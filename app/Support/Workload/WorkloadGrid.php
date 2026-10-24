<?php

namespace App\Support\Workload;

use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkloadAllocation;
use App\Models\WorkloadCapacity;
use App\Models\WorkloadCapacityOverride;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The workload page's numbers (BRAI-75): one row per person who can open the project, one cell
 * per week, plus each person's open assigned work and the "needs review" list.
 *
 * Rules (also in the plan file and in the UI's "How it's calculated"):
 * - everything is this project only; amounts are integer minutes, rounded only for display;
 * - load = allocated ÷ capacity; capacity 0 makes any planned time "over" (no percentage);
 *   no capacity declared = "unset" (no load, no overload);
 * - trashed issues / boards and issues moved out of the project don't count;
 * - a done issue counts up to the week it was completed in; planned time in later weeks is
 *   excluded from the load and flagged;
 * - remaining = max(estimate − logged, 0), compared with open allocations (this week and later)
 *   only, so logged work is never subtracted twice;
 * - "assignee changed" = the issue is assigned to someone else now (unassigned issues can be
 *   planned for anyone);
 * - a parent whose open subtasks carry estimates is a container: only the subtasks count in a
 *   person's open work (the allocation guard in WorkloadPlanner covers planned time).
 */
class WorkloadGrid
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Workspace $workspace, Project $project, User $viewer, string $from, int $span, bool $canPlan = false): array
    {
        $mondays = WorkloadWeeks::range($from, $span);
        $to = end($mondays);
        $current = WorkloadWeeks::current($viewer);
        $zone = $viewer->localTimezone();

        $members = $project->accessibleUsers()->orderBy('name')->orderBy('users.id')->get(['users.id', 'users.name', 'users.avatar']);
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $capacities = WorkloadCapacity::where('project_id', $project->id)->pluck('weekly_minutes', 'user_id');
        $overrides = WorkloadCapacityOverride::where('project_id', $project->id)
            ->whereBetween('week_start', [$from, $to])->get()
            ->keyBy(fn (WorkloadCapacityOverride $o) => $o->user_id.'|'.$o->week_start->toDateString());

        $boardUrl = fn (Item $item) => $item->board
            ? route('boards.show', [$workspace->slug, $project->slug, $item->board->slug]).'#item-'.$item->id
            : null;

        // Allocations shown in the range, plus every open one (this week and later) for the review.
        $allocations = WorkloadAllocation::query()
            ->where('project_id', $project->id)
            ->whereIn('item_id', WorkloadPlanner::projectItems($project)->select('items.id'))
            ->where(fn ($q) => $q->whereBetween('week_start', [$from, $to])->orWhere('week_start', '>=', $current))
            ->with([
                'item:id,key,title,board_id,parent_id,assigned_to,original_estimate,time_spent,completed_at',
                'item.board:id,slug',
                'item.assignee:id,name',
                'user:id,name',
            ])
            ->orderBy('week_start')->orderBy('id')
            ->get();

        $completionWeek = fn (Item $item) => $item->completed_at
            ? WorkloadWeeks::monday(CarbonImmutable::instance($item->completed_at)->setTimezone($zone)->format('Y-m-d'))
            : null;
        $counts = function (WorkloadAllocation $a) use ($completionWeek): bool {
            $done = $completionWeek($a->item);

            return $done === null || $a->week_start->toDateString() <= $done;
        };

        $open = $allocations->filter(fn (WorkloadAllocation $a) => $a->week_start->toDateString() >= $current);
        $openByItem = $open->groupBy('item_id')->map(fn (Collection $rows) => (int) $rows->sum('minutes'));

        // Per-allocation flags (open allocations only; past weeks are history).
        $flagsOf = function (WorkloadAllocation $a) use ($current, $counts, $memberIds): array {
            if ($a->week_start->toDateString() < $current) {
                return [];
            }
            $flags = [];
            if (! $counts($a)) {
                $flags[] = 'done';
            }
            if ($a->estimate_snapshot !== self::int($a->item->original_estimate)) {
                $flags[] = 'estimate_changed';
            }
            if (! in_array((int) $a->user_id, $memberIds, true)) {
                $flags[] = 'not_member';
            } elseif ($a->item->assigned_to !== null && self::int($a->item->assigned_to) !== (int) $a->user_id && $a->item->completed_at === null) {
                // planning an unassigned issue for someone is a choice, not a conflict
                $flags[] = 'assignee_changed';
            }

            return $flags;
        };

        $itemSummary = fn (Item $item) => [
            'id' => $item->id,
            'key' => $item->key,
            'title' => $item->title,
            'url' => $boardUrl($item),
            'estimate_minutes' => $item->original_estimate,
            'logged_minutes' => (int) $item->time_spent,
            'remaining_minutes' => WorkloadPlanner::remaining($item),
            'completed' => $item->completed_at !== null,
        ];

        $inRange = $allocations->filter(fn (WorkloadAllocation $a) => $a->week_start->toDateString() >= $from && $a->week_start->toDateString() <= $to)
            ->groupBy(fn (WorkloadAllocation $a) => $a->user_id.'|'.$a->week_start->toDateString());

        $work = self::openWork($project, $memberIds, $openByItem);

        $team = array_fill_keys($mondays, ['capacity_minutes' => 0, 'allocated_minutes' => 0, 'unset' => 0, 'over' => 0]);

        $rows = $members->map(function (User $member) use ($canPlan, $mondays, $capacities, $overrides, $inRange, $counts, $flagsOf, $itemSummary, $work, &$team) {
            $default = $capacities->get($member->id);
            $cells = [];
            foreach ($mondays as $week) {
                $override = $overrides->get($member->id.'|'.$week);
                $capacity = $override ? $override->minutes : ($default !== null ? (int) $default : null);
                $weekRows = $inRange->get($member->id.'|'.$week, collect());

                $allocated = 0;
                $unestimated = 0;
                $items = [];
                foreach ($weekRows as $a) {
                    $counted = $counts($a);
                    if ($counted) {
                        $allocated += $a->minutes;
                        if ($a->item->original_estimate === null) {
                            $unestimated += $a->minutes;
                        }
                    }
                    $items[] = [
                        'id' => $a->id,
                        'minutes' => $a->minutes,
                        'counts' => $counted,
                        'flags' => $flagsOf($a),
                        'item' => $itemSummary($a->item),
                    ];
                }

                $cells[] = self::cell($week, $capacity, $override, $allocated, $unestimated, $items, $canPlan);

                $team[$week]['allocated_minutes'] += $allocated;
                if ($capacity === null) {
                    $team[$week]['unset']++;
                } else {
                    $team[$week]['capacity_minutes'] += $capacity;
                }
                if ($capacity !== null && $allocated > $capacity) {
                    $team[$week]['over']++;
                }
            }

            return [
                'id' => $member->id,
                'name' => $member->name,
                'avatar_display' => $member->avatar_display,
                'capacity_minutes' => $default !== null ? (int) $default : null,
                'work' => $work[$member->id] ?? self::emptyWork(),
                'cells' => $cells,
            ];
        })->values()->all();

        return [
            'weeks' => array_map(fn (string $w) => WorkloadWeeks::describe($w) + ['is_current' => $w === $current, 'is_past' => $w < $current], $mondays),
            'currentWeek' => $current,
            'members' => $rows,
            'team' => array_map(fn (string $w) => ['week' => $w] + $team[$w], $mondays),
            // former members' planned time is the planners' to clean up; others don't need their names
            'review' => array_values(array_filter(
                self::review($open, $openByItem, $flagsOf, $itemSummary),
                fn (array $entry) => $canPlan || $entry['kind'] !== 'not_member',
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function cell(string $week, ?int $capacity, ?WorkloadCapacityOverride $override, int $allocated, int $unestimated, array $items, bool $canPlan): array
    {
        $state = match (true) {
            $capacity === null => 'unset',
            $allocated > $capacity => 'over',
            $capacity === 0 => 'unavailable',
            default => 'ok',
        };

        return [
            'week' => $week,
            'capacity_minutes' => $capacity,
            'capacity_source' => $override ? 'override' : ($capacity !== null ? 'default' : null),
            // reasons ("sick", "parental leave") are personal: planners only
            'override_note' => $canPlan ? $override?->note : null,
            'allocated_minutes' => $allocated,
            'unestimated_minutes' => $unestimated,
            'over_minutes' => $capacity !== null ? max($allocated - $capacity, 0) : 0,
            'free_minutes' => $capacity !== null ? max($capacity - $allocated, 0) : null,
            'load_percent' => $capacity ? (int) round($allocated * 100 / $capacity) : null,
            'state' => $state,
            'allocations' => $items,
        ];
    }

    /**
     * Each member's open assigned issues: estimated remaining, issues without estimate, and how
     * much of the remaining isn't planned in any open week yet.
     *
     * @param  list<int>  $memberIds
     * @param  Collection<int, int>  $openByItem
     * @return array<int, array<string, int>>
     */
    private static function openWork(Project $project, array $memberIds, Collection $openByItem): array
    {
        $items = WorkloadPlanner::projectItems($project)
            ->whereIn('assigned_to', $memberIds)
            ->whereNull('completed_at')
            ->withExists(['children as has_estimated_children' => fn ($q) => $q->whereNull('completed_at')->whereNotNull('original_estimate')])
            ->get(['items.id', 'items.assigned_to', 'items.original_estimate', 'items.time_spent']);

        $work = [];
        foreach ($items as $item) {
            if ($item->has_estimated_children) {
                continue; // a container: its estimated subtasks carry the work
            }
            $w = $work[$item->assigned_to] ??= self::emptyWork();
            $remaining = WorkloadPlanner::remaining($item);
            if ($remaining === null) {
                $w['unestimated_items']++;
            } else {
                $w['estimated_items']++;
                $w['remaining_minutes'] += $remaining;
                $w['unplanned_minutes'] += max($remaining - (int) $openByItem->get($item->id, 0), 0);
            }
            $work[$item->assigned_to] = $w;
        }

        return $work;
    }

    /** @return array<string, int> */
    private static function emptyWork(): array
    {
        return ['estimated_items' => 0, 'remaining_minutes' => 0, 'unplanned_minutes' => 0, 'unestimated_items' => 0];
    }

    /**
     * Inconsistencies a planner should look at; nothing here changes an allocation by itself.
     *
     * @param  Collection<int, WorkloadAllocation>  $open
     * @param  Collection<int, int>  $openByItem
     * @return list<array<string, mixed>>
     */
    private static function review(Collection $open, Collection $openByItem, callable $flagsOf, callable $itemSummary): array
    {
        $review = [];

        // planned more than the remaining estimate (estimate lowered, work logged meanwhile)
        foreach ($open->unique('item_id') as $a) {
            $item = $a->item;
            $remaining = WorkloadPlanner::remaining($item);
            $planned = (int) $openByItem->get($item->id, 0);
            if ($item->completed_at === null && $remaining !== null && $planned > $remaining) {
                $review[] = [
                    'kind' => 'over_remaining',
                    'message' => WorkloadFormat::hours($planned).' planned from this week on, but only '.WorkloadFormat::hours($remaining).' remaining ('
                        .WorkloadFormat::hours((int) $item->original_estimate).' estimate − '.WorkloadFormat::hours((int) $item->time_spent).' logged).',
                    'item' => $itemSummary($item),
                    'allocation' => null,
                ];
            }
        }

        foreach ($open as $a) {
            foreach ($flagsOf($a) as $flag) {
                $message = match ($flag) {
                    'done' => 'Done, but '.WorkloadFormat::hours($a->minutes).' are still planned for '.$a->user->name.' in a later week (not counted).',
                    'estimate_changed' => 'Estimate changed from '.self::estimateText($a->estimate_snapshot).' to '.self::estimateText($a->item->original_estimate).' since '.WorkloadFormat::hours($a->minutes).' were planned.',
                    'not_member' => WorkloadFormat::hours($a->minutes).' planned for '.$a->user->name.', who is no longer in this project (not shown in the grid).',
                    'assignee_changed' => WorkloadFormat::hours($a->minutes).' planned for '.$a->user->name.', but the issue is now assigned to '.$a->item->assignee?->name.'.',
                };
                $review[] = [
                    'kind' => $flag,
                    'message' => $message,
                    'item' => $itemSummary($a->item),
                    'allocation' => [
                        'id' => $a->id,
                        'user_id' => $a->user_id,
                        'user_name' => $a->user->name,
                        'week' => $a->week_start->toDateString(),
                        'minutes' => $a->minutes,
                    ],
                ];
            }
        }

        return $review;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function estimateText(?int $minutes): string
    {
        return $minutes === null ? 'none' : WorkloadFormat::hours($minutes);
    }
}
