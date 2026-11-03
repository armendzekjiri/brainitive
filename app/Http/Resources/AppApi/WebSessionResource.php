<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/auth/web-session: `{data: {url}}`, the one-time link the webview opens (60 s,
 * single use). Wrapped like every App API resource; only the token responses are not.
 *
 * @property-read string $resource
 */
class WebSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['url' => $this->resource];
    }
}
