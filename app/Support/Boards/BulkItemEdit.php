<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\Sprint;
use App\Models\User;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\ItemStatusChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk edit / delete of board issues (BRAI-108, the board's list view).
 *
 * Every issue is changed through the model with one save, so `ItemObserver` writes one history
 * entry per issue, exactly like a single edit. Notifications go out after the commit, once per
 * issue (never once per changed field).
 */
class BulkItemEdit
{
    /** Most issues one request may touch. */
    public const MAX_ITEMS = 200;

    /**
     * Apply `$changes` to the issues. Keys (all optional): column_id, assigned_to, priority_id,
     * item_type_id, sprint_id, due_date, add_label_ids, remove_label_ids — validated by the caller.
     *
     * @param  Collection<int, Item>  $items
     * @param  array<string, mixed>  $changes
     * @return array{updated: int, unchanged: int, skipped_subtasks: int}
     */
    public static function apply(Board $board, Collection $items, array $changes, User $actor): array
    {
        $column = array_key_exists('column_id', $changes) ? BoardColumn::findOrFail($changes['column_id']) : null;
        $sprint = array_key_exists('sprint_id', $changes)
            ? ($changes['sprint_id'] === null ? null : Sprint::findOrFail($changes['sprint_id']))
            : false; // false = sprint not part of this change
        $addLabels = array_map('intval', $changes['add_label_ids'] ?? []);
        $removeLabels = array_map('intval', $changes['remove_label_ids'] ?? []);

        $assignments = [];   // [Item, ?int previous assignee]
        $statusMoves = [];   // [Item, BoardColumn previous column]
        $updated = 0;
        $skippedSubtasks = 0;

        DB::transaction(function () use ($board, $items, $changes, $column, $sprint, $addLabels, $removeLabels, &$assignments, &$statusMoves, &$updated, &$skippedSubtasks) {
            // New cards land at the bottom of the target column, in the order they were listed
            $nextPosition = $column ? (int) (Item::where('column_id', $column->id)->max('position') ?? 0) : 0;

            foreach ($items as $item) {
                $touched = false;
                $previousAssignee = $item->assigned_to;
                $previousColumn = $item->column;

                foreach (['assigned_to', 'priority_id', 'item_type_id'] as $field) {
                    if (array_key_exists($field, $changes)) {
                        $item->{$field} = $changes[$field];
                    }
                }
                if (array_key_exists('due_date', $changes)) {
                    $item->due_date = $changes['due_date'];
                    if ($changes['due_date'] === null) {
                        $item->due_time = null; // a time without a date means nothing
                    }
                }
                if ($column && $item->column_id !== $column->id) {
                    $wasDone = (bool) $previousColumn?->is_done_column;
                    $item->column_id = $column->id;
                    $item->position = ++$nextPosition;
                    // Same rule as Item::moveToColumn, but an issue that stays done keeps its date
                    $item->completed_at = $column->is_done_column ? ($wasDone ? $item->completed_at : now()) : null;
                }

                if ($item->isDirty()) {
                    $item->save();
                    $touched = true;
                }

                if ($item->wasChanged('assigned_to') && $item->assigned_to) {
                    $assignments[] = [$item, $previousAssignee];
                }
                if ($item->wasChanged('column_id') && $previousColumn) {
                    $statusMoves[] = [$item, $previousColumn];
                }

                // Sprints: the whole subtree travels with its issue, so subtasks follow their parent
                if ($sprint !== false && $board->isScrum()) {
                    if ($item->parent_id !== null) {
                        $skippedSubtasks++;
                    } elseif ($item->sprint_id !== $sprint?->id) {
                        $item->moveToSprint($sprint);
                        $touched = true;
                    }
                }

                if ($addLabels !== [] || $removeLabels !== []) {
                    $touched = self::changeLabels($board, $item, $addLabels, $removeLabels) || $touched;
                }

                if ($touched) {
                    $updated++;
                }
            }
        });

        foreach ($assignments as [$item, $previousAssignee]) {
            self::notifyAssignee($item, $previousAssignee, $actor);
        }
        foreach ($statusMoves as [$item, $previousColumn]) {
            self::notifyStatusChange($board, $item, $previousColumn, $actor);
        }

        return ['updated' => $updated, 'unchanged' => $items->count() - $updated, 'skipped_subtasks' => $skippedSubtasks];
    }

    /**
     * Soft-delete the issues and their whole subtrees (like a single delete).
     *
     * @param  Collection<int, Item>  $items
     * @return int issues deleted (subtasks included)
     */
    public static function delete(Collection $items): int
    {
        return DB::transaction(function () use ($items) {
            $ids = $items->pluck('id')->all();
            foreach ($items as $item) {
                $ids = [...$ids, ...$item->descendantIds()];
            }
            $ids = array_values(array_unique($ids));

            // Soft deletes don't trigger the FK cascade: delete every issue of the subtrees.
            Item::whereIn('id', $ids)->get()->each->delete();

            return count($ids);
        });
    }

    /**
     * Add / remove project labels on one issue, with the same history entry as the issue dialog.
     *
     * @param  list<int>  $add
     * @param  list<int>  $remove
     */
    private static function changeLabels(Board $board, Item $item, array $add, array $remove): bool
    {
        $current = $item->labels()->pluck('labels.id')->map(fn ($id) => (int) $id)->all();
        $attached = array_values(array_diff($add, $current));
        $detached = array_values(array_intersect($remove, $current));
        if ($attached === [] && $detached === []) {
            return false;
        }

        if ($attached !== []) {
            $item->labels()->attach($attached);
        }
        if ($detached !== []) {
            $item->labels()->detach($detached);
        }

        $names = $board->project->labels()->whereIn('id', [...$attached, ...$detached])->pluck('name', 'id');
        ActivityLog::log($item, 'labels_changed', [
            'labels' => [
                'added' => array_map(fn ($id) => $names[$id] ?? "#{$id}", $attached),
                'removed' => array_map(fn ($id) => $names[$id] ?? "#{$id}", $detached),
            ],
        ]);

        return true;
    }

    /**
     * Same rule as BoardController::updateItem: tell a new assignee, unless they did it themselves.
     * BRAI-103 moves assignment notifications into ItemObserver; then this method goes.
     */
    private static function notifyAssignee(Item $item, ?int $previousAssignee, User $actor): void
    {
        if (! $item->assigned_to || $item->assigned_to === $previousAssignee || $item->assigned_to === $actor->id) {
            return;
        }

        User::find($item->assigned_to)?->notify(new ItemAssignedNotification($item, $actor));
    }

    /**
     * Same rule as BoardController::moveItem: watchers who can still open the project hear about
     * the new status, except the person who changed it.
     */
    private static function notifyStatusChange(Board $board, Item $item, BoardColumn $previousColumn, User $actor): void
    {
        $item->load(['watchers', 'column']);

        foreach ($item->watchers as $watcher) {
            if ($watcher->id === $actor->id || ! $watcher->hasProjectAccess($board->project)) {
                continue;
            }

            $watcher->notify(new ItemStatusChangedNotification(
                item: $item,
                oldColumn: $previousColumn,
                newColumn: $item->column,
                actor: $actor,
            ));
        }
    }
}
