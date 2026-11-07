<?php

namespace App\Support\ApiKeys;

use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Personal API keys of the Developer API (v1.52.0): `Authorization: Bearer brai_…` on /api/v1, next to the
 * desktop / mobile / TV device tokens (AppTokens) in the same `app` guard.
 *
 *  - Format `brai_` + 43 random base62 characters (≈ 256 bits); `prefix` (the first 13 characters) is kept to
 *    recognise the key in lists. Only the sha256 is stored; the key is shown once, at creation.
 *  - The lookup is by the hash on a unique index (no secret-dependent comparison in PHP; hash_equals re-checks).
 *  - Revoked or expired → 401 `api_key_revoked` / `api_key_expired`; keys switched off on the instance → 401
 *    `api_keys_disabled` (config `api-keys.enabled`). What a key may do: ApiKeyAccess, AuthorizeApiKey.
 */
final class ApiKeys
{
    public const PREFIX = 'brai_';

    /** Request attribute holding the ApiKey of the bearer. */
    public const ATTRIBUTE = 'api_key';

    /** Request attribute with why a `brai_` bearer was refused (AppApiErrors renders it as the 401 code). */
    public const FAILURE_ATTRIBUTE = 'api_key_failure';

    public const SECRET_LENGTH = 43;

    public static function enabled(): bool
    {
        return (bool) config('api-keys.enabled', true);
    }

    /** Does this bearer look like a personal API key (not a device token `bra_…` / `brr_…`)? */
    public static function looksLike(?string $token): bool
    {
        return is_string($token) && strlen($token) <= 128 && str_starts_with($token, self::PREFIX);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a key; the returned plain key exists only here (shown once). Abilities and scope ids must already
     * be validated against what the user can reach (StoreApiKeyRequest).
     *
     * @param  array<string, string>  $abilities
     * @param  list<int>  $scopeIds
     * @return array{key: ApiKey, secret: string}
     */
    public static function create(User $user, string $name, string $scopeType, array $scopeIds, array $abilities, bool $canDelete, ?int $expiresInDays, ?string $ip, ?int $generation = null): array
    {
        $secret = self::newSecret();

        $key = DB::transaction(function () use ($user, $name, $scopeType, $scopeIds, $abilities, $canDelete, $expiresInDays, $ip, $secret, $generation) {
            $current = self::lockGeneration($user, $generation);

            $key = new ApiKey(['name' => $name]);
            $key->forceFill([
                'credential_generation' => $current,
                'user_id' => $user->id,
                'prefix' => substr($secret, 0, 13),
                'token_hash' => self::hash($secret),
                'scope_type' => $scopeType,
                'scope_ids' => $scopeType === ApiKey::SCOPE_ALL ? null : array_values(array_unique(array_map('intval', $scopeIds))),
                'abilities' => ApiKeyAreas::normalize($abilities),
                'can_delete' => $canDelete,
                'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
                'created_from_ip' => $ip,
            ])->save();

            return $key;
        });

        self::notify($user, $key, regenerated: false);

        return ['key' => $key, 'secret' => $secret];
    }

    /**
     * A new secret for an active key (same name, scope, areas and expiry): the old one stops working at once.
     *
     * @return array{key: ApiKey, secret: string}
     */
    public static function regenerate(ApiKey $key, ?string $ip, ?int $generation = null): array
    {
        $secret = self::newSecret();
        DB::transaction(function () use ($key, $ip, $secret, $generation) {
            $current = self::lockGeneration($key->user, $generation);
            // revoked meanwhile (sign out everywhere got the lock first): nothing to renew
            abort_unless(ApiKey::query()->whereKey($key->id)->whereNull('revoked_at')->exists(), 409, self::STALE_SESSION);
            $key->forceFill([
                'credential_generation' => $current,
                'prefix' => substr($secret, 0, 13),
                'token_hash' => self::hash($secret),
                'last_used_at' => null,
                'last_used_ip' => null,
                'created_from_ip' => $ip,
            ])->save();
        });

        self::notify($key->user, $key, regenerated: true);

        return ['key' => $key, 'secret' => $secret];
    }

    public const STALE_SESSION = 'You were signed out everywhere (password change or reset, or by an admin) while this was being saved. Sign in again and create the key again.';

    /**
     * The race with "sign out everywhere" (Codex review): a create / regenerate on a stolen session can pass validation
     * just before the password change revokes every key. Both serialize on the user row: under its lock, the user's
     * `app_auth_generation` (bumped by SignOutEverywhere, AppSignInGeneration) must still be the one read when the
     * request started — else 409 and nothing is saved. If the create wins the lock, the sign-out's revokeAllFor
     * revokes the new key right after; and a key whose generation is older than the user's is refused at sign-in
     * anyway (resolve()).
     */
    private static function lockGeneration(User $user, ?int $expected): int
    {
        $locked = User::query()->whereKey($user->id)->lockForUpdate()->first(['id', 'app_auth_generation']);
        $current = (int) $locked?->app_auth_generation;
        abort_if($locked === null || ($expected !== null && $expected !== $current), 409, self::STALE_SESSION);

        return $current;
    }

    public static function revoke(ApiKey $key, string $reason = 'user'): void
    {
        if ($key->revoked_at !== null) {
            return;
        }
        $key->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
    }

    /** Revoke every key of a user (password change / reset, suspension: SignOutEverywhere). */
    public static function revokeAllFor(User $user, string $reason): int
    {
        return ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => mb_substr($reason, 0, 32), 'updated_at' => now()]);
    }

    /**
     * Resolver of the `app` guard for `brai_` bearers (AppTokens::userFromRequest): the key's user, with the key and
     * its access on the request. Null (→ 401) for unknown, revoked or expired keys, or keys switched off.
     */
    public static function userFromRequest(Request $request): ?User
    {
        $token = (string) $request->bearerToken();
        $key = self::resolve($request, $token);
        if ($key === null) {
            return null;
        }

        // at most one write a minute per key
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        return $key->user;
    }

    /** The active key of a `brai_` bearer, put on the request with its access (null + the failure reason otherwise). */
    public static function resolve(Request $request, ?string $token): ?ApiKey
    {
        if (! self::looksLike($token)) {
            return null;
        }
        if (! self::enabled()) {
            $request->attributes->set(self::FAILURE_ATTRIBUTE, 'api_keys_disabled');

            return null;
        }

        $hash = self::hash($token);
        $key = ApiKey::query()->where('token_hash', $hash)->with('user')->first();
        if (! $key instanceof ApiKey || ! hash_equals($key->token_hash, $hash) || $key->user === null) {
            return null;
        }
        // revoked, or made before the user was last signed out everywhere (second line of defence for the race above)
        if ($key->revoked_at !== null || (int) $key->credential_generation !== (int) $key->user->app_auth_generation) {
            $request->attributes->set(self::FAILURE_ATTRIBUTE, 'api_key_revoked');

            return null;
        }
        if ($key->isExpired()) {
            $request->attributes->set(self::FAILURE_ATTRIBUTE, 'api_key_expired');

            return null;
        }

        $request->attributes->set(self::ATTRIBUTE, $key);
        $request->attributes->set(ApiKeyAccess::ATTRIBUTE, new ApiKeyAccess($key, $key->user));
        ApiKeyLocator::flush();

        return $key;
    }

    /** The key of the current request (set by the guard). */
    public static function current(Request $request): ?ApiKey
    {
        $key = $request->attributes->get(self::ATTRIBUTE);

        return $key instanceof ApiKey ? $key : null;
    }

    private static function newSecret(): string
    {
        return self::PREFIX.Str::random(self::SECRET_LENGTH);
    }

    private static function notify(User $user, ApiKey $key, bool $regenerated): void
    {
        try {
            $user->notify(new ApiKeyCreatedNotification($key, $regenerated));
        } catch (Throwable $e) {
            // the key works either way; a mail outage must not lose the one-time secret
            Log::warning('api-keys: created-key email failed', ['key' => $key->id, 'error' => $e->getMessage()]);
        }
    }
}
