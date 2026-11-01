<?php

namespace App\Http\Requests\AppApi;

use App\Support\TaskLists\TaskLists;

/**
 * `PATCH /api/v1/task-lists/{id}/items/{item}`: only the sent fields change (`null` clears one).
 * `status: done` on a repeating task with a due date moves it to its next date and keeps it open.
 */
class UpdateTaskRequest extends TaskRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...TaskLists::taskRules($this->listForRules(), update: true),
            // Markdown; stored as the web editor's HTML; null clears it
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
