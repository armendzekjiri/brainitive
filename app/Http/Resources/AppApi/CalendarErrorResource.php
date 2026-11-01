<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected calendar that could not be loaded this time (its `sync_error` also shows in
 * GET /calendar/connections).
 *
 * @property-read array{connection_id: int, message: string} $resource
 */
class CalendarErrorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'connection_id' => (int) $this->resource['connection_id'],
            // why, e.g. "Reconnect this calendar."
            'message' => (string) $this->resource['message'],
        ];
    }
}
