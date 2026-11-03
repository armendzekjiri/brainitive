<?php

namespace App\Http\Requests\AppApi;

/** `POST /api/v1/task-lists/{id}/items/reorder`: task ids in their new order. */
class ReorderTasksRequest extends TaskRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // every task id of the list, top to bottom (positions become 1, 2, 3 …; ids of other lists are ignored)
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
