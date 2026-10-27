<?php

namespace App\Support\Live;

use App\Models\User;

/**
 * Short-lived token to join live channels (BRAI-151), same scheme as CollabToken:
 * `base64url(json payload) . "." . base64url(HMAC-SHA256(first part, LiveConfig::tokenKey()))`.
 * Payload: `v` (1), `typ` ("live"), `sub` (user id), `name`, `avatar` (URL or null), `ch` (the
 * channels Brainitive authorized), `iat`, `exp` (unix). Verified by `realtime/src/live/token.js` —
 * keep both in sync.
 */
final class LiveToken
{
    /**
     * @param  list<string>  $channels  already authorized (LiveChannels::authorize)
     * @return array{token: string, expires_at: int}
     */
    public static function issue(User $user, array $channels, ?int $now = null): array
    {
        $issued = $now ?? time();
        $expires = $issued + LiveConfig::tokenTtl();
        $avatar = $user->avatar_display;
        $payload = self::encode((string) json_encode([
            'v' => 1,
            'typ' => 'live',
            'sub' => $user->id,
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
     * The payload of a valid, unexpired token; null otherwise (used by tests).
     *
     * @return array{v: int, typ: string, sub: int, name: string, avatar: ?string, ch: list<string>, iat: int, exp: int}|null
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
