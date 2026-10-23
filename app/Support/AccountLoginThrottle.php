<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Failed password logins per account, whatever IP they claim to come from — a spoofed
 * X-Forwarded-For (TRUSTED_PROXIES=*) would otherwise reset an email|ip limit on every request.
 * Used by the web login and the CLI token login; a successful password reset lifts the lock.
 *
 * Trade-off: someone who knows an email can keep that account's password login locked; the
 * owner can still reset the password or use Google / GitHub sign-in.
 */
class AccountLoginThrottle
{
    public const ATTEMPTS = 20;

    public const DECAY = 900; // seconds

    public static function key(string $email): string
    {
        return 'login-account:'.Str::transliterate(Str::lower(trim($email)));
    }

    public static function tooManyAttempts(string $email): bool
    {
        return RateLimiter::tooManyAttempts(self::key($email), self::ATTEMPTS);
    }

    public static function availableIn(string $email): int
    {
        return RateLimiter::availableIn(self::key($email));
    }

    public static function hit(string $email): void
    {
        RateLimiter::hit(self::key($email), self::DECAY);
    }

    public static function clear(string $email): void
    {
        RateLimiter::clear(self::key($email));
    }
}
