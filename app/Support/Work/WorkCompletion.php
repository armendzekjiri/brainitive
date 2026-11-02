<?php

namespace App\Support\Work;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\TaskListItem;
use App\Models\User;
use App\Notifications\ItemStatusChangedNotification;
use Illuminate\Validation\ValidationException;

/**
 * Completing and reopening work, the same way everywhere (board drag & drop, the circle in My Work /
 * My Day, the App API):
 *  - a board issue moves to a column (done = the board's first done column, reopen = its first open
 *    one), and its watchers hear about the new status;
 *  - a list task changes its status; completing a repeating task with a due date keeps it open and
 *    moves it to the next date.
 * Callers authorize first (ItemPolicy::update, TaskListPolicy::manageItems).
 */
final class WorkCompletion
{
    /** Done / undone for either kind of work (the circle). No change when it already is. */
    public static function setDone(Item|TaskListItem $work, bool $done, User $actor): void
    {
        if ($work instanceof TaskListItem) {
            if (($work->status === 'done') !== $done) {
                self::updateTask($work, ['status' => $done ? 'done' : 'todo']);
            }

            return;
        }

        $isDone = $work->completed_at !== null || ($work->column?->is_done_column ?? false);
        if ($isDone === $done) {
            return;
        }

        $column = $work->board?->columns()
            ->where('is_done_column', $done)
            ->orderBy('position')
            ->first();
        if (! $column instanceof BoardColumn) {
            throw ValidationException::withMessages(['done' => [$done ? 'This board has no done column.' : 'This board has no open column.']]);
        }

        self::moveIssue($work, $column, 0, $actor);
    }

    /**
     * Move an issue to a column of its board; watchers (except the actor) hear about a new status.
     * `$withSubtasks`: moving into a done column completes the open subtasks (all levels) too, as the
     * board's "Also complete the subtasks?" (web drag & drop, App API).
     */
    public static function moveIssue(Item $item, BoardColumn $column, int $position, User $actor, bool $withSubtasks = false): void
    {
        $oldColumn = $item->column;

        $item->moveToColumn($column, $position);

        if ($withSubtasks && $column->is_done_column) {
            Item::whereIn('id', $item->descendantIds())
                ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
                ->get()
                ->each(fn (Item $child) => $child->moveToColumn($column, 0));
        }

        if (! $oldColumn || $oldColumn->id === $column->id) {
            return;
        }

        $project = $item->board->project;
        $item->load('watchers');
        foreach ($item->watchers as $watcher) {
            if ($watcher->id !== $actor->id && $watcher->hasProjectAccess($project)) {
                $watcher->notify(new ItemStatusChangedNotification(
                    item: $item,
                    oldColumn: $oldColumn,
                    newColumn: $column,
                    actor: $actor,
                ));
            }
        }
    }

    /**
     * Update a list task. Completing a repeating task that has a due date keeps it open and moves it
     * to the next date; without a due date there is nothing to move, so it just gets done.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function updateTask(TaskListItem $task, array $attributes): void
    {
        $completing = ($attributes['status'] ?? null) === 'done' && $task->status !== 'done';

        if ($completing && $task->recurrence && $task->due_date) {
            $next = $task->nextDueDate();
            $task->update(array_merge($attributes, [
                'status' => 'todo',
                'due_date' => $next ? $next->format('Y-m-d') : $task->getRawOriginal('due_date'),
            ]));

            return;
        }

        $task->update($attributes);
    }
}
