<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review of a doc's draft (BRAI-80 / 413), as ReviewPresenter::summary builds it for the web. `stale`: the
 * draft changed since the snapshot (an approval no longer lets it publish).
 *
 * @property array<string, mixed> $resource
 */
class PageReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'uuid' => (string) $r['uuid'],
            // #1, #2 … of this page
            'number' => (int) $r['number'],
            /** @var 'pending'|'approved'|'changes_requested'|'published'|'cancelled' */
            'status' => (string) $r['status'],
            'stale' => (bool) $r['stale'],
            // the title of the reviewed snapshot
            'snapshot_name' => (string) $r['snapshot_name'],
            'message' => $r['message'] === null ? null : (string) $r['message'],
            'requested_by' => ['id' => $r['requested_by']['id'] === null ? null : (int) $r['requested_by']['id'], 'name' => (string) $r['requested_by']['name']],
            'created_at' => PageReviewerResource::zulu($r['created_at']),
            'closed_at' => PageReviewerResource::zulu($r['closed_at']),
            'url' => (string) $r['url'],
            'reviewers' => PageReviewerResource::collection($r['reviewers']),
        ];
    }
}
