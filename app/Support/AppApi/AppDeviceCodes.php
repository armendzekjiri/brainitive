<?php

namespace App\Support\AppApi;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Device-code sign-in (RFC 8628, BRAI-438) for apps without a usable browser (the TV app), kept in
 * the cache like AppAuthCodes:
 *  - `device_code`: 64 random characters, held by the device; only its sha256 is a cache key.
 *  - `user_code`: 8 letters of a vowel-free alphabet without look-alikes (BCDFGHJKLMNPQRSTVWXZ, 20^8),
 *    shown as XXXX-XXXX on the screen; the user confirms it at /app/device. Single use: it can be
 *    approved or denied once, then it no longer resolves.
 * Every change (decide, poll) runs under a cache lock, so two clicks or two polls never both win.
 */
final class AppDeviceCodes
{
    /** RFC 8628 §6.1: consonants only (no words, no 0/O, 1/I/L confusion beyond L itself). */
    public const ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const USED = 'used';

    /** Poll results (DeviceAuthController maps them to RFC 8628 errors). */
    public const POLL_INVALID = 'invalid';

    public const POLL_EXPIRED = 'expired';

    public const POLL_PENDING = 'pending';

    public const POLL_SLOW_DOWN = 'slow_down';

    public const POLL_DENIED = 'denied';

    public const POLL_APPROVED = 'approved';

    /** A finished code stays known this long after it expires, so the device hears `expired_token`. */
    private const GRACE = 600;

    /**
     * Start a sign-in for a device. `$ip`: where the request came from — the confirm page shows its coarse
     * prefix and the age of the request, so a user can spot a code that isn't from their own TV (BRAI-377).
     *
     * @param  array{name: string, platform: string, app_version: string, os_version?: string|null}  $device
     * @return array{device_code: string, user_code: string, expires_in: int, interval: int}
     */
    public static function start(string $client, array $device, ?string $ip = null): array
    {
        $ttl = (int) config('app-api.device_code_ttl');
        $interval = (int) config('app-api.device_code_interval');
        $deviceCode = Str::random(64);
        $hash = self::hash($deviceCode);

        $userCode = null;
        for ($attempt = 0; $attempt < 5 && $userCode === null; $attempt++) {
            $candidate = self::randomUserCode();
            if (Cache::add(self::userKey($candidate), $hash, $ttl)) {
                $userCode = $candidate;
            }
        }
        if ($userCode === null) {
            throw new RuntimeException('Could not allocate a unique device user code.');
        }

        self::save($hash, [
            'status' => self::PENDING,
            'client' => $client,
            'device' => [
                'name' => $device['name'],
                'platform' => $device['platform'],
                'app_version' => $device['app_version'],
                'os_version' => $device['os_version'] ?? null,
            ],
            'user_code' => $userCode,
            'ip' => $ip,
            'created_at' => now()->getTimestamp(),
            'user_id' => null,
            'interval' => $interval,
            'expires_at' => now()->getTimestamp() + $ttl,
            'last_poll_at' => null,
        ]);

        return ['device_code' => $deviceCode, 'user_code' => self::format($userCode), 'expires_in' => $ttl, 'interval' => $interval];
    }

    /**
     * The open sign-in a typed / scanned user code belongs to (for the confirm page), or null when
     * the code is unknown, expired or already decided.
     *
     * @return array<string, mixed>|null
     */
    public static function pending(?string $userCode): ?array
    {
        $hash = self::hashForUserCode($userCode);
        $payload = $hash === null ? null : Cache::get(self::key($hash));

        return is_array($payload) && $payload['status'] === self::PENDING && ! self::expired($payload) ? $payload : null;
    }

    /**
     * Approve or deny the open sign-in of a user code, once. Null when it is unknown, expired or was
     * already decided (by anyone): the code then no longer resolves.
     *
     * @return array<string, mixed>|null the payload as decided
     */
    public static function decide(?string $userCode, User $user, bool $approve): ?array
    {
        $hash = self::hashForUserCode($userCode);
        if ($hash === null) {
            return null;
        }

        return self::locked($hash, function () use ($hash, $userCode, $user, $approve) {
            $payload = Cache::get(self::key($hash));
            if (! is_array($payload) || $payload['status'] !== self::PENDING || self::expired($payload)) {
                return null;
            }

            $payload['status'] = $approve ? self::APPROVED : self::DENIED;
            $payload['user_id'] = $user->id;
            // a "sign out everywhere" after this approval kills it (AppDeviceSignIn, Codex review)
            $payload[AppSignInGeneration::KEY] = AppSignInGeneration::current($user);
            self::save($hash, $payload);
            Cache::forget(self::userKey(self::normalize((string) $userCode)));

            return $payload;
        });
    }

    /**
     * The device asks whether the user decided (POST /auth/device/token). Enforces `interval`: asking
     * again too soon answers `slow_down` and adds 5 s to the interval (RFC 8628 §3.5). An approved
     * code is handed out once (then `used`).
     *
     * @return array{0: string, 1?: array<string, mixed>}
     */
    public static function poll(?string $deviceCode): array
    {
        if (! is_string($deviceCode) || strlen($deviceCode) !== 64) {
            return [self::POLL_INVALID];
        }
        $hash = self::hash($deviceCode);

        $result = self::locked($hash, function () use ($hash) {
            $payload = Cache::get(self::key($hash));
            if (! is_array($payload) || $payload['status'] === self::USED) {
                return [self::POLL_INVALID];
            }
            if (self::expired($payload)) {
                return [self::POLL_EXPIRED];
            }

            $now = (float) now()->format('U.u');
            $last = $payload['last_poll_at'];
            // one second of slack for network jitter on a client that waits exactly `interval`
            if (is_numeric($last) && $now - (float) $last < (int) $payload['interval'] - 1) {
                $payload['interval'] = (int) $payload['interval'] + 5;
                $payload['last_poll_at'] = $now;
                self::save($hash, $payload);

                return [self::POLL_SLOW_DOWN, $payload];
            }
            $payload['last_poll_at'] = $now;

            if ($payload['status'] === self::APPROVED) {
                $payload['status'] = self::USED;
                self::save($hash, $payload);

                return [self::POLL_APPROVED, $payload];
            }

            self::save($hash, $payload);

            return [$payload['status'] === self::DENIED ? self::POLL_DENIED : self::POLL_PENDING, $payload];
        });

        // another poll of the same code holds the lock right now: that is polling too fast
        return $result ?? [self::POLL_SLOW_DOWN];
    }

    /**
     * The coarse origin of a request for the confirm page: an IPv4 address without its last part
     * (`203.0.113.x`), an IPv6 address as its first three groups (`2001:db8:85a3:x`, the /48). Never the
     * full address; null when unknown.
     */
    public static function coarseIp(?string $ip): ?string
    {
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.x';
        }
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $groups = array_map(fn (string $hex) => ltrim($hex, '0') ?: '0', str_split(bin2hex(substr($packed, 0, 6)), 4));

                return implode(':', $groups).':x';
            }
        }

        return null;
    }

    /** "BCDFGHJK" → "BCDF-GHJK" (how the TV and the confirm page show it). */
    public static function format(string $userCode): string
    {
        $code = self::normalize($userCode);

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /** What the user typed → the 8 code letters: case, spaces and dashes don't matter. */
    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $input));
    }

    public static function isWellFormed(?string $userCode): bool
    {
        return is_string($userCode) && preg_match('/^['.self::ALPHABET.']{8}$/', self::normalize($userCode)) === 1;
    }

    private static function hashForUserCode(?string $userCode): ?string
    {
        if (! self::isWellFormed($userCode)) {
            return null;
        }
        $hash = Cache::get(self::userKey(self::normalize((string) $userCode)));

        return is_string($hash) ? $hash : null;
    }

    private static function randomUserCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** @param  array<string, mixed>  $payload */
    private static function expired(array $payload): bool
    {
        return now()->getTimestamp() >= (int) $payload['expires_at'];
    }

    /** @param  array<string, mixed>  $payload */
    private static function save(string $hash, array $payload): void
    {
        Cache::put(self::key($hash), $payload, max(1, (int) $payload['expires_at'] + self::GRACE - now()->getTimestamp()));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null null when another request holds the lock
     */
    private static function locked(string $hash, callable $callback): mixed
    {
        $result = Cache::lock(self::key($hash).':lock', 5)->get($callback);

        return $result === false ? null : $result;
    }

    private static function hash(string $deviceCode): string
    {
        return hash('sha256', $deviceCode);
    }

    private static function key(string $hash): string
    {
        return 'app-device-code:'.$hash;
    }

    private static function userKey(string $normalizedUserCode): string
    {
        return 'app-device-user-code:'.hash('sha256', $normalizedUserCode);
    }
}
