<?php

namespace App\Support\Offline\Appliers;

use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Support\Offline\FieldMerge;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use Illuminate\Support\Facades\Gate;

/**
 * Task-list tasks made or changed offline (BRAI-82): create, rename / complete, reorder. Same
 * policy as the list page (`TaskListPolicy::manageItems`).
 */
final class ListTaskMutations
{
    public const ENTITY = 'list_task';

    private const KINDS = ['title' => FieldMerge::STRING, 'status' => FieldMerge::STRING];

    public static function create(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate([
            'task_list_id' => 'required|integer',
            'title' => 'required|string|max:500',
            'status' => 'nullable|in:todo,done',
        ]);
        $list = self::list($ctx, (int) $data['task_list_id']);

        $task = $list->items()->create([
            'title' => $data['title'],
            'status' => $data['status'] ?? 'todo',
            'position' => (int) $list->items()->max('position') + 1,
            'created_by' => $ctx->user->id,
        ]);

        return SyncOutcome::applied(self::ENTITY, self::snapshot($task), $task->id);
    }

    public static function update(MutationContext $ctx): SyncOutcome
    {
        $changes = $ctx->validate([
            'title' => 'sometimes|required|string|max:500',
            'status' => 'sometimes|required|in:todo,done',
        ]);
        $base = $ctx->baseFor($changes);

        $task = TaskListItem::with('taskList.project.workspace')->lockForUpdate()->find($ctx->targetId(self::ENTITY));
        // a trashed list / project / workspace counts as deleted (and must never reach a policy with null)
        if (! $task?->taskList?->project?->workspace || ! $ctx->user->can('view', $task->taskList)) {
            throw new SyncAbort(SyncOutcome::deleted(self::ENTITY, 'This task was deleted while you were offline.'));
        }
        Gate::forUser($ctx->user)->authorize('manageItems', $task->taskList);

        $merge = FieldMerge::merge(self::fields($task), $base, $changes, self::KINDS);
        if ($merge['apply'] !== []) {
            self::write($task, $merge['apply']);
        }

        $snapshot = self::snapshot($task->refresh());

        return $merge['conflicts'] === []
            ? SyncOutcome::applied(self::ENTITY, $snapshot, $task->id)
            : SyncOutcome::conflict(self::ENTITY, $snapshot, $task->id, $merge['conflicts'], array_keys($merge['apply']));
    }

    /** The order of a list's open tasks. Order isn't content: no conflict check, missing tasks are skipped. */
    public static function reorder(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate([
            'task_list_id' => 'required|integer',
            'items' => 'required|array|max:500',
            'items.*.id' => 'nullable|integer',
            'items.*.ref' => 'nullable|uuid',
        ]);
        $list = self::list($ctx, (int) $data['task_list_id']);

        $items = $data['items'];
        ksort($items); // validated() regroups wildcard keys; the index is the order

        $refs = $ctx->resolveRefs(array_values(array_filter(array_column($items, 'ref'))), self::ENTITY);
        $ids = [];
        foreach ($items as $entry) {
            $ids[] = ! empty($entry['id']) ? (int) $entry['id'] : (! empty($entry['ref']) ? $refs[$entry['ref']] : null);
        }
        $ids = array_values(array_filter($ids));
        $existing = $list->items()->whereIn('id', $ids)->pluck('id')->all();

        $position = 0;
        foreach ($ids as $id) {
            if (in_array($id, $existing, true)) {
                // query builder on purpose, like TaskListController::reorderItems (no events)
                $list->items()->where('id', $id)->update(['position' => $position++]);
            }
        }

        return SyncOutcome::applied('task_list', ['id' => $list->id, 'order' => array_values(array_intersect($ids, $existing))], $list->id);
    }

    /** @return array<string, mixed> */
    public static function snapshot(TaskListItem $task): array
    {
        return [
            'id' => $task->id,
            'task_list_id' => $task->task_list_id,
            'title' => $task->title,
            'status' => $task->status,
            'position' => $task->position,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    private static function list(MutationContext $ctx, int $id): TaskList
    {
        $list = TaskList::with('project.workspace')->find($id);
        if (! $list?->project?->workspace || ! $ctx->user->can('view', $list)) {
            throw new SyncAbort(SyncOutcome::deleted('task_list', 'This list was deleted while you were offline.'));
        }
        Gate::forUser($ctx->user)->authorize('manageItems', $list);

        return $list;
    }

    /** @return array<string, mixed> */
    private static function fields(TaskListItem $task): array
    {
        return ['title' => $task->title, 'status' => $task->status];
    }

    /**
     * Mirrors TaskListController::updateItem: completing a repeating task with a due date keeps it
     * open and moves it to the next date.
     *
     * @param  array<string, mixed>  $apply
     */
    private static function write(TaskListItem $task, array $apply): void
    {
        $completing = ($apply['status'] ?? null) === 'done' && $task->status !== 'done';
        if ($completing && $task->recurrence && $task->due_date) {
            $next = $task->nextDueDate();
            $task->update(array_merge($apply, [
                'status' => 'todo',
                'due_date' => $next ? $next->format('Y-m-d') : $task->getRawOriginal('due_date'),
            ]));

            return;
        }

        $task->update($apply);
    }
}
