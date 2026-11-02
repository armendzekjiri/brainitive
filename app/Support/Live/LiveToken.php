<?php

namespace App\Support\Live;

use App\Models\User;

/**
 * Short-lived token to join live channels (BRAI-151), same scheme as CollabToken:
 * `base64url(json payload) . "." . base64url(HMAC-SHA256(first part, LiveConfig::tokenKey()))`.
 * Payload: `v` (1), `typ` ("live"), `sub` (user id), `name`, `avatar` (URL or null), `ch` (the
 * channels Brainitive authorized), `iat`, `exp` (unix), and `dev` (the app device, App API tokens only:
 * the realtime server closes that device's sockets once it is revoked; the web's tokens have none).
 * Verified by `realtime/src/live/token.js` — keep both in sync.
 */
final class LiveToken
{
    /**
     * @param  list<string>  $channels  already authorized (LiveChannels::authorize)
     * @param  int|null  $device  the AppDevice of an App API client, null for the web
     * @return array{token: string, expires_at: int}
     */
    public static function issue(User $user, array $channels, ?int $now = null, ?int $device = null): array
    {
        $issued = $now ?? time();
        $expires = $issued + LiveConfig::tokenTtl();
        $avatar = $user->avatar_display;
        $payload = self::encode((string) json_encode([
            'v' => 1,
            'typ' => 'live',
            'sub' => $user->id,
            // the app device: its sockets are closed when it is revoked (AppTokens::revoke)
            ...($device !== null ? ['dev' => $device] : []),
            'name' => mb_substr((string) $user->name, 0, 80),
            'avatar' => is_string($avatar) && strlen($avatar) <= 500 ? $avatar : null,
            'ch' => array_values($channels),
            // the realtime server ignores channels revoked after this moment
            'iat' => $issued,
            'exp' => $expires,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['token' => $payload.'.'.self::encode(hash_hmac('sha256', $payload, LiveConfig::tokenKey(), true)), 'expires_at' => $expires];
    }

    /**
     * The answer of a token request — the web's `live.token` and the App API's `POST /realtime/token`
     * alike: a token for the channels of `$requested` the user may join (LiveChannels, the policies),
     * the rest in `denied` (never a 403: one token covers a whole page's channels). `$device`: the
     * App API's device (AppTokens::currentDevice), written into the token as `dev`; null for the web.
     *
     * @param  array<int, mixed>  $requested
     * @return array{token: string, expires_at: int, url: string, channels: list<string>, denied: list<string>, user: array{id: int, name: string, avatar: string|null}}
     */
    public static function grant(User $user, array $requested, ?int $device = null): array
    {
        $requested = array_values(array_unique(array_filter($requested, 'is_string')));
        $allowed = (new LiveChannels)->authorize($user, $requested);
        $avatar = $user->avatar_display;

        return self::issue($user, $allowed, device: $device) + [
            'url' => LiveConfig::url(),
            'channels' => $allowed,
            'denied' => array_values(array_diff($requested, $allowed)),
            'user' => ['id' => (int) $user->id, 'name' => (string) $user->name, 'avatar' => is_string($avatar) ? $avatar : null],
        ];
    }

    /**
     * The payload of a valid, unexpired token; null otherwise (used by tests).
     *
     * @return array{v: int, typ: string, sub: int, dev?: int, name: string, avatar: ?string, ch: list<string>, iat: int, exp: int}|null
     */
    public static function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals(self::encode(hash_hmac('sha256', $payload, LiveConfig::tokenKey(), true)), $signature)) {
            return null;
        }
        $data = json_decode((string) self::decode($payload), true);
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ($data['typ'] ?? null) !== 'live'
            || ! is_int($data['exp'] ?? null) || $data['exp'] <= ($now ?? time())) {
            return null;
        }

        return $data;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
