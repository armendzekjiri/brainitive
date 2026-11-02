<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `PATCH /api/v1/me/work/{id}` and `PATCH /api/v1/me/my-day/items/{id}`: done or open again. */
class WorkDoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // true: complete it (issue → the board's first done column); false: reopen it
            'done' => ['required', 'boolean'],
        ];
    }
}
