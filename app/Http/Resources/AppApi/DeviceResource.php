<?php

namespace App\Http\Resources\AppApi;

use App\Models\AppDevice;
use App\Support\AppApi\AppTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One signed-in app install (GET /api/v1/devices, Profile → "Desktop & mobile apps"). Never the
 * token hashes, the push token or the IP: `has_push_token` only says whether one is registered.
 *
 * @mixin AppDevice
 */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client' => $this->client,
            'platform' => $this->platform,
            'name' => $this->name,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'has_push_token' => $this->push_token !== null && $this->push_token !== '',
            // the device of the calling app token; always false on the web Profile page
            'current' => AppTokens::currentDevice($request)?->is($this->resource) ?? false,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
