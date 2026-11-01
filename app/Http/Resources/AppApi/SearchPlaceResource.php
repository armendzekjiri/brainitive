<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place matching the search by name (PlaceSearch, the web's command palette): a workspace,
 * project, board, plan, snippet or bookmark collection the user can open.
 *
 * @property-read array{id: int, type: string, title: string, subtitle: string|null, url: string, workspace: string|null} $resource
 */
class SearchPlaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // workspace | project | board | plan | snippet (a snippet collection) | bookmark (a bookmark collection)
            'type' => (string) $this->resource['type'],
            'id' => (int) $this->resource['id'],
            'title' => (string) $this->resource['title'],
            'subtitle' => isset($this->resource['subtitle']) ? (string) $this->resource['subtitle'] : null,
            'url' => (string) $this->resource['url'],
            // the workspace's name
            'workspace' => isset($this->resource['workspace']) ? (string) $this->resource['workspace'] : null,
        ];
    }
}
