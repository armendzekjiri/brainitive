<?php

namespace App\Http\Resources\AppApi;

use App\Models\AppDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The token response of exchange + refresh (contract: docs/ai/tasks/release-v1.49.0.md). Unwrapped:
 * the fields sit at the top level, as OAuth clients expect.
 *
 * @property-read array{tokens: array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int}, device: AppDevice} $resource
 */
class TokenResource extends JsonResource
{
    public static $wrap = null;

    /** @param  array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int}  $tokens */
    public static function for(array $tokens, AppDevice $device): self
    {
        return new self(['tokens' => $tokens, 'device' => $device]);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tokens = $this->resource['tokens'];
        $device = $this->resource['device'];

        return [
            'token_type' => $tokens['token_type'],
            'access_token' => $tokens['access_token'],
            'expires_in' => $tokens['expires_in'],
            'refresh_token' => $tokens['refresh_token'],
            'refresh_expires_in' => $tokens['refresh_expires_in'],
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'platform' => $device->platform,
            ],
        ];
    }
}
