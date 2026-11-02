<?php

namespace App\Observers;

use App\Models\SyncTombstone;
use App\Models\TaskListItem;
use App\Support\MyWork\WorkRef;
use App\Support\Notifications\Assignments;

/**
 * task_assigned for every path that assigns a task-list task (list UI, MCP, CLI, quick capture,
 * duplicates): sent here once, never from the controllers (BRAI-103).
 */
class TaskListItemObserver
{
    public function created(TaskListItem $task): void
    {
        Assignments::task($task);
    }

    public function updated(TaskListItem $task): void
    {
        if ($task->wasChanged('assignee_id')) {
            Assignments::task($task);
            // App API offline sync (BRAI-425): the task leaves the previous assignee's My Work
            SyncTombstone::record((int) $task->getOriginal('assignee_id') ?: null, SyncTombstone::WORK, WorkRef::of($task), SyncTombstone::UNASSIGNED);
        }
    }
}
