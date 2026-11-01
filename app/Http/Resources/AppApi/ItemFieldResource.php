<?php

namespace App\Http\Resources\AppApi;

use App\Models\ItemField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom field of the project's issues (BRAI-407), in settings order. Needs `options`.
 *
 * @mixin ItemField
 */
class ItemFieldResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $boardIds = $this->resource->board_ids;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            // text | number | date | checkbox | select
            'type' => (string) $this->type,
            // the boards it shows on; null = every board of the project
            'board_ids' => $boardIds === null ? null : array_values(array_map('intval', (array) $boardIds)),
            // archived fields keep their values but are neither shown nor set
            'archived' => $this->resource->isArchived(),
            'options' => FieldOptionResource::collection($this->resource->options->sortBy('position')->values()),
        ];
    }
}
