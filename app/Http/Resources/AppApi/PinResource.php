<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sidebar pin with its name and web link (PinnedContent).
 *
 * @property-read array{type: string, id: int, name: string, url: string, is_whiteboard: bool} $resource
 */
class PinResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // board | list | plan | helpdesk | lead_form | page (docs, whiteboards and folders)
            'type' => (string) $this->resource['type'],
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'url' => (string) $this->resource['url'],
            'is_whiteboard' => (bool) $this->resource['is_whiteboard'],
        ];
    }
}
