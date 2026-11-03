<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `POST /pages/{uuid}/move`: into a folder / page, to the top level, or before / after a sibling (the
 * sidebar's drag & drop and "Move…" dialog, PageMover).
 */
class MovePageRequest extends PageRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the new parent (a folder or page of the same project), null = the top level
            'parent_uuid' => ['present', 'nullable', 'uuid'],
            // put it right before / after this sibling (one of them at most); else at the end
            'before_uuid' => ['nullable', 'uuid'],
            'after_uuid' => ['nullable', 'uuid'],
        ];
    }
}
