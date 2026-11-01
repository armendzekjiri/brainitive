<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/me/timer/start`: the issue to time. */
class StartTimerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // issue:<id> (as in My Work); list tasks have no work log and can't be timed
            'id' => ['required', 'string', 'max:40'],
        ];
    }
}
