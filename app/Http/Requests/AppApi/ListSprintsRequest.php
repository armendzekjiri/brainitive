<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `GET /api/v1/boards/{id}/sprints`: the list rules + `status`. */
class ListSprintsRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // comma-separated: active, planned, completed (default active,planned)
            'status' => ['sometimes', 'string', 'regex:/^(active|planned|completed)(,(active|planned|completed))*$/'],
        ];
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return array_values(array_unique(explode(',', (string) ($this->validated('status') ?? 'active,planned'))));
    }
}
