<?php

namespace App\Support\AppApi;

use App\Models\AppDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The last step of every app sign-in (PKCE exchange and device code): create the device row, sign
 * out the least recently used installs beyond `max_devices`, issue the first token pair.
 */
final class AppDeviceSignIn
{
    /**
     * @param  array{name: string, platform: string, app_version: string, os_version?: string|null}  $device
     * @return array{0: AppDevice, 1: array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int}}
     */
    public static function create(User $user, string $client, array $device, ?string $ip): array
    {
        return DB::transaction(function () use ($user, $client, $device, $ip) {
            // one sign-in at a time per user, so the device limit holds under concurrent sign-ins
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $row = AppDevice::create([
                'user_id' => $user->id,
                'client' => $client,
                'platform' => $device['platform'],
                'name' => $device['name'],
                'app_version' => $device['app_version'],
                'os_version' => $device['os_version'] ?? null,
                'last_used_at' => now(),
                'last_ip' => $ip,
            ]);

            self::enforceDeviceLimit($user, $row);

            return [$row, AppTokens::issue($row)];
        });
    }

    /** Over `max_devices` active installs: sign out the least recently used ones (never the new one). */
    private static function enforceDeviceLimit(User $user, AppDevice $new): void
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
}
