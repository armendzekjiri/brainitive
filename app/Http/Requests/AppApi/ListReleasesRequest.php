<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** `GET /projects/{id}/releases?status=`: the project's releases, optionally of some statuses. */
class ListReleasesRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // comma-separated: unreleased, released, archived (default: all)
            'status' => ['sometimes', 'string', 'regex:/^(unreleased|released|archived)(,(unreleased|released|archived))*$/'],
        ];
    }

    /** @return list<string> */
    public function statuses(): array
    {
        $status = $this->validated('status');

        return is_string($status) ? array_values(array_unique(explode(',', $status))) : [];
    }
}
