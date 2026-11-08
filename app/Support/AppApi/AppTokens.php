<?php

namespace App\Support\AppApi;

use App\Models\AppDevice;
use App\Models\AppDeviceRefreshUse;
use App\Models\User;
use App\Support\ApiKeys\ApiKeys;
use App\Support\Live\Live;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tokens of the App API v1 (desktop / mobile, BRAI-428). Opaque random strings, stored as sha256
 * on the AppDevice row, one active pair per device:
 *  - access `bra_…`, 15 min, sent as `Authorization: Bearer` to /api/v1;
 *  - refresh `brr_…`, 60 days, single use: every refresh issues a new pair.
 *
 * Not Passport on purpose: Passport's TTLs are global (MCP / CLI keep 30 days) and it has no
 * refresh-reuse detection or device binding. These tokens aren't JWTs, so Passport (`/mcp`,
 * `/api/cli`, `/api/ext`) rejects them, and Passport tokens aren't in `app_devices`, so the `app`
 * guard rejects those.
 */
final class AppTokens
{
    public const ACCESS_PREFIX = 'bra_';

    public const REFRESH_PREFIX = 'brr_';

    /** Request attribute holding the AppDevice of the bearer token. */
    public const DEVICE_ATTRIBUTE = 'app_device';

    /**
     * Give the device a new pair (sign-in or refresh); the old pair stops working and its refresh
     * hash is kept to recognise reuse — every one the device used, not only the last (Codex review,
     * finding 7: AppDeviceRefreshUse). The plain tokens exist only in the returned array.
     *
     * @return array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int}
     */
    public static function issue(AppDevice $device): array
    {
        $access = self::ACCESS_PREFIX.Str::random(48);
        $refresh = self::REFRESH_PREFIX.Str::random(48);
        $accessTtl = (int) config('app-api.access_ttl');
        $refreshTtl = (int) config('app-api.refresh_ttl');

        if ($device->refresh_token_hash !== null && $device->exists) {
            AppDeviceRefreshUse::query()->insertOrIgnore(['app_device_id' => $device->id, 'token_hash' => $device->refresh_token_hash, 'created_at' => now()]);
        }

        $device->forceFill([
            'previous_refresh_hash' => $device->refresh_token_hash,
            'access_token_hash' => self::hash($access),
            'access_expires_at' => now()->addSeconds($accessTtl),
            'refresh_token_hash' => self::hash($refresh),
            'refresh_expires_at' => now()->addSeconds($refreshTtl),
        ])->save();

        return [
            'token_type' => 'Bearer',
            'access_token' => $access,
            'expires_in' => $accessTtl,
            'refresh_token' => $refresh,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /** The active, unexpired device an access token belongs to. */
    public static function deviceForAccessToken(?string $token): ?AppDevice
    {
        if (! self::looksLike($token, self::ACCESS_PREFIX)) {
            return null;
        }

        $device = AppDevice::query()
            ->where('access_token_hash', self::hash($token))
            ->whereNull('revoked_at')
            ->with('user')
            ->first();

        return $device && $device->access_expires_at?->isFuture() ? $device : null;
    }

    /** Resolver of the `app` guard (AppServiceProvider): the bearer's user, its device on the request. */
    public static function userFromRequest(Request $request): ?User
    {
        // personal API keys of the Developer API (`brai_…`, v1.52.0) share the guard
        if (ApiKeys::looksLike($request->bearerToken())) {
            return ApiKeys::userFromRequest($request);
        }

        $device = self::deviceForAccessToken($request->bearerToken());
        if (! $device) {
            return null;
        }

        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        // at most one write a minute per device
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subMinute())) {
            $device->forceFill(['last_used_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }

        return $device->user;
    }

    /** The device of the current `app` token (set by the guard). */
    public static function currentDevice(Request $request): ?AppDevice
    {
        $device = $request->attributes->get(self::DEVICE_ATTRIBUTE);

        return $device instanceof AppDevice ? $device : null;
    }

    /**
     * Sign the device out: both tokens stop working immediately, the realtime server closes the device's
     * live and collab sockets (pushed now, else at its next minute re-check), and the web session its
     * webview opened is logged out on its next request.
     */
    public static function revoke(AppDevice $device, string $reason): void
    {
        $device->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'access_token_hash' => null,
            'access_expires_at' => null,
            'refresh_token_hash' => null,
            'refresh_expires_at' => null,
            'push_token' => null, // signed out: never pushed to again (the hash follows on save)
        ])->save();

        // its used refresh tokens no longer matter: presenting one now is simply invalid
        AppDeviceRefreshUse::query()->where('app_device_id', $device->id)->delete();

        Live::deviceRevoked((int) $device->id);
        // the web session its webview opened ends on its next request (EndRevokedAppDeviceSessions); again
        // after commit, so a request racing the transaction can't cache the device as still active
        AppWebSessions::forget((int) $device->id);
        DB::afterCommit(fn () => AppWebSessions::forget((int) $device->id));
    }

    /**
     * Sign out one of the user's own active devices (remote sign-out, connected apps). False when it
     * isn't theirs / is gone. The calling device signing itself out is a normal sign-out.
     */
    public static function signOutDevice(User $user, int $deviceId, ?Request $request = null): bool
    {
        $target = $user->appDevices()->active()->whereKey($deviceId)->first();
        if (! $target instanceof AppDevice) {
            return false;
        }

        $current = $request !== null && (self::currentDevice($request)?->is($target) ?? false);
        self::revoke($target, $current ? 'sign_out' : 'user');

        return true;
    }

    /** Sign out every app install of a user (suspension, password change, "sign out everywhere"). */
    public static function revokeAllFor(User $user, string $reason): int
    {
        $devices = $user->appDevices()->active()->get();
        $devices->each(fn (AppDevice $device) => self::revoke($device, $reason));

        return $devices->count();
    }

    /**
     * Of the asked devices (device id → the user whose socket holds it), those that may no longer be
     * connected: revoked, deleted, or not that user's. The realtime server's minute re-checks ask this
     * (live channels and live co-editing) and close their sockets with 4410.
     *
     * @param  array<int, int>  $deviceUsers
     * @return list<int>
     */
    public static function revokedAmong(array $deviceUsers): array
    {
        if ($deviceUsers === []) {
            return [];
        }
        $active = AppDevice::query()->active()->whereIn('id', array_keys($deviceUsers))->pluck('user_id', 'id');

        return array_values(array_filter(
            array_keys($deviceUsers),
            fn (int $device) => (int) ($active[$device] ?? 0) !== $deviceUsers[$device],
        ));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @phpstan-assert-if-true string $token */
    public static function looksLike(?string $token, string $prefix): bool
    {
        return is_string($token) && strlen($token) <= 128 && str_starts_with($token, $prefix);
    }
}
