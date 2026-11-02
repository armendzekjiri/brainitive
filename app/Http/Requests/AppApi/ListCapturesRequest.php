<?php

namespace App\Http\Requests\AppApi;

use App\Models\Capture;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** `GET /api/v1/captures`: the list rules + `state` and the search `q`. */
class ListCapturesRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // inbox (default) | processed | archived
            'state' => ['sometimes', Rule::in(Capture::STATES)],
            // matches the text, the URL and the source
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function state(): string
    {
        return (string) ($this->validated('state') ?? 'inbox');
    }

    public function search(): string
    {
        return trim((string) $this->validated('q'));
    }
}
