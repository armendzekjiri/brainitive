<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How many captures the user has per state (CaptureInbox::counts).
 *
 * @property-read array{inbox: int, processed: int, archived: int} $resource
 */
class CaptureCountsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'inbox' => (int) $this->resource['inbox'],
            'processed' => (int) $this->resource['processed'],
            'archived' => (int) $this->resource['archived'],
        ];
    }
}
