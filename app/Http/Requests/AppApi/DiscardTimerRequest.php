<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/me/timer/discard`: stop without logging. */
class DiscardTimerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // GET /me/timer → timer.id
            'timer_id' => ['required', 'integer'],
        ];
    }
}
