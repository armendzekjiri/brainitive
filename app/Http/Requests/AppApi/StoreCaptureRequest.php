<?php

namespace App\Http\Requests\AppApi;

use App\Models\Capture;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/captures`: what a person types into a capture (Capture::rules(), as the web and the extension). */
class StoreCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...Capture::rules(),
            // a uuid: a retry with the same one answers 200 with the capture the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
