<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ancestor of a page (its breadcrumbs, top first), from `Page::$path`.
 *
 * @property array{uuid: string, name: string, type: string} $resource
 */
class PageCrumbResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->resource['uuid'],
            'name' => (string) $this->resource['name'],
            /** @var 'folder'|'page' */
            'type' => (string) $this->resource['type'],
        ];
    }
}
