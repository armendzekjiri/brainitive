<?php

namespace App\Support\Sync;

use App\Http\Resources\AppApi\TaskListDetailResource;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\Captures\CaptureConversion;
use App\Support\MyDay\MyDayPlan;
use App\Support\MyWork\WorkRef;
use App\Support\TaskLists\TaskLists;
use Carbon\CarbonImmutable;

/**
 * The entity of a `POST /api/v1/sync` result as the feed's record (BRAI-441): the web's offline engine
 * answers snapshots of its own; the App API looks the entity up again (by the outcome's entity type + id,
 * also for replays) and renders it with the feed's resources, so a client replaces its cached record
 * directly. `issue.*` and `work.done` → `work`, `list_task.create|update` → `task`, `list_task.reorder` →
 * `task_list`, `capture.create` → `capture`, `my_day.*` → `my_day`, `issue.comment` → `comment`. Only
 * what the user can open now: gone or hidden → null.
 */
final class SyncRecords
{
    public static function find(User $user, string $mutationType, ?string $entityType, ?int $entityId): ?SyncChange
    {
        if (str_starts_with($mutationType, 'my_day.')) {
            return self::myDay($user);
        }
        if ($entityId === null) {
            return null;
        }

        return match ($entityType) {
            'issue' => self::work($user, WorkRef::ISSUE.':'.$entityId),
            'list_task' => $mutationType === 'work.done' ? self::work($user, WorkRef::TASK.':'.$entityId) : self::task($user, $entityId),
            'task_list' => self::taskList($user, $entityId),
            'capture' => self::capture($user, $entityId),
            'comment' => self::comment($user, $entityId),
            default => null,
        };
    }

    private static function work(User $user, string $ref): ?SyncChange
    {
        $work = WorkRef::visible($user, $ref);
        if ($work === null) {
            return null;
        }
        $project = $work instanceof TaskListItem ? $work->taskList->project_id : $work->board->project_id;

        return new SyncChange(ChangeFeed::WORK, WorkRef::of($work), SyncChange::UPSERT, (int) $project, $work->updated_at, $work);
    }

    private static function task(User $user, int $id): ?SyncChange
    {
        $task = TaskListItem::with(['assignee', 'taskList.project.workspace'])->find($id);
        if (! $task?->taskList?->project?->workspace || ! $user->can('view', $task->taskList)) {
            return null;
        }

        return new SyncChange('task', (string) $task->id, SyncChange::UPSERT, (int) $task->taskList->project_id, $task->updated_at, $task);
    }

    private static function taskList(User $user, int $id): ?SyncChange
    {
        $list = TaskLists::withCounts(TaskList::query()->with('project.workspace')->whereKey($id))->first();
        if (! $list?->project?->workspace || ! $user->can('view', $list)) {
            return null;
        }

        return new SyncChange('task_list', (string) $list->id, SyncChange::UPSERT, (int) $list->project_id, $list->updated_at, $list,
            TaskListDetailResource::abilities($user, $list));
    }

    private static function capture(User $user, int $id): ?SyncChange
    {
        $capture = Capture::query()->where('user_id', $user->id)->find($id);
        if ($capture === null) {
            return null;
        }
        $locations = CaptureConversion::locations(collect([$capture]), $user);

        return new SyncChange('capture', (string) $capture->uuid, SyncChange::UPSERT, null, $capture->updated_at, $capture, $locations[$capture->id] ?? null);
    }

    private static function comment(User $user, int $id): ?SyncChange
    {
        $comment = Comment::with('user')->find($id);
        $item = $comment ? WorkRef::visible($user, WorkRef::ISSUE.':'.$comment->item_id) : null;
        if ($item === null) {
            return null;
        }

        return new SyncChange('comment', (string) $comment->id, SyncChange::UPSERT, (int) $item->board->project_id, $comment->updated_at, $comment);
    }

    /** Today's My Day, as `GET /me/my-day`. */
    private static function myDay(User $user): SyncChange
    {
        $date = MyDayPlan::today($user);

        return new SyncChange(ChangeFeed::MY_DAY, $date, SyncChange::UPSERT, null, CarbonImmutable::now('UTC'), [
            'date' => $date,
            'items' => MyDayPlan::forDate($user, $date, WorkRef::ISSUE_WITH, WorkRef::TASK_WITH)->pluck('work')->values(),
            'carry_over_count' => MyDayPlan::carryOverCandidates($user, $date)->count(),
        ]);
    }
}
