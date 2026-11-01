<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A named destination (task list, bookmark group, snippet collection, board column). `icon` only for
 * bookmark groups.
 *
 * @property-read array<string, mixed> $resource
 */
class CaptureDestinationOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'icon' => self::text($this->resource['icon'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
