<?php

namespace App\Support\AppApi;

/**
 * The first-party app clients of `config/app-api.php` (`clients`): which way each one signs in
 * (`authorization_code` = /app/authorize + PKCE, `device_code` = RFC 8628) and the platforms it runs on.
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
}
