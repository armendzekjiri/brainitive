<?php

namespace App\Http\Requests\AppApi;

use App\Models\Capture;
use Illuminate\Validation\Rule;

/** `GET /api/v1/captures/{id}/preview`: which destination type to prepare the fields for. */
class CapturePreviewRequest extends CaptureRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // issue | task | page | bookmark | snippet
            'type' => ['required', Rule::in(Capture::TYPES)],
        ];
    }
}
