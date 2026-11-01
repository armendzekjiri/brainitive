<?php

namespace App\Http\Resources\AppApi;

use App\Models\TaskList;
use Illuminate\Http\Request;

/**
 * One task list (`GET /task-lists/{id}`): the list fields + what the user may do and whether they
 * watch it. The tasks are a list of their own (`GET /task-lists/{id}/items`).
 *
 * @mixin TaskList
 */
class TaskListDetailResource extends TaskListResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::toArray($request),
            'can' => [
                // rename / describe (web only for now)
                'update' => $user->can('update', $this->resource),
                'delete' => $user->can('delete', $this->resource),
                // add, edit, complete, reorder and delete tasks
                'manage_items' => $user->can('manageItems', $this->resource),
            ],
            'watching' => $this->resource->watchers()->whereKey($user->id)->exists(),
        ];
    }
}
