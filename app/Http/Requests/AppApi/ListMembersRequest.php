<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/** Members for assignee / mention pickers: a cursor page, optionally filtered by name or email. */
class ListMembersRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // no updated_since: joining or leaving doesn't change the member's own record
        return [
            ...Arr::except(parent::rules(), 'updated_since'),
            // part of the name or email address
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function search(): ?string
    {
        $q = trim((string) $this->validated('q'));

        return $q === '' ? null : $q;
    }
}
