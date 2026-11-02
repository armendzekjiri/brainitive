<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An issue, doc / whiteboard, ticket or plan linked with a page ("Related content" / "Referenced by",
 * ContentLinkFormatter — the MCP's shape). Only content the user can open is listed.
 *
 * @property array<string, mixed> $resource
 */
class ContentLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;

        return [
            /** @var 'item'|'page'|'ticket'|'plan' */
            'type' => (string) $l['type'],
            // Issue, Doc, Whiteboard, Ticket, Plan
            'kind' => (string) $l['kind'],
            'id' => (int) $l['id'],
            // issue / ticket key
            'key' => $l['key'] === '' ? null : (string) $l['key'],
            // docs and whiteboards
            'uuid' => $l['uuid'] === '' ? null : (string) $l['uuid'],
            'title' => (string) $l['title'],
            'url' => (string) $l['url'],
            // draft / archived for pages
            'status' => $l['status'] === '' ? null : (string) $l['status'],
            // manual (linked by someone), derived (a smart link, a plan row, a whiteboard card) or both
            /** @var 'manual'|'derived'|'manual+derived' */
            'origin' => (string) $l['origin'],
            'derived_from' => $l['derived_from'] === '' ? null : (string) $l['derived_from'],
        ];
    }
}
