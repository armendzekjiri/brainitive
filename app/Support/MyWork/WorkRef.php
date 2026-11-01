<?php

namespace App\Support\MyWork;

use App\Models\Item;
use App\Models\TaskListItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The App API's id of a piece of work: `issue:<item id>` (a board issue) or `task:<task id>` (a list
 * task). My Day stores the same pair as `work_type` board | list.
 */
final class WorkRef
{
    public const ISSUE = 'issue';

    public const TASK = 'task';

    /** App API type → MyDayEntry::work_type */
    public const MY_DAY_TYPES = [self::ISSUE => 'board', self::TASK => 'list'];

    /** What WorkItemResource reads: one query per relation, never per row. */
    public const ISSUE_WITH = [
        'board:id,project_id,name,slug',
        'board.project:id,workspace_id,name,slug,key,color',
        'board.project.workspace:id,name,slug',
        'column:id,name,is_done_column',
        'priority:id,name,color',
    ];

    public const TASK_WITH = [
        'taskList:id,project_id,name,slug',
        'taskList.project:id,workspace_id,name,slug,key,color',
        'taskList.project.workspace:id,name,slug',
    ];

    /** @return array{0: string, 1: int}|null [type, id] of "issue:12" / "task:5"; null when malformed */
    public static function parse(?string $ref): ?array
    {
        if (! is_string($ref) || ! preg_match('/^(issue|task):([1-9][0-9]{0,18})$/', $ref, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    public static function of(Item|TaskListItem $work): string
    {
        return ($work instanceof Item ? self::ISSUE : self::TASK).':'.$work->id;
    }

    /**
     * The work behind a ref if the user can open it (the web policies: ItemPolicy::view,
     * TaskListPolicy::view), with WorkItemResource's relations; null otherwise (→ 404).
     */
    public static function visible(User $user, ?string $ref): Item|TaskListItem|null
    {
        $parsed = self::parse($ref);
        if ($parsed === null) {
            return null;
        }
        [$type, $id] = $parsed;

        if ($type === self::ISSUE) {
            $item = Item::with(self::ISSUE_WITH)->find($id);

            // a trashed board / project counts as deleted (and must never reach a policy with null)
            return $item?->board?->project?->workspace && $user->can('view', $item) ? $item : null;
        }

        $task = TaskListItem::with(self::TASK_WITH)->find($id);

        return $task?->taskList?->project?->workspace && $user->can('view', $task->taskList) ? $task : null;
    }

    /**
     * Load many refs at once (a page of My Work), in the given order; missing ones are skipped.
     *
     * @param  iterable<array{0: string, 1: int}>  $refs
     * @return Collection<int, Item|TaskListItem>
     */
    public static function load(iterable $refs): Collection
    {
        $refs = collect($refs);
        $issues = Item::with(self::ISSUE_WITH)->whereIn('id', $refs->where(0, self::ISSUE)->pluck(1))->get()->keyBy('id');
        $tasks = TaskListItem::with(self::TASK_WITH)->whereIn('id', $refs->where(0, self::TASK)->pluck(1))->get()->keyBy('id');

        return $refs
            ->map(fn (array $ref) => $ref[0] === self::ISSUE ? $issues->get($ref[1]) : $tasks->get($ref[1]))
            ->filter()
            ->values();
    }
}
