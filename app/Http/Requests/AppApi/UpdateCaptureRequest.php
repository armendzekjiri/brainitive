<?php

namespace App\Http\Requests\AppApi;

use App\Models\Capture;

/** `PATCH /api/v1/captures/{id}`: Capture::rules(), but only the sent fields change. */
class UpdateCaptureRequest extends CaptureRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(Capture::rules())
            ->map(fn ($rule) => ['sometimes', ...(is_array($rule) ? $rule : explode('|', $rule))])
            ->all();
    }
}
