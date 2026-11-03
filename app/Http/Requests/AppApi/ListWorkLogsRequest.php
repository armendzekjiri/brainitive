<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `GET /api/v1/work-logs`: the list rules + workspace and date filters. */
class ListWorkLogsRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // a workspace slug (default: every workspace the user is a member of)
            'workspace' => ['sometimes', 'string', 'max:255'],
            // Y-m-d, inclusive
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
