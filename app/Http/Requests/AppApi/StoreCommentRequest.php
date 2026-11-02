<?php

namespace App\Http\Requests\AppApi;

use App\Support\Validation\ContentLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/items/{id}/comments`: Markdown, optionally a reply to a comment of the issue. The web lets
 * the people who may edit the issue comment (ItemPolicy::update → 403 otherwise).
 */
class StoreCommentRequest extends IssueRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $itemId = $this->issueForRules()?->id;

        return [
            // Markdown; mentions as @[Name](user:<id>)
            'body' => ['required', ...ContentLimits::comment()],
            // reply to this comment of the issue
            'parent_id' => ['nullable', 'integer', $itemId === null ? 'min:1' : Rule::exists('comments', 'id')->where('item_id', $itemId)],
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
