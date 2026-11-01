<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** `GET /api/v1/task-lists/{id}/items`: the list rules + `status`. */
class ListTasksRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // todo | done | all (default)
            'status' => ['sometimes', Rule::in(['todo', 'done', 'all'])],
        ];
    }

    public function status(): string
    {
        return (string) ($this->validated('status') ?? 'all');
    }
}
