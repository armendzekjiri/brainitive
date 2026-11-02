<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project to capture into: `id` is the page destination (when `can_create_pages`), its boards the
 * issue destinations, its task lists the task destinations.
 *
 * @property-read array<string, mixed> $resource
 */
class CaptureDestinationProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'key' => self::text($this->resource['key'] ?? null),
            'icon' => self::text($this->resource['icon'] ?? null),
            'color' => self::text($this->resource['color'] ?? null),
            'can_create_pages' => (bool) $this->resource['can_create_pages'],
            'boards' => CaptureDestinationBoardResource::collection($this->resource['boards']),
            'task_lists' => CaptureDestinationOptionResource::collection($this->resource['task_lists']),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
