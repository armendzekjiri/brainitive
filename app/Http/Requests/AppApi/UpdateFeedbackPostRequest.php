<?php

namespace App\Http\Requests\AppApi;

use App\Models\FeedbackPost;
use Illuminate\Validation\Rule;

/** `PATCH /api/v1/feedback-posts/{id}`: the public status of an idea (Planned / Building / Released …) and its note. */
class UpdateFeedbackPostRequest extends FeedbackPostRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // open | planned | building | released | declined
            'status' => ['required', 'string', Rule::in(FeedbackPost::STATUSES)],
            // shown on the public post ("Shipping in May"); null clears it
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
