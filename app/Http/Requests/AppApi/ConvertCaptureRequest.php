<?php

namespace App\Http\Requests\AppApi;

use App\Models\Capture;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/captures/{id}/convert`: the web's convert dialog. `fields` are the destination type's
 * fields (start from `GET /captures/{id}/preview?type=`), validated by ContentCapture::rules().
 */
class ConvertCaptureRequest extends CaptureRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // issue | task | page | bookmark | snippet
            'type' => ['required', Rule::in(Capture::TYPES)],
            // a board (issue), task list (task), project (page), bookmark group (bookmark) or snippet collection (snippet) id from GET /capture-destinations
            'destination_id' => ['required', 'integer'],
            // the type's fields, e.g. {title, description} for an issue
            'fields' => ['required', 'array'],
        ];
    }
}
