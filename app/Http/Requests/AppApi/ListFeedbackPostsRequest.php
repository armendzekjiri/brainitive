<?php

namespace App\Http\Requests\AppApi;

use App\Support\Feedback\FeedbackModeration;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** `GET /api/v1/feedback-portals/{id}/posts?tab=`: one moderation tab of a feedback portal. */
class ListFeedbackPostsRequest extends ListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['updated_since']) + [
            // pending (oldest first) | published (most votes first, default) | rejected | merged (newest first)
            'tab' => ['sometimes', 'string', Rule::in(FeedbackModeration::TABS)],
        ];
    }
}
