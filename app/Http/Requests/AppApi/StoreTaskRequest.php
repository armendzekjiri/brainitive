<?php

namespace App\Http\Requests\AppApi;

use App\Support\TaskLists\TaskLists;

/**
 * `POST /api/v1/task-lists/{id}/items`: the web's task fields (TaskLists::taskRules), with the
 * notes as Markdown instead of the editor's document.
 */
class StoreTaskRequest extends TaskRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...TaskLists::taskRules($this->listForRules()),
            // Markdown; stored as the web editor's HTML
            'notes' => ['nullable', 'string', 'max:10000'],
            // a uuid: a retry with the same one answers 200 with the task the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
