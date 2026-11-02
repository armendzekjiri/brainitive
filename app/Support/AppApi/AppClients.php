<?php

namespace App\Support\AppApi;

use Illuminate\Support\Str;

/**
 * The first-party app clients of `config/app-api.php` (`clients`): which way each one signs in
 * (`authorization_code` = /app/authorize + PKCE, `device_code` = RFC 8628), the platforms it runs on and
 * the signed-in App API routes it may call (`abilities`, BRAI-377).
 */
final class AppClients
{
    public const AUTHORIZATION_CODE = 'authorization_code';

    public const DEVICE_CODE = 'device_code';

    /**
     * Keys of the clients that sign in with the given grant.
     *
     * @return list<string>
     */
    public static function using(string $grant): array
    {
        return array_keys(array_filter(
            (array) config('app-api.clients'),
            fn ($client) => is_array($client) && ($client['grant'] ?? self::AUTHORIZATION_CODE) === $grant,
        ));
    }

    public static function exists(string $client): bool
    {
        return is_array(config('app-api.clients.'.$client));
    }

    public static function name(string $client): string
    {
        return (string) (config('app-api.clients.'.$client.'.name') ?? 'Brainitive app');
    }

    /** Whether the platform belongs to the client (a TV app can't sign in as macOS). */
    public static function runsOn(string $client, string $platform): bool
    {
        return in_array($platform, (array) config('app-api.clients.'.$client.'.platforms', []), true);
    }

    /**
     * Whether the client may call the App API route (its name without `app.v1.`). `abilities` are
     * `Str::is` patterns, `*` = everything; an unknown client or one without abilities may call nothing.
     */
    public static function allows(string $client, ?string $route): bool
    {
        if ($route === null || $route === '') {
            return false;
        }

        $abilities = config('app-api.clients.'.$client.'.abilities', []);

        return is_array($abilities) && Str::is(array_values(array_filter($abilities, 'is_string')), $route);
    }
}
