<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `POST /pages/{uuid}/publish`: publish a draft, or make a published doc's unpublished changes live. */
class PublishPageRequest extends PageRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the version's note in the history
            'message' => ['nullable', 'string', 'max:255'],
            // a published doc's update: tell its watchers (default true)
            'notify' => ['sometimes', 'boolean'],
            // the `revision` as last read: 409 when the page changed since
            'revision' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
