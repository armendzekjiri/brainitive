<?php

namespace App\Support\Offline\Appliers;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Support\MyWork\AssignedWork;
use App\Support\Offline\FieldMerge;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use App\Support\Work\WorkCompletion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The circle of My Work / My Day ticked offline (App API sync, BRAI-425): complete or reopen an issue
 * or a list task, exactly as `PATCH /api/v1/me/work/{id}` and the web's circle (`WorkCompletion::setDone`:
 * an issue moves to its board's first done / open column, a repeating task moves to its next date).
 * Same policies (`ItemPolicy::update`, `TaskListPolicy::manageItems`). Base value: `done` as the device
 * saw it; someone else completing / reopening it meanwhile is a conflict, already being in the wanted
 * state is a no-op.
 *
 * data: `{work_type: board|list, work_id | work_ref, done}` (work_ref = the uuid of an offline create).
 */
final class WorkMutations
{
    private const KINDS = ['done' => FieldMerge::BOOL];

    public static function done(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate([
            'work_type' => ['required', Rule::in(MyDayEntry::TYPES)],
            'work_id' => 'required_without:work_ref|nullable|integer',
            'work_ref' => 'required_without:work_id|nullable|uuid',
            'done' => 'required|boolean',
        ]);
        $isIssue = $data['work_type'] === 'board';
        $entity = $isIssue ? IssueMutations::ENTITY : ListTaskMutations::ENTITY;
        $id = ! empty($data['work_id']) ? (int) $data['work_id'] : $ctx->resolveRef($data['work_ref'], $entity);
        $done = filter_var($data['done'], FILTER_VALIDATE_BOOL);
        $base = $ctx->baseFor(['done' => $done]);

        $work = $isIssue
            ? Item::with('board.project.workspace', 'column')->lockForUpdate()->find($id)
            : TaskListItem::with('taskList.project.workspace')->lockForUpdate()->find($id);
        // deleted and "no longer visible to you" get the same answer (a trashed parent counts as deleted)
        $visible = $work instanceof Item
            ? $work->board?->project?->workspace && $ctx->user->can('view', $work)
            : $work?->taskList?->project?->workspace && $ctx->user->can('view', $work->taskList);
        if (! $visible) {
            throw new SyncAbort(SyncOutcome::deleted($entity, 'This task was deleted while you were offline.'));
        }
        $work instanceof Item
            ? Gate::forUser($ctx->user)->authorize('update', $work)
            : Gate::forUser($ctx->user)->authorize('manageItems', $work->taskList);

        $merge = FieldMerge::merge(['done' => AssignedWork::isDone($work)], $base, ['done' => $done], self::KINDS);
        if (array_key_exists('done', $merge['apply'])) {
            // a board without a done / open column → ValidationException (invalid, nothing written)
            WorkCompletion::setDone($work, $done, $ctx->user);
        }

        $work->refresh(); // reloads the column too
        $snapshot = ($work instanceof Item ? IssueMutations::snapshot($work) : ListTaskMutations::snapshot($work))
            + ['done' => AssignedWork::isDone($work)];

        return $merge['conflicts'] === []
            ? SyncOutcome::applied($entity, $snapshot, $work->id)
            : SyncOutcome::conflict($entity, $snapshot, $work->id, $merge['conflicts'], array_keys($merge['apply']));
    }
}
