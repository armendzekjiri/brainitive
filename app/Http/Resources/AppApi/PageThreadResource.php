<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inline comment thread of a doc review (ReviewPresenter::threads): optionally anchored to a passage of the
 * snapshot (`quote` + `prefix` / `suffix`); `outdated` when that passage isn't in the snapshot being viewed.
 *
 * @property array<string, mixed> $resource
 */
class PageThreadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $t = $this->resource;

        return [
            'uuid' => (string) $t['uuid'],
            'review_uuid' => $t['review_uuid'] === null ? null : (string) $t['review_uuid'],
            'review_number' => $t['review_number'] === null ? null : (int) $t['review_number'],
            'quote' => $t['quote'] === null ? null : (string) $t['quote'],
            'prefix' => $t['prefix'] === null ? null : (string) $t['prefix'],
            'suffix' => $t['suffix'] === null ? null : (string) $t['suffix'],
            'outdated' => (bool) $t['outdated'],
            'resolved' => (bool) $t['resolved'],
            'resolved_at' => PageReviewerResource::zulu($t['resolved_at']),
            'resolved_by_name' => $t['resolved_by_name'] === null ? null : (string) $t['resolved_by_name'],
            'comments' => PageThreadCommentResource::collection($t['comments']),
        ];
    }
}
