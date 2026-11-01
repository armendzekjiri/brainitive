<?php

namespace App\Http\Resources\AppApi;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A release an issue's "Fix version" can be set to (unreleased or released, not archived).
 *
 * @mixin Release
 */
class ReleaseOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            // unreleased | released
            'status' => (string) $this->status,
            'release_date' => $this->resource->release_date?->format('Y-m-d'),
        ];
    }
}
