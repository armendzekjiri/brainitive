<?php

namespace App\Support\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sign in with Apple (BRAI-449): Apple's OAuth / OpenID Connect flow, hand-written like
 * MicrosoftLogin (no Socialite provider). Config `services.apple`.
 *
 *  - The authorize request asks for `name email` with `response_mode=form_post`: Apple POSTs the
 *    result cross-site to the callback (see AppleAuthController for why that route has no session).
 *  - The client secret is an ES256 JWT (iss = team id, sub = Services ID, aud = Apple) signed at
 *    runtime with the "Sign in with Apple" .p8 key; it lives 5 minutes.
 *  - The id_token's signature is verified against Apple's JWKS (cached an hour, refetched for an
 *    unknown `kid`), then iss / aud / exp / nonce / sub.
 *  - id = `sub` (stable per developer team). The email is trusted only with `email_verified` —
 *    private relay addresses (@privaterelay.appleid.com) included: Apple verified and forwards them.
 *  - The name is never in the id_token: Apple sends it once, in the first callback's `user` field.
 *  - The refresh token of the exchange is kept (encrypted) only to revoke the authorization when the
 *    account is deleted (App Store guideline 5.1.1(v)) — see App\Jobs\RevokeAppleToken.
 */
class AppleLogin
{
    public const ISSUER = 'https://appleid.apple.com';

    public const AUTHORIZE_URL = 'https://appleid.apple.com/auth/authorize';

    public const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public const KEYS_URL = 'https://appleid.apple.com/auth/keys';

    public const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    private const JWKS_CACHE = 'auth:apple:jwks';

    /** Only with a Services ID, team, key id and a readable private key configured. */
    public static function available(): bool
    {
        return filled(config('services.apple.client_id'))
            && filled(config('services.apple.team_id'))
            && filled(config('services.apple.key_id'))
            && self::privateKey() !== '';
    }

    /** Apple's sign-in page, with state and nonce. */
    public function authorizeUrl(string $state, string $nonce): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('services.apple.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'response_mode' => 'form_post', // required by Apple when asking for name / email
            'scope' => 'name email',
            'state' => $state,
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The client secret Apple's token endpoint wants: an ES256 JWT signed with the .p8 key. */
    public function clientSecret(): string
    {
        $now = time();

        return JWT::encode([
            'iss' => (string) config('services.apple.team_id'),
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => self::ISSUER,
            'sub' => (string) config('services.apple.client_id'),
        ], self::privateKey(), 'ES256', (string) config('services.apple.key_id'));
    }

    /**
     * Swap the code for tokens and read who signed in.
     *
     * @return array{id: string, email: string|null, private_email: bool, refresh_token: string|null}
     *
     * @throws AppleLoginFailed with a message for the user
     */
    public function identity(string $code, string $nonce): array
    {
        try {
            $secret = $this->clientSecret();
        } catch (Throwable $e) {
            Log::error('Sign in with Apple: the client secret could not be signed (check APPLE_PRIVATE_KEY / APPLE_KEY_ID)', ['error' => substr($e->getMessage(), 0, 200)]);

            throw new AppleLoginFailed('Sign in with Apple isn’t set up correctly on this server. Please use another way to sign in.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                    'client_id' => (string) config('services.apple.client_id'),
                    'client_secret' => $secret,
                ]);
        } catch (ConnectionException) {
            throw new AppleLoginFailed('Apple didn’t answer. Please try again.');
        }

        $idToken = $response->json('id_token');
        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            $error = $response->json('error');
            Log::warning('Apple sign-in code exchange failed', ['status' => $response->status(), 'error' => is_string($error) ? substr($error, 0, 80) : null]);

            throw new AppleLoginFailed('Apple didn’t accept the sign-in. Please try again.');
        }

        $claims = $this->claims($idToken, $nonce);
        if ($claims === null) {
            Log::warning('Apple sign-in id_token refused');

            throw new AppleLoginFailed('Something went wrong signing in with Apple. Please try again.');
        }

        $email = $claims['email'] ?? null;
        $trusted = is_string($email)
            && strlen($email) <= 255
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && self::isTrue($claims['email_verified'] ?? null);

        return [
            'id' => $claims['sub'],
            'email' => $trusted ? strtolower($email) : null,
            'private_email' => self::isTrue($claims['is_private_email'] ?? null),
            'refresh_token' => is_string($refresh = $response->json('refresh_token')) && $refresh !== '' && strlen($refresh) <= 2048 ? $refresh : null,
        ];
    }

    /**
     * The name from the first callback's `user` form field (`{"name": {"firstName", "lastName"},
     * "email"}`); null when absent — Apple sends it only the first time someone signs in.
     */
    public static function nameFromUserField(mixed $user): ?string
    {
        if (! is_string($user) || $user === '' || strlen($user) > 4096) {
            return null;
        }

        $data = json_decode($user, true);
        $name = is_array($data) && is_array($data['name'] ?? null) ? $data['name'] : null;
        if ($name === null) {
            return null;
        }

        $parts = array_filter([$name['firstName'] ?? null, $name['lastName'] ?? null], fn ($p) => is_string($p) && trim($p) !== '');
        $full = trim(preg_replace('/\s+/u', ' ', implode(' ', array_map('trim', $parts))) ?? '');

        return $full !== '' ? mb_substr($full, 0, 255) : null;
    }

    /**
     * The id_token payload when Apple signed it, for this app and this sign-in; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function claims(string $idToken, string $nonce): ?array
    {
        if (strlen($idToken) > 16384 || substr_count($idToken, '.') !== 2) {
            return null;
        }

        $header = json_decode((string) base64_decode(strtr(explode('.', $idToken)[0], '-_', '+/'), true), true);
        $kid = is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
        if ($kid === null || ($header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        $keys = $this->signingKeys($kid);
        if ($keys === []) {
            return null;
        }

        try {
            $claims = (array) JWT::decode($idToken, $keys); // signature, alg of the key, exp / nbf / iat
        } catch (Throwable) {
            return null;
        }

        $aud = $claims['aud'] ?? null;
        $sub = $claims['sub'] ?? null;
        $tokenNonce = $claims['nonce'] ?? null;

        if (($claims['iss'] ?? null) !== self::ISSUER
            || ! is_string($aud) || ! hash_equals((string) config('services.apple.client_id'), $aud)
            || ! is_int($claims['exp'] ?? null)
            || ! is_string($sub) || $sub === '' || strlen($sub) > 255
            || ! is_string($tokenNonce) || $nonce === '' || ! hash_equals($nonce, $tokenNonce)) {
            return null;
        }

        return $claims;
    }

    /**
     * Apple's current signing keys, parsed; refetched once when `$kid` isn't among the cached ones
     * (Apple rotates keys), at most once a minute.
     *
     * @return array<string, Key>
     */
    private function signingKeys(string $kid): array
    {
        $cached = Cache::get(self::JWKS_CACHE);
        $has = fn ($set) => is_array($set) && collect($set['jwks']['keys'] ?? [])->contains(fn ($k) => is_array($k) && ($k['kid'] ?? null) === $kid);

        if (! $has($cached) && (! is_array($cached) || ($cached['fetched_at'] ?? 0) < time() - 60)) {
            $fresh = $this->fetchJwks();
            if ($fresh !== null) {
                $cached = ['jwks' => $fresh, 'fetched_at' => time()];
                Cache::put(self::JWKS_CACHE, $cached, 3600);
            }
        }

        if (! $has($cached)) {
            return [];
        }

        try {
            return JWK::parseKeySet($cached['jwks'], 'RS256');
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{keys: array<int, mixed>}|null */
    private function fetchJwks(): ?array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(15)->get(self::KEYS_URL);
        } catch (ConnectionException) {
            return null;
        }

        $keys = $response->json('keys');
        if (! $response->successful() || ! is_array($keys)) {
            Log::warning('Apple sign-in: the signing keys could not be fetched', ['status' => $response->status()]);

            return null;
        }

        return ['keys' => array_values(array_filter($keys, 'is_array'))];
    }

    /** The .p8 key: the PEM itself (`\n` escapes allowed) or a path to the file. */
    private static function privateKey(): string
    {
        $value = trim((string) config('services.apple.private_key'));
        if ($value !== '' && ! str_contains($value, '-----BEGIN') && is_file($value) && is_readable($value)) {
            $value = trim((string) file_get_contents($value));
        }

        return str_contains($value, '-----BEGIN') ? str_replace('\n', "\n", $value) : '';
    }

    /** Apple sends booleans as JSON booleans or as "true" / "false" strings. */
    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    private function redirectUri(): string
    {
        return (string) (config('services.apple.redirect') ?: route('login.apple.callback'));
    }
}
