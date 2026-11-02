<?php

namespace App\Support\AppApi;

use App\Models\AppDevice;
use Illuminate\Support\Facades\Cache;

/**
 * Web sessions opened by an app's webview (/app/session/{code}) are bound to the app device (BRAI-377): the
 * session holds `app_device_id`, and EndRevokedAppDeviceSessions logs it out once the device is revoked or
 * gone. Whether a device is still active is cached a minute per device (one indexed query); AppTokens::revoke
 * forgets it, so a revocation counts on the very next request.
 */
final class AppWebSessions
{
    public const SESSION_KEY = 'app_device_id';

    private const TTL = 60;

    /** Whether the device is active and belongs to the user. */
    public static function deviceActiveFor(int $deviceId, int $userId): bool
    {
        if ($deviceId < 1) {
            return false;
        }

        $owner = Cache::remember(self::cacheKey($deviceId), self::TTL, fn () => (int) (AppDevice::query()->active()->whereKey($deviceId)->value('user_id') ?? 0));

        return (int) $owner === $userId;
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::cacheKey($deviceId));
    }

    private static function cacheKey(int $deviceId): string
    {
        return 'app-device-web-session:'.$deviceId;
    }
}
