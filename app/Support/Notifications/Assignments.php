<?php

namespace App\Support\Notifications;

use App\Models\Item;
use App\Models\Lead;
use App\Models\TaskListItem;
use App\Models\User;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Support\Imports\ImportScope;
use Illuminate\Support\Facades\DB;

/**
 * "X assigned you …" for issues, task-list tasks and leads — sent from ONE place per model, so every
 * path (board UI, bulk edit, MCP, meeting notes, plan import, whiteboard, duplicates) notifies exactly once:
 *
 * - issues: ItemObserver::created/updated → item()
 * - tasks:  TaskListItemObserver::created/updated → task()
 * - leads:  LeadController::update → lead()
 *
 * The actor is the signed-in user. No actor (seeders, console commands) or assigning yourself sends
 * nothing. Sent after the surrounding transaction commits, so a rolled-back write tells nobody.
 */
class Assignments
{
    public static function item(Item $item): void
    {
        if (ImportScope::active()) {
            return; // bulk imports don't notify per record (v1.41.0)
        }
        $actor = self::actor();
        $assigneeId = (int) $item->assigned_to;
        if (! $actor || ! $assigneeId || $assigneeId === $actor->id) {
            return;
        }

        DB::afterCommit(function () use ($item, $actor, $assigneeId) {
            $assignee = User::find($assigneeId);
            $project = $item->board?->project;
            if ($assignee && $project && $assignee->hasProjectAccess($project)) {
                $assignee->notify(new ItemAssignedNotification($item, $actor));
            }
        });
    }

    public static function task(TaskListItem $task): void
    {
        if (ImportScope::active()) {
            return; // bulk imports don't notify per record (v1.41.0)
        }
        $actor = self::actor();
        $assigneeId = (int) $task->assignee_id;
        if (! $actor || ! $assigneeId || $assigneeId === $actor->id) {
            return;
        }

        DB::afterCommit(function () use ($task, $actor, $assigneeId) {
            $assignee = User::find($assigneeId);
            $project = $task->taskList?->project;
            if ($assignee && $project && $assignee->hasProjectAccess($project)) {
                $assignee->notify(new TaskAssignedNotification($task, $actor));
            }
        });
    }

    public static function lead(Lead $lead, ?int $previousAssigneeId): void
    {
        $actor = self::actor();
        $assigneeId = (int) $lead->assigned_to;
        if (! $actor || ! $assigneeId || $assigneeId === (int) $previousAssigneeId || $assigneeId === $actor->id) {
            return;
        }

        $assignee = User::find($assigneeId);
        $project = $lead->form?->project;
        if ($assignee && $project && $assignee->hasProjectAccess($project)) {
            $assignee->notify(new LeadAssignedNotification($lead, $actor));
        }
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
