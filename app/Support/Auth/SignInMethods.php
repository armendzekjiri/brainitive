<?php

namespace App\Support\Auth;

/**
 * Which sign-in buttons this instance offers: one rule for the Login page, the homepage and the
 * App API (`GET /api/v1/instance`, BRAI-354). Password sign-in is always on; a social provider
 * only when its app registration is configured.
 */
final class SignInMethods
{
    public static function google(): bool
    {
        return (bool) config('services.google.client_id');
    }

    public static function github(): bool
    {
        return (bool) config('services.github.client_id');
    }

    public static function microsoft(): bool
    {
        return MicrosoftLogin::available();
    }

    public static function apple(): bool
    {
        return AppleLogin::available();
    }

    /** @return array{password: bool, google: bool, github: bool, microsoft: bool, apple: bool} */
    public static function all(): array
    {
        return [
            'password' => true,
            'google' => self::google(),
            'github' => self::github(),
            'microsoft' => self::microsoft(),
            'apple' => self::apple(),
        ];
    }
}
