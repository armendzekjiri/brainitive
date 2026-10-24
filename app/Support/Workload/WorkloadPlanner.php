<?php

namespace App\Support\Workload;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkloadAllocation;
use App\Models\WorkloadCapacity;
use App\Models\WorkloadCapacityOverride;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write of workload planning (BRAI-75) and the rules behind it:
 * - allocations are explicit (item + person + week = minutes), never spread automatically;
 * - an issue and its parent / subtasks never both carry allocations (no double counting);
 * - a write states what the planner saw (`expected`), so a parallel change isn't overwritten.
 */
class WorkloadPlanner
{
    /** Upper bound for any weekly amount: the hours of a whole week. */
    public const MAX_MINUTES = 7 * 24 * 60;

    /** Longest stretch one override request may cover (e.g. parental leave is entered in parts). */
    public const MAX_OVERRIDE_WEEKS = 26;

    /**
     * Issues of the project: on one of its boards, not trashed (nor their board).
     *
     * @return Builder<Item>
     */
    public static function projectItems(Project $project): Builder
    {
        return Item::query()->whereIn('items.board_id', Board::where('project_id', $project->id)->select('id'));
    }

    /** Remaining estimate = max(original estimate − logged, 0); null when there is no estimate. */
    public static function remaining(Item $item): ?int
    {
        if ($item->original_estimate === null) {
            return null;
        }

        return max((int) $item->original_estimate - (int) $item->time_spent, 0);
    }

    /** Default weekly capacity; null removes it (back to "not set"). */
    public static function setCapacity(Project $project, User $member, ?int $minutes, User $by): void
    {
        if ($minutes === null) {
            WorkloadCapacity::where('project_id', $project->id)->where('user_id', $member->id)->delete();

            return;
        }

        WorkloadCapacity::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $member->id],
            ['weekly_minutes' => $minutes, 'updated_by' => $by->id],
        );
    }

    /** Capacity for `weeks` weeks from `monday`; null removes the overrides (default applies again). */
    public static function setOverride(Project $project, User $member, string $monday, int $weeks, ?int $minutes, ?string $note, User $by): void
    {
        $mondays = WorkloadWeeks::range($monday, $weeks);

        DB::transaction(function () use ($project, $member, $mondays, $minutes, $note, $by) {
            if ($minutes === null) {
                WorkloadCapacityOverride::where('project_id', $project->id)->where('user_id', $member->id)
                    ->whereIn('week_start', $mondays)->delete();

                return;
            }

            foreach ($mondays as $week) {
                WorkloadCapacityOverride::updateOrCreate(
                    ['project_id' => $project->id, 'user_id' => $member->id, 'week_start' => $week],
                    ['minutes' => $minutes, 'note' => $note, 'updated_by' => $by->id],
                );
            }
        });
    }

    /**
     * Set the planned minutes of an issue for a person and week (0 removes them).
     * `$expected` = the minutes the planner saw (null = nothing planned); pass `$checkExpected`
     * false only for writes that don't come from a screen.
     *
     * @throws ValidationException
     */
    public static function allocate(Project $project, Item $item, User $member, string $monday, int $minutes, User $by, bool $checkExpected = true, ?int $expected = null): ?WorkloadAllocation
    {
        $key = ['item_id' => $item->id, 'user_id' => $member->id, 'week_start' => $monday];

        return DB::transaction(function () use ($project, $item, $monday, $minutes, $by, $checkExpected, $expected, $key) {
            if ($minutes > 0) {
                // One issue tree plans one at a time: lock its root, then check parents / subtasks.
                $ancestors = self::ancestorIds($item);
                Item::whereKey($ancestors === [] ? $item->id : end($ancestors))->lockForUpdate()->first();
                self::guardSubtree($project, $item, min(WorkloadWeeks::current($by), $monday), $ancestors);
            }

            // Rows of another project belong to an issue that has since moved here: they were inert
            // (no grid shows them) and would otherwise collide with the unique key — drop them.
            WorkloadAllocation::where('item_id', $item->id)->where('project_id', '!=', $project->id)->delete();

            $existing = WorkloadAllocation::where($key)->where('project_id', $project->id)->lockForUpdate()->first();

            if ($checkExpected && ($existing?->minutes ?? 0) !== ($expected ?? 0)) {
                throw self::conflict($existing?->minutes ?? 0);
            }
            if ($minutes === 0) {
                $existing?->delete();

                return null;
            }
            if (! $existing && $item->completed_at !== null) {
                throw ValidationException::withMessages(['item_id' => "{$item->key} is done. Plan open issues only."]);
            }

            $values = ['minutes' => $minutes, 'estimate_snapshot' => $item->original_estimate, 'updated_by' => $by->id];
            if ($existing) {
                $existing->update($values);

                return $existing;
            }

            // A parallel request may have inserted the same row since the lock: the unique key keeps
            // it to one row (createOrFirst uses a savepoint, safe inside the transaction on Postgres).
            $allocation = WorkloadAllocation::createOrFirst($key, $values + ['project_id' => $project->id, 'created_by' => $by->id]);
            if (! $allocation->wasRecentlyCreated) {
                if ($checkExpected) {
                    throw self::conflict($allocation->minutes);
                }
                $allocation->update($values);
            }

            return $allocation;
        });
    }

    /**
     * Refuse planning an issue whose parent / ancestors or subtasks already carry planned time
     * from `$sinceWeek` on: the same work would be counted twice. Weeks before it are history
     * (a parent planned last month can still be split into subtasks now).
     *
     * @param  list<int>|null  $ancestors
     *
     * @throws ValidationException
     */
    public static function guardSubtree(Project $project, Item $item, string $sinceWeek, ?array $ancestors = null): void
    {
        $related = array_merge($ancestors ?? self::ancestorIds($item), $item->descendantIds());
        if ($related === []) {
            return;
        }

        $conflict = WorkloadAllocation::query()
            ->where('workload_allocations.project_id', $project->id)
            ->where('workload_allocations.week_start', '>=', $sinceWeek)
            // live issues of the project only (not trashed, nor their board)
            ->whereIn('workload_allocations.item_id', self::projectItems($project)->whereIn('items.id', $related)->select('items.id'))
            ->join('items', 'items.id', '=', 'workload_allocations.item_id')
            ->value('items.key');

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'item_id' => "{$conflict} (a parent or subtask of {$item->key}) already has planned hours. Plan an issue or its subtasks, not both, so the same work isn't counted twice.",
            ]);
        }
    }

    /**
     * Parent, grandparent, … (not trashed).
     *
     * @return list<int>
     */
    public static function ancestorIds(Item $item): array
    {
        $ids = [];
        $parentId = $item->parent_id;
        while ($parentId !== null && count($ids) <= Item::MAX_DEPTH + 5 && ! in_array($parentId, $ids, true)) {
            $parent = Item::select('id', 'parent_id')->find($parentId);
            if (! $parent) {
                break;
            }
            $ids[] = $parent->id;
            $parentId = $parent->parent_id;
        }

        return $ids;
    }

    private static function conflict(int $now): ValidationException
    {
        return ValidationException::withMessages([
            'minutes' => 'Someone changed this plan in the meantime (now '.WorkloadFormat::hours($now).'). Check the new value and try again.',
        ]);
    }
}
