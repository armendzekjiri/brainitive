<?php

namespace App\Http\Resources\AppApi;

use App\Models\TaskList;
use Illuminate\Http\Request;

/**
 * A task list in the offline sync (`task_list` records of `GET /sync/changes`, list results of `POST /sync`,
 * BRAI-441): the list as `GET /projects/{id}/task-lists` returns it, plus what the user may do with it —
 * the same `can` as `GET /task-lists/{id}`, so an offline view can hide "Add task" for viewers.
 *
 * @mixin TaskList
 */
class SyncTaskListResource extends TaskListResource
{
    /** @param  array{update: bool, delete: bool, manage_items: bool}  $can */
    public function __construct(TaskList $list, private readonly array $can)
    {
        parent::__construct($list);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            /** @var array{update: bool, delete: bool, manage_items: bool} as GET /task-lists/{id}: manage_items = add, edit, complete, reorder and delete tasks */
            'can' => $this->can,
        ];
    }
}
