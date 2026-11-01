<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ExchangeCodeRequest;
use App\Http\Requests\AppApi\RefreshTokenRequest;
use App\Http\Resources\AppApi\TokenResource;
use App\Http\Resources\AppApi\WebSessionResource;
use App\Models\AppDevice;
use App\Models\User;
use App\Notifications\AppRefreshReuseNotification;
use App\Support\AppApi\AppApiErrors;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * App API v1 — native sign-in and the token lifecycle (BRAI-383 / BRAI-428). The browser half
 * (/app/authorize, /app/session/{code}) is App\Http\Controllers\AppAuthController.
 */
class AuthController extends Controller
{
    /** Which platforms each first-party client runs on. */
    private const CLIENT_PLATFORMS = [
        'desktop' => ['macos', 'windows', 'linux'],
        'mobile' => ['ios', 'android'],
    ];

    /**
     * Exchange the one-time code from the consent page for the first token pair.
     *
     * Creates the device (signing out the least recently used one beyond the per-user limit).
     * A wrong, used or expired code, or a verifier that doesn't match its challenge → 400 `invalid_grant`.
     *
     * @unauthenticated
     */
    public function exchange(ExchangeCodeRequest $request): TokenResource|JsonResponse
    {
        $payload = AppAuthCodes::consume(AppAuthCodes::EXCHANGE, $request->string('code')->toString());
        $challenge = $payload['code_challenge'] ?? null;

        if (! is_string($challenge)
            || ! hash_equals($challenge, AppAuthCodes::challengeFor($request->string('code_verifier')->toString()))) {
            return $this->invalidGrant(400, 'The sign-in code is invalid or has expired. Please sign in again.');
        }

        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        $client = (string) ($payload['client'] ?? '');
        if (! $user || $user->isSuspended() || ! isset(self::CLIENT_PLATFORMS[$client])) {
            return $this->invalidGrant(400, 'The sign-in code is invalid or has expired. Please sign in again.');
        }

        $platform = $request->string('device.platform')->toString();
        if (! in_array($platform, self::CLIENT_PLATFORMS[$client], true)) {
            throw ValidationException::withMessages(['device.platform' => ['This platform does not belong to the '.$client.' app.']]);
        }

        [$device, $tokens] = DB::transaction(function () use ($request, $user, $client, $platform) {
            // one sign-in at a time per user, so the device limit holds under concurrent exchanges
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $device = AppDevice::create([
                'user_id' => $user->id,
                'client' => $client,
                'platform' => $platform,
                'name' => $request->string('device.name')->toString(),
                'app_version' => $request->string('device.app_version')->toString(),
                'os_version' => $request->input('device.os_version'),
                'last_used_at' => now(),
                'last_ip' => $request->ip(),
            ]);

            $this->enforceDeviceLimit($user, $device);

            return [$device, AppTokens::issue($device)];
        });

        return TokenResource::for($tokens, $device);
    }

    /**
     * Rotate the token pair. The refresh token works once; presenting an already-used one again
     * signs the whole device out (reuse = a stolen copy) and emails the user. Clients must send
     * one refresh at a time: two parallel refreshes with the same token count as reuse.
     *
     * @unauthenticated
     */
    public function refresh(RefreshTokenRequest $request): TokenResource|JsonResponse
    {
        $token = $request->string('refresh_token')->toString();
        if (! AppTokens::looksLike($token, AppTokens::REFRESH_PREFIX)) {
            return $this->invalidGrant(401, 'The refresh token is invalid. Please sign in again.');
        }

        $hash = AppTokens::hash($token);

        /** @var array{0: string, 1?: AppDevice, 2?: array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int}} $result */
        $result = DB::transaction(function () use ($hash, $request) {
            $device = AppDevice::query()->active()->where('refresh_token_hash', $hash)->lockForUpdate()->first();
            if ($device) {
                if (! $device->refresh_expires_at?->isFuture() || $device->user?->isSuspended() !== false) {
                    return ['invalid'];
                }

                $device->forceFill(['last_used_at' => now(), 'last_ip' => $request->ip()]);

                return ['ok', $device, AppTokens::issue($device)];
            }

            $reused = AppDevice::query()->active()->where('previous_refresh_hash', $hash)->lockForUpdate()->first();
            if ($reused) {
                AppTokens::revoke($reused, 'refresh_reuse');

                return ['reuse', $reused];
            }

            return ['invalid'];
        });

        if ($result[0] === 'ok') {
            return TokenResource::for($result[2], $result[1]);
        }

        if ($result[0] === 'reuse') {
            $this->warnAboutReuse($result[1]);
        }

        return $this->invalidGrant(401, 'The refresh token is invalid. Please sign in again.');
    }

    /** Sign this device out: its tokens stop working at once. */
    public function logout(Request $request): Response
    {
        $device = AppTokens::currentDevice($request);
        if ($device) {
            AppTokens::revoke($device, 'sign_out');
        }

        return response()->noContent();
    }

    /**
     * A one-time link (60 s) that signs the app's webview into the web app, remembered. Append
     * `?to=/path` (a relative path on this site) to land somewhere other than the dashboard.
     */
    public function webSession(Request $request): WebSessionResource
    {
        $device = AppTokens::currentDevice($request);

        $code = AppAuthCodes::issue(AppAuthCodes::WEB_SESSION, [
            'user_id' => $request->user()->id,
            'device_id' => $device?->id,
        ]);

        return new WebSessionResource(route('app.session', ['code' => $code]));
    }

    /** Over `max_devices` active installs: sign out the least recently used ones (never the new one). */
    private function enforceDeviceLimit(User $user, AppDevice $new): void
    {
        $max = max(1, (int) config('app-api.max_devices'));

        $user->appDevices()->active()
            ->whereKeyNot($new->id)
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->slice($max - 1)
            ->each(fn (AppDevice $device) => AppTokens::revoke($device, 'limit'));
    }

    private function warnAboutReuse(AppDevice $device): void
    {
        try {
            $device->user?->notify(new AppRefreshReuseNotification($device));
        } catch (Throwable $e) {
            // the device is signed out either way; a mail failure must not turn the 401 into a 500
            report($e);
        }
    }

    private function invalidGrant(int $status, string $message): JsonResponse
    {
        return AppApiErrors::json($status, $message, code: 'invalid_grant');
    }
}
