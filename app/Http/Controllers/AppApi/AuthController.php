<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ExchangeCodeRequest;
use App\Http\Requests\AppApi\RefreshTokenRequest;
use App\Http\Resources\AppApi\TokenResource;
use App\Http\Resources\AppApi\WebSessionResource;
use App\Models\AppDevice;
use App\Models\AppDeviceRefreshUse;
use App\Models\User;
use App\Notifications\AppRefreshReuseNotification;
use App\Support\AppApi\AppApiErrors;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppClients;
use App\Support\AppApi\AppDeviceSignIn;
use App\Support\AppApi\AppTokens;
use Dedoc\Scramble\Attributes\Group;
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
#[Group('Auth', weight: 20)]
class AuthController extends Controller
{
    /**
     * Exchange the one-time code from the consent page for the first token pair.
     *
     * Creates the device (signing out the least recently used one beyond the per-user limit).
     * A wrong, used or expired code, a verifier that doesn't match its challenge, or a code approved before
     * the user signed out everywhere (password change / reset …) → 400 `invalid_grant`.
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
        if (! $user || $user->isSuspended() || ! in_array($client, AppClients::using(AppClients::AUTHORIZATION_CODE), true)) {
            return $this->invalidGrant(400, 'The sign-in code is invalid or has expired. Please sign in again.');
        }

        $platform = $request->string('device.platform')->toString();
        if (! AppClients::runsOn($client, $platform)) {
            throw ValidationException::withMessages(['device.platform' => ['This platform does not belong to the '.$client.' app.']]);
        }

        // null: the user signed out everywhere (password change / reset …) after approving (Codex review)
        $signIn = AppDeviceSignIn::create($user, $client, $payload, $request->validated('device'), $request->ip());
        if ($signIn === null) {
            return $this->invalidGrant(400, 'The sign-in code is invalid or has expired. Please sign in again.');
        }
        [$device, $tokens] = $signIn;

        return TokenResource::for($tokens, $device);
    }

    /**
     * Rotate the token pair. The refresh token works once; presenting an already-used one again —
     * any earlier one of this device, not only the last (reuse = a stolen copy) — signs this device out — its tokens, live connections and the web session its
     * webview opened — and emails the user. Clients must send one refresh at a time: two parallel refreshes
     * with the same token count as reuse.
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

            // any refresh token the device already used (Codex review: not only the last one), else the last one of a
            // pair issued before that table existed
            $reused = AppDevice::query()->active()
                ->where(fn ($q) => $q->where('previous_refresh_hash', $hash)
                    ->orWhereIn('id', AppDeviceRefreshUse::query()->select('app_device_id')->where('token_hash', $hash)))
                ->lockForUpdate()->first();
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
            // the device is revoked (in the transaction): its tokens, live / collab sockets and the web session
            // its webview opened end with it (EndRevokedAppDeviceSessions). Only that device: a client that
            // refreshed twice in parallel must not sign the user out everywhere.
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
