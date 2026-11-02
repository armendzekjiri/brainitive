<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\DeviceTokenRequest;
use App\Http\Requests\AppApi\StartDeviceAuthRequest;
use App\Http\Resources\AppApi\DeviceAuthorizationResource;
use App\Http\Resources\AppApi\TokenResource;
use App\Models\User;
use App\Support\AppApi\AppApiErrors;
use App\Support\AppApi\AppDeviceCodes;
use App\Support\AppApi\AppDeviceSignIn;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * App API v1 — device-code sign-in (RFC 8628, BRAI-438) for apps without a usable browser (the TV
 * app, client `tv`). The device shows a code; the user confirms it at /app/device on a phone or
 * computer where they are signed in (App\Http\Controllers\AppDeviceApprovalController); the device
 * polls for its tokens. Desktop and mobile keep the PKCE flow (/app/authorize + /auth/exchange).
 */
#[Group('Auth', weight: 20)]
class DeviceAuthController extends Controller
{
    /**
     * Start a device-code sign-in.
     *
     * Answers (unwrapped, RFC 8628 §3.2) the `device_code` to poll with, the `user_code` to show
     * (XXXX-XXXX), the page to type it on (`verification_uri`), that page with the code filled in for
     * a QR (`verification_uri_complete`), `expires_in` (seconds, 600) and the polling `interval`
     * (seconds, 5). Only clients that sign in with a device code (`tv`); the platform must be the
     * client's (`android_tv`), else 422.
     *
     * @unauthenticated
     */
    public function start(StartDeviceAuthRequest $request): DeviceAuthorizationResource
    {
        return new DeviceAuthorizationResource(
            AppDeviceCodes::start($request->string('client')->toString(), $request->validated('device'), $request->ip()),
        );
    }

    /**
     * Poll for the tokens of a device-code sign-in.
     *
     * Once the user approved: the same token response as `/auth/exchange` (unwrapped, with `device`),
     * once. Until then a 400 with an RFC 8628 `code`: `authorization_pending` (ask again after
     * `interval`), `slow_down` (asked too soon: wait 5 s longer from now on), `access_denied` (the user
     * said no, or signed out everywhere after approving: stop), `expired_token` (start again), `invalid_grant` (unknown or already used code).
     *
     * @unauthenticated
     */
    public function token(DeviceTokenRequest $request): TokenResource|JsonResponse
    {
        $result = AppDeviceCodes::poll($request->string('device_code')->toString());

        return match ($result[0]) {
            AppDeviceCodes::POLL_PENDING => AppApiErrors::json(400, 'Waiting for the code to be confirmed on another device.', code: 'authorization_pending'),
            AppDeviceCodes::POLL_SLOW_DOWN => $this->slowDown((int) ($result[1]['interval'] ?? 10)),
            AppDeviceCodes::POLL_DENIED => AppApiErrors::json(400, 'The sign-in was denied.', code: 'access_denied'),
            AppDeviceCodes::POLL_EXPIRED => AppApiErrors::json(400, 'The code has expired. Start signing in again.', code: 'expired_token'),
            AppDeviceCodes::POLL_APPROVED => $this->signIn($result[1] ?? [], $request->ip()),
            default => AppApiErrors::json(400, 'The device code is invalid or was already used. Start signing in again.', code: 'invalid_grant'),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function signIn(array $payload, ?string $ip): TokenResource|JsonResponse
    {
        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        // suspended between the approval and the poll: no tokens
        if (! $user || $user->isSuspended()) {
            return AppApiErrors::json(400, 'The sign-in was denied.', code: 'access_denied');
        }

        $signIn = AppDeviceSignIn::create($user, (string) $payload['client'], $payload, $payload['device'], $ip);
        // the user signed out everywhere (password change / reset …) after approving: as a denial (Codex review)
        if ($signIn === null) {
            return AppApiErrors::json(400, 'The sign-in was denied.', code: 'access_denied');
        }
        [$device, $tokens] = $signIn;

        return TokenResource::for($tokens, $device);
    }

    /** Polled too soon: `interval` is the new wait in seconds (RFC 8628 §3.5: +5 s each time). */
    private function slowDown(int $interval): JsonResponse
    {
        return response()->json([
            'message' => "Polling too fast: wait {$interval} seconds between requests.",
            'code' => 'slow_down',
            'interval' => $interval,
        ], 400);
    }
}
