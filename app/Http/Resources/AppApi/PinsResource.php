<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `PUT /api/v1/me/pins` (BRAI-391): a project's pins as saved (what the user may open, in order).
 *
 * @property-read array{project_id: int, pinned_only: bool, pins: list<array{type: string, id: int, name: string, url: string, is_whiteboard: bool}>} $resource
 */
class PinsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'project_id' => (int) $this->resource['project_id'],
            'pinned_only' => (bool) $this->resource['pinned_only'],
            'pins' => PinResource::collection($this->resource['pins']),
        ];
    }
}
