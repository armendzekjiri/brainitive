<?php

namespace App\Http\Resources\AppApi;

use App\Support\Live\LiveConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A live channel token (BRAI-426): `LiveToken::grant()`, the same answer the web's tabs get, with an
 * absolute URL and an ISO expiry.
 *
 * @property-read array{token: string, expires_at: int, channels: list<string>, denied: list<string>, user: array{id: int, name: string, avatar: string|null}} $resource
 */
class RealtimeTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $grant = $this->resource;

        return [
            // send {"type": "auth", "token": …} as the socket's first message (never in the URL)
            'token' => (string) $grant['token'],
            // join before this; the server re-checks access every minute after that
            'expires_at' => Carbon::createFromTimestampUTC($grant['expires_at'])->toIso8601ZuluString(),
            // seconds until expires_at
            'expires_in' => (int) max(0, $grant['expires_at'] - time()),
            // the WebSocket URL (ws:// or wss://)
            'url' => LiveConfig::absoluteUrl(),
            /** @var list<string> the channels this token lets you subscribe to */
            'channels' => array_values($grant['channels']),
            /** @var list<string> requested channels you may not join (unknown, gone, or not visible to you) */
            'denied' => array_values($grant['denied']),
            /** @var array{id: int, name: string, avatar: string|null} how others see you in presence */
            'user' => $grant['user'],
        ];
    }
}
