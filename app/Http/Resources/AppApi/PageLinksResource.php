<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A page's links: what it links to (`related`) and what links to it (`referenced_by`, the backlinks: other docs'
 * smart links, issues, plan rows, whiteboard cards).
 *
 * @property array{related: list<array<string, mixed>>, referenced_by: list<array<string, mixed>>} $resource
 */
class PageLinksResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'related' => ContentLinkResource::collection($this->resource['related']),
            'referenced_by' => ContentLinkResource::collection($this->resource['referenced_by']),
        ];
    }
}
