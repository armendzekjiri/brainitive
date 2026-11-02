<?php

namespace App\Http\Resources\AppApi;

use App\Models\DataExport;
use App\Models\User;
use App\Support\Profile\DataExports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state of "Download my data" (BRAI-394): the newest export (null before the first request),
 * the last few, and when a new one may be requested (one per hour).
 *
 * @property-read User $resource
 */
class DataExportStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $exports = DataExports::recent($this->resource);
        $latest = $exports[0] ?? null;

        return [
            'latest' => $latest instanceof DataExport ? new DataExportResource($latest) : null,
            'exports' => DataExportResource::collection($exports),
            // seconds until a new request is allowed (0 = now)
            'available_in' => DataExports::availableIn($this->resource),
        ];
    }
}
