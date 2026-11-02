<?php

namespace App\Http\Resources\AppApi;

use App\Support\Sync\SyncChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the change feed (`GET /api/v1/sync/changes`, BRAI-425). `data` is the record exactly as
 * its own endpoint returns it — `work`: a work item (`GET /me/work`), `task_list`: a list
 * (`GET /projects/{id}/task-lists`, plus `can` as `GET /task-lists/{id}`), `task`: a task
 * (`GET /task-lists/{id}/items`), `capture`: a capture (`GET /captures`), `my_day`: My Day
 * (`GET /me/my-day`) — and null for a `delete`. `POST /sync` renders its results' `entity` the same way.
 *
 * @property-read SyncChange $resource
 */
class SyncChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $change = $this->resource;

        return [
            // work | task_list | task | capture | my_day
            'type' => $change->type,
            // the record's id in its own endpoint: "issue:12" / "task:5" (work), a number as text (task_list,
            // task), the uuid (capture), the date Y-m-d (my_day)
            'id' => $change->id,
            // upsert = add or replace your copy with `data`; delete = drop it (deleted, or no longer yours)
            'op' => $change->op,
            // the project it belongs to (null: captures, My Day, deletions)
            'project_id' => $change->projectId,
            'updated_at' => $change->updatedAt?->toIso8601ZuluString(),
            /** @var array<string, mixed>|null the record (see the type), null for a delete */
            'data' => self::record($change),
        ];
    }

    /** The record of an upsert as its type's resource (also the `entity` of `POST /sync` results). */
    public static function record(SyncChange $change): ?JsonResource
    {
        if ($change->op === SyncChange::DELETE) {
            return null;
        }

        return match ($change->type) {
            'work' => new WorkItemResource($change->record),
            'task_list' => new SyncTaskListResource($change->record, (array) $change->context),
            'task' => new TaskResource($change->record),
            'capture' => new CaptureResource($change->record, is_array($change->context) ? $change->context : null),
            'my_day' => new MyDayResource($change->record),
            'comment' => new CommentResource($change->record),
            default => null,
        };
    }
}
