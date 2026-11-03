<?php

namespace App\Support\AppApi;

use App\Models\User;

/**
 * Codex review (v1.49.0), finding 2: an app sign-in the user approved — a consent-page code (/app/authorize →
 * POST /auth/exchange) or a TV device code (/app/device → POST /auth/device/token) — lives in the cache until the
 * app turns it into tokens, and the cache can't list a user's codes. So each approval carries the user's
 * `app_auth_generation` from the moment of approval; SignOutEverywhere bumps it, and AppDeviceSignIn::create
 * compares it under the user row lock it takes anyway: an approval from before the sign-out mints nothing, and a
 * sign-in that got the lock first is revoked by the sign-out's revokeAllFor right after.
 */
final class AppSignInGeneration
{
    /** The payload key of an approval. */
    public const KEY = 'auth_generation';

    public static function current(User $user): int
    {
        return (int) User::query()->whereKey($user->id)->value('app_auth_generation');
    }

    /** Ends every approval of the user given so far (atomic increment: concurrent bumps both count). */
    public static function bump(User $user): void
    {
        User::query()->whereKey($user->id)->increment('app_auth_generation');
    }

    /**
     * Whether an approval's payload still holds for the user row (locked by the caller). An approval without the
     * key (issued before this check existed) does not.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function holds(array $payload, User $locked): bool
    {
        return is_int($payload[self::KEY] ?? null) && $payload[self::KEY] === (int) $locked->app_auth_generation;
    }
}
