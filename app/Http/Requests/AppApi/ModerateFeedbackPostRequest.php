<?php

namespace App\Http\Requests\AppApi;

/** `POST /api/v1/feedback-posts/{id}/moderate`: approve (make public) or reject a submitted idea. */
class ModerateFeedbackPostRequest extends FeedbackPostRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // approve | reject
            'decision' => ['required', 'string', 'in:approve,reject'],
        ];
    }
}
