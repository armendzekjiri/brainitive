<?php

namespace App\Http\Requests\AppApi;

use App\Models\PageComment;
use App\Support\PageReviews\QuoteAnchor;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `POST /pages/{uuid}/reviews/{review}/comments`: a new thread on an open review (PageReviewPolicy::comment), or a
 * reply to a thread of a review the user may see (`parent_uuid`).
 */
class StoreReviewCommentRequest extends ReviewRequest
{
    private ?PageComment $resolvedParent = null;

    public function authorize(): bool
    {
        if ($this->parentThread() !== null) {
            return true; // visibleThread: the user may see the thread's review
        }

        return $this->user()->can('comment', $this->review());
    }

    public function parentThread(): ?PageComment
    {
        $uuid = $this->input('parent_uuid');
        $this->review(); // the review in the path must be visible too

        return is_string($uuid) && $uuid !== '' ? ($this->resolvedParent ??= $this->visibleThread($this, $this->page(), $uuid)) : null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // plain text
            'body' => ['required', 'string', 'max:5000'],
            // reply to this thread (its root comment's uuid)
            'parent_uuid' => ['nullable', 'uuid'],
            // a new thread on a passage of the snapshot: the selected text and a few characters around it
            'quote' => ['nullable', 'string', 'max:1000'],
            'prefix' => ['nullable', 'string', 'max:'.(QuoteAnchor::CONTEXT * 2)],
            'suffix' => ['nullable', 'string', 'max:'.(QuoteAnchor::CONTEXT * 2)],
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
