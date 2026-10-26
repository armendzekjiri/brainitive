<?php

namespace App\Support\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sign in with Microsoft (BRAI-148): OpenID Connect against the Microsoft identity platform v2,
 * authorization code + PKCE (S256) + nonce, for personal Microsoft accounts and work / school
 * (Entra ID) accounts. The same app registration as the Outlook calendar (config
 * `services.microsoft`), with its own redirect URI (`login_redirect`).
 *
 * The identity comes from the id_token only — Graph is not called:
 *  - id: "{tid}:{oid}" (tenant + object id; stable, never shared between tenants).
 *  - email: trusted only when it can't be claimed by someone else — the id_token `email` of a
 *    personal account, or of a work / school account whose domain owner Microsoft verified
 *    (`xms_edov`). A tenant admin can put ANY address in a user's `mail` (nOAuth), so Graph
 *    `mail`, `userPrincipalName` and `preferred_username` are never used. Without a trusted
 *    email, `email` is null: the account can only sign in once linked. Guests of a tenant
 *    (`acct` = 1) never have a trusted email.
 *
 * The id_token signature is not verified: it comes straight from the token endpoint over TLS in
 * exchange for the code + client secret + PKCE verifier (OIDC Core 3.1.3.7 §6); its claims are
 * checked (aud, iss, exp, nonce, tid / oid).
 */
class MicrosoftLogin
{
    public const SCOPES = 'openid profile email User.Read';

    /** The tenant every personal Microsoft account (Outlook.com, Hotmail, Live…) belongs to. */
    public const CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Only with an app registration (client id + secret) configured. */
    public static function available(): bool
    {
        return filled(config('services.microsoft.client_id')) && filled(config('services.microsoft.client_secret'));
    }

    /** The Microsoft sign-in page, with state, nonce and the PKCE (S256) challenge. */
    public function authorizeUrl(string $state, string $nonce, string $codeVerifier): string
    {
        return $this->loginBase().'/authorize?'.http_build_query([
            'client_id' => (string) config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($codeVerifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function challenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Swap the code for tokens and read who signed in.
     *
     * @return array{id: string, email: string|null, name: string|null}
     *
     * @throws MicrosoftLoginFailed with a message for the user
     */
    public function identity(string $code, string $codeVerifier, string $nonce): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->post($this->loginBase().'/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                    'code_verifier' => $codeVerifier,
                    'scope' => self::SCOPES,
                    'client_id' => (string) config('services.microsoft.client_id'),
                    'client_secret' => (string) config('services.microsoft.client_secret'),
                ]);
        } catch (ConnectionException) {
            throw new MicrosoftLoginFailed('Microsoft didn’t answer. Please try again.');
        }

        $idToken = $response->json('id_token');
        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            $error = $response->json('error');
            Log::warning('Microsoft sign-in code exchange failed', ['status' => $response->status(), 'error' => is_string($error) ? substr($error, 0, 80) : null]);

            throw new MicrosoftLoginFailed('Microsoft didn’t accept the sign-in. Please try again.');
        }

        $claims = $this->claims($idToken, $nonce);
        if ($claims === null) {
            Log::warning('Microsoft sign-in id_token refused');

            throw new MicrosoftLoginFailed('Something went wrong signing in with Microsoft. Please try again.');
        }

        $tid = strtolower($claims['tid']);
        $personal = $tid === self::CONSUMER_TENANT;

        $email = $claims['email'] ?? null;
        $trusted = is_string($email)
            && strlen($email) <= 255
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ($personal || in_array($claims['xms_edov'] ?? null, [true, 1, '1', 'true'], true))
            // a B2B guest's address is managed by another directory: never trusted (optional claim `acct`)
            && ! in_array($claims['acct'] ?? null, [1, '1'], true);

        $name = $claims['name'] ?? null;

        return [
            'id' => $tid.':'.strtolower($claims['oid']),
            'email' => $trusted ? strtolower($email) : null,
            'name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
        ];
    }

    /**
     * The id_token payload when it was issued to this app, for this sign-in, by the tenant it
     * names; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function claims(string $idToken, string $nonce): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || strlen($parts[1]) > 16384) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($claims)) {
            return null;
        }

        $tid = $claims['tid'] ?? null;
        $oid = $claims['oid'] ?? null;
        if (! is_string($tid) || ! preg_match(self::GUID, strtolower($tid)) || ! is_string($oid) || ! preg_match(self::GUID, strtolower($oid))) {
            return null;
        }

        $aud = $claims['aud'] ?? null;
        $iss = $claims['iss'] ?? null;
        $exp = $claims['exp'] ?? null;
        $tokenNonce = $claims['nonce'] ?? null;

        if (! is_string($aud) || ! hash_equals((string) config('services.microsoft.client_id'), $aud)
            || $iss !== 'https://login.microsoftonline.com/'.$tid.'/v2.0'
            || ! is_int($exp) || $exp < time() - 60
            || ! is_string($tokenNonce) || $nonce === '' || ! hash_equals($nonce, $tokenNonce)) {
            return null;
        }

        // A single-tenant setup (MICROSOFT_TENANT = a tenant id) only accepts that tenant.
        $tenant = strtolower((string) config('services.microsoft.tenant'));
        if (preg_match(self::GUID, $tenant) && strtolower($tid) !== $tenant) {
            return null;
        }

        return $claims;
    }

    private function loginBase(): string
    {
        $tenant = (string) (config('services.microsoft.tenant') ?: 'common');
        if (! preg_match('/^[A-Za-z0-9.-]{1,100}$/', $tenant)) {
            $tenant = 'common';
        }

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0";
    }

    private function redirectUri(): string
    {
        return (string) (config('services.microsoft.login_redirect') ?: route('login.microsoft.callback'));
    }
}
