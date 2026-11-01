<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `GET /projects/{id}/pages?parent=`: the whole tree, or one level (`root` or a folder / page uuid). */
class ListPagesRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // `root` = the top level, a page / folder uuid = its children; leave out for every level
            'parent' => ['sometimes', 'string', 'regex:/^(root|[0-9a-fA-F-]{36})$/'],
        ];
    }

    /** null = every level, 'root' or a uuid */
    public function parent(): ?string
    {
        $parent = $this->validated('parent');

        return is_string($parent) ? $parent : null;
    }
}
