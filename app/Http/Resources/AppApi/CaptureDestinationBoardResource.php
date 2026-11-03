<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board to create an issue on (`fields.column_id` = one of its columns; none = the first one).
 *
 * @property-read array<string, mixed> $resource
 */
class CaptureDestinationBoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            // kanban | scrum
            'type' => (string) $this->resource['type'],
            'columns' => CaptureDestinationOptionResource::collection($this->resource['columns']),
        ];
    }
}
