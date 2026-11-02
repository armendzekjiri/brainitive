<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment of a review thread (the first one opens it). Plain text.
 *
 * @property array{uuid: string, author: array{id: int|null, name: string}, body: string, created_at: string|null} $resource
 */
class PageThreadCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->resource['uuid'],
            'author' => ['id' => $this->resource['author']['id'] === null ? null : (int) $this->resource['author']['id'], 'name' => (string) $this->resource['author']['name']],
            'body' => (string) $this->resource['body'],
            'created_at' => PageReviewerResource::zulu($this->resource['created_at']),
        ];
    }
}
