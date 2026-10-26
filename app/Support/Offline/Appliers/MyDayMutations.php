<?php

namespace App\Support\Offline\Appliers;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Support\MyDay\MyDayPlan;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use Illuminate\Validation\Rule;

/**
 * My Day changes made offline (BRAI-82), as MyDayController: only the plan changes, never the
 * task. Lands on the user's local "today" when it syncs. Completing a task is a task / issue
 * update (`list_task.update` status, `issue.update` column).
 */
final class MyDayMutations
{
    public const ENTITY = 'my_day';

    public static function add(MutationContext $ctx): SyncOutcome
    {
        [$type, $id] = self::work($ctx);

        // Only work the user can open can go on their plan.
        $work = $type === 'board' ? Item::with('board.project')->find($id) : TaskListItem::with('taskList.project')->find($id);
        $visible = $work instanceof Item
            ? $work->board && $ctx->user->can('view', $work)
            : $work && $work->taskList && $ctx->user->can('view', $work->taskList);
        if (! $visible) {
            throw new SyncAbort(SyncOutcome::deleted($type === 'board' ? IssueMutations::ENTITY : ListTaskMutations::ENTITY, 'This task was deleted while you were offline.'));
        }

        $today = MyDayPlan::today($ctx->user);
        $entry = MyDayEntry::firstOrCreate(
            ['user_id' => $ctx->user->id, 'work_type' => $type, 'work_id' => $id, 'plan_date' => $today],
            ['position' => (int) MyDayEntry::where('user_id', $ctx->user->id)->where('plan_date', $today)->max('position') + 1],
        );

        return SyncOutcome::applied(self::ENTITY, ['work_type' => $type, 'work_id' => $id, 'plan_date' => $today], $entry->id);
    }

    public static function remove(MutationContext $ctx): SyncOutcome
    {
        [$type, $id] = self::work($ctx);
        $today = MyDayPlan::today($ctx->user);

        MyDayEntry::where('user_id', $ctx->user->id)->where('plan_date', $today)
            ->where('work_type', $type)->where('work_id', $id)->delete();

        return SyncOutcome::applied(self::ENTITY, ['work_type' => $type, 'work_id' => $id, 'plan_date' => $today, 'removed' => true]);
    }

    /** @return array{0: string, 1: int} */
    private static function work(MutationContext $ctx): array
    {
        $data = $ctx->validate([
            'work_type' => ['required', Rule::in(MyDayEntry::TYPES)],
            'work_id' => 'required_without:work_ref|nullable|integer',
            'work_ref' => 'required_without:work_id|nullable|uuid',
        ]);
        $id = ! empty($data['work_id'])
            ? (int) $data['work_id']
            : $ctx->resolveRef($data['work_ref'], $data['work_type'] === 'board' ? IssueMutations::ENTITY : ListTaskMutations::ENTITY);

        return [$data['work_type'], $id];
    }
}
