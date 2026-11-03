<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recent destination the user can still open (RecentDestinations), e.g. for a "Recent" menu.
 *
 * @property-read array{type: string, id: int, title: string, subtitle: string, url: string} $resource
 */
class RecentDestinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // project | board | page | item | plan
            'type' => (string) $this->resource['type'],
            'id' => (int) $this->resource['id'],
            'title' => (string) $this->resource['title'],
            // where it lives, e.g. "Website · Board"
            'subtitle' => (string) $this->resource['subtitle'],
            'url' => (string) $this->resource['url'],
        ];
    }
}
