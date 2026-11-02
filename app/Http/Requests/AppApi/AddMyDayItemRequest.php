<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/me/my-day/items`: the work to plan for today. */
class AddMyDayItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // issue:<id> or task:<id>, as in GET /me/work
            'id' => ['required', 'string', 'max:40'],
        ];
    }
}
