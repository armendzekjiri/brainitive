<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/auth/device (RFC 8628 §3.2): unwrapped like the token responses, so OAuth device
 * clients read it as is. The device shows `user_code` and a QR of `verification_uri_complete`, then
 * polls POST /auth/device/token every `interval` seconds until `expires_in` runs out.
 *
 * @property-read array{device_code: string, user_code: string, expires_in: int, interval: int} $resource
 */
class DeviceAuthorizationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // secret of the device: send it back to /auth/device/token, never show it
            'device_code' => (string) $this->resource['device_code'],
            // what the screen shows, XXXX-XXXX
            'user_code' => (string) $this->resource['user_code'],
            // where to type it: {instance}/app/device
            'verification_uri' => route('app.device'),
            // the same page with the code filled in (the QR); still needs the user's click
            'verification_uri_complete' => route('app.device', ['code' => $this->resource['user_code']]),
            // seconds until the code stops working
            'expires_in' => (int) $this->resource['expires_in'],
            // seconds to wait between two polls
            'interval' => (int) $this->resource['interval'],
        ];
    }
}
