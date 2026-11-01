<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** `GET /api/v1/bookmarks/{id}/items`: the list rules + a `type` filter. */
class ListBookmarkItemsRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // link | todo | note | file (default: all)
            'type' => ['sometimes', Rule::in(['link', 'todo', 'note', 'file'])],
        ];
    }

    public function type(): ?string
    {
        $type = $this->validated('type');

        return is_string($type) ? $type : null;
    }
}
