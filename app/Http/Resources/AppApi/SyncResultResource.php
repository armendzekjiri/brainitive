<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of one change of a `POST /api/v1/sync` batch (BRAI-425), in the batch's order. `status`
 * says what the device does with its queued change: `applied` / `conflict` → done (drop it from the
 * outbox; on a conflict show `conflicts` and let the user pick), `deleted` / `forbidden` / `invalid` →
 * drop it and tell the user, `waiting` / `rate_limited` / `error` → keep it and send it again later.
 *
 * @property-read array{request_id: string|null, type: string|null, status: string, http_status: int, body?: array<string, mixed>, errors?: array<string, list<string>>|null} $resource
 */
class SyncResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $body = $result['body'] ?? [];

        return [
            /** @var string|null the change's request_id (null when it had none: invalid) */
            'request_id' => $result['request_id'],
            /** @var string|null the change's type */
            'type' => $result['type'],
            // applied | conflict | deleted | waiting | forbidden | invalid | rate_limited | error
            'status' => $result['status'],
            // the HTTP status this result stands for: 200 | 409 | 410 | 424 | 403 | 422 | 429 | 500
            'http_status' => (int) $result['http_status'],
            // true: this request_id was applied before; this is the stored result, nothing was written again
            'replayed' => (bool) ($body['replayed'] ?? false),
            // issue | list_task | comment | capture | my_day | task_list | board …
            'entity_type' => $body['entity_type'] ?? null,
            /** @var array<string, mixed>|null the server's state after the change (applied, conflict) */
            'entity' => $body['entity'] ?? null,
            /** @var array<string, array{base: mixed, mine: mixed, theirs: mixed}>|null conflicting fields: none of them was written */
            'conflicts' => $body['conflicts'] ?? null,
            /** @var list<string>|null fields of a conflicting change that did not conflict and were written */
            'applied' => $body['applied'] ?? null,
            /** @var string|null what to tell the user (deleted, waiting, forbidden, invalid, conflict) */
            'message' => $body['message'] ?? null,
            /** @var array<string, list<string>>|null validation errors of an `invalid` change */
            'errors' => $result['errors'] ?? null,
        ];
    }
}
