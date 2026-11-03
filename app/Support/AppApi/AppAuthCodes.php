<?php

namespace App\Support\AppApi;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Single-use, 60-second codes of the native sign-in (BRAI-355 / BRAI-428), kept in the cache:
 *  - `exchange`: /app/authorize → brainitive://auth/callback?code=… → POST /api/v1/auth/exchange,
 *    bound to the PKCE challenge;
 *  - `web_session`: POST /api/v1/auth/web-session → the webview opens /app/session/{code}.
 * Only the sha256 of a code is used as the key; a code never reaches the logs or the database.
 * An `exchange` code carries the user's AppSignInGeneration from when it was issued (= approved), so a
 * "sign out everywhere" before the exchange kills it (Codex review).
 */
final class AppAuthCodes
{
    public const EXCHANGE = 'exchange';

    public const WEB_SESSION = 'web_session';

    /** @param  array<string, mixed>  $payload */
    public static function issue(string $purpose, array $payload): string
    {
        $code = Str::random(64);
        if ($purpose === self::EXCHANGE && isset($payload['user_id']) && ! array_key_exists(AppSignInGeneration::KEY, $payload)) {
            $payload[AppSignInGeneration::KEY] = (int) User::query()->whereKey((int) $payload['user_id'])->value('app_auth_generation');
        }
        Cache::put(self::key($purpose, $code), $payload, (int) config('app-api.code_ttl'));

        return $code;
    }

    /**
     * The payload of a valid code, once. A second call with the same code — even a concurrent one —
     * gets null: the `used` marker is an atomic add.
     *
     * @return array<string, mixed>|null
     */
    public static function consume(string $purpose, ?string $code): ?array
    {
        if (! is_string($code) || strlen($code) !== 64) {
            return null;
        }

        $key = self::key($purpose, $code);
        if (! Cache::add($key.':used', true, (int) config('app-api.code_ttl') * 2)) {
            return null;
        }

        $payload = Cache::pull($key);

        return is_array($payload) ? $payload : null;
    }

    /** PKCE S256: base64url(sha256(verifier)) without padding. */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function key(string $purpose, string $code): string
    {
        return 'app-code:'.$purpose.':'.hash('sha256', $code);
    }
}
