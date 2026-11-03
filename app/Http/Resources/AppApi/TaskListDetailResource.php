<?php

namespace App\Http\Resources\AppApi;

use App\Models\TaskList;
use App\Models\User;
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
            'can' => self::abilities($user, $this->resource),
            'watching' => $this->resource->watchers()->whereKey($user->id)->exists(),
        ];
    }

    /**
     * What the user may do with the list (also the sync feed's `task_list` records, BRAI-441).
     *
     * @return array{update: bool, delete: bool, manage_items: bool}
     */
    public static function abilities(User $user, TaskList $list): array
    {
        return [
            // rename / describe (web only for now)
            'update' => $user->can('update', $list),
            'delete' => $user->can('delete', $list),
            // add, edit, complete, reorder and delete tasks
            'manage_items' => $user->can('manageItems', $list),
        ];
    }
}
