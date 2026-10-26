<?php

namespace App\Support\Collab;

use App\Models\User;

/**
 * Short-lived connection token for the realtime server (BRAI-54):
 * `base64url(json payload) . "." . base64url(HMAC-SHA256(first part, CollabConfig::tokenKey()))`.
 * Payload: `v` (1), `sub` (user id), `name`, `doc` (the one document it opens), `edit`, `exp` (unix).
 * Verified by `realtime/src/token.js` — keep both in sync.
 */
final class CollabToken
{
    /**
     * @return array{token: string, expires_at: int}
     */
    public static function issue(User $user, string $document, bool $edit, ?int $now = null): array
    {
        $expires = ($now ?? time()) + CollabConfig::tokenTtl();
        $payload = self::encode((string) json_encode([
            'v' => 1,
            'sub' => $user->id,
            'name' => $user->name,
            'doc' => $document,
            'edit' => $edit,
            'exp' => $expires,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['token' => $payload.'.'.self::encode(hash_hmac('sha256', $payload, CollabConfig::tokenKey(), true)), 'expires_at' => $expires];
    }

    /**
     * The payload of a valid, unexpired token; null otherwise.
     *
     * @return array{v: int, sub: int, name: string, doc: string, edit: bool, exp: int}|null
     */
    public static function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals(self::encode(hash_hmac('sha256', $payload, CollabConfig::tokenKey(), true)), $signature)) {
            return null;
        }
        $data = json_decode((string) self::decode($payload), true);
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_int($data['exp'] ?? null) || $data['exp'] <= ($now ?? time())) {
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
