<?php

namespace App\Http\Resources\AppApi;

use App\Models\Comment;
use App\Support\Boards\IssueMarkdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment on an issue (BRAI-405): `body` as Markdown (mentions `@[Name](user:<id>)`), `parent_id` for a
 * reply. Needs `user`.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $author = $this->resource->user;

        return [
            'id' => (int) $this->id,
            'item_id' => (int) $this->item_id,
            // the comment this one replies to (null = a top-level comment)
            'parent_id' => $this->parent_id === null ? null : (int) $this->parent_id,
            'body' => IssueMarkdown::toMarkdown($this->body) ?? '',
            // null when the author's account is gone
            'author' => $author ? new PersonResource($author) : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
