<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The badge count: unread notifications in the inbox (archived ones never count).
 *
 * @property-read int $resource
 */
class UnreadCountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['unread_count' => (int) $this->resource];
    }
}
