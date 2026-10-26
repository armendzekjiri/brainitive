<?php

namespace App\Support\Calendar;

use App\Models\CalendarConnection;

/**
 * Every calendar provider (BRAI-140). Adding a provider = one line here + its class (see the
 * CalendarProvider docblock and docs/ai/tasks/feat-calendar-providers.md).
 */
final class CalendarProviders
{
    /** @var array<string, class-string<CalendarProvider>> */
    public const PROVIDERS = [
        'google' => Providers\GoogleCalendarProvider::class,
        'outlook' => Providers\OutlookCalendarProvider::class, // BRAI-140
        'caldav' => Providers\CaldavCalendarProvider::class, // BRAI-141
        'ics' => Providers\IcsSubscriptionProvider::class, // BRAI-141
    ];

    /** @var array<string, class-string<CalendarProvider>> test-only extra providers (CalendarProviders::fake) */
    private static array $fakes = [];

    /** @return array<string, class-string<CalendarProvider>> */
    public static function all(): array
    {
        // The made-up 'e2e' provider exists only for the Playwright journeys (APP_ENV=e2e).
        $e2e = app()->environment('e2e') ? ['e2e' => Providers\E2eCalendarProvider::class] : [];

        return self::PROVIDERS + self::$fakes + $e2e;
    }

    /** @param class-string<CalendarProvider>|null $class null removes it */
    public static function fake(string $key, ?string $class): void
    {
        if ($class === null) {
            unset(self::$fakes[$key]);
        } else {
            self::$fakes[$key] = $class;
        }
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @throws CalendarSyncFailed for an unknown key (e.g. a provider that was removed) */
    public static function make(string $key): CalendarProvider
    {
        $class = self::all()[$key] ?? throw new CalendarSyncFailed('This calendar provider is no longer supported.');

        return app($class);
    }

    public static function for(CalendarConnection $connection): CalendarProvider
    {
        return self::make($connection->provider);
    }

    /**
     * The provider of a connection that must accept writes.
     *
     * @throws CalendarReadOnly|CalendarSyncFailed
     */
    public static function writable(CalendarConnection $connection): CalendarProvider
    {
        $provider = self::for($connection);
        if ($connection->kind !== 'calendar' || ! $provider->isWritable($connection)) {
            throw new CalendarReadOnly;
        }

        return $provider;
    }

    /**
     * Connectable providers for the "Connect a calendar" menu.
     *
     * @return list<array{key: string, label: string, badge: string, color: string, connect_mode: string, redirect_url: string|null, fields: list<array<string, mixed>>, capabilities: array{write: bool, tasks: bool}}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (array_keys(self::all()) as $key) {
            $provider = self::make($key);
            if (! $provider->available()) {
                continue;
            }
            $route = $provider->connectMode() === 'oauth' ? $provider->redirectRoute() : null;
            $out[] = [
                'key' => $key,
                'label' => $provider->label(),
                'badge' => $provider->badge(),
                'color' => $provider->defaultColor(),
                'connect_mode' => $provider->connectMode(),
                'redirect_url' => $route ? route($route) : null,
                'fields' => array_map(fn (array $field) => [
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'type' => $field['type'] ?? 'text',
                    'help' => $field['help'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                    'required' => $field['required'] ?? true,
                ], $provider->formFields()),
                'capabilities' => $provider->capabilities(),
            ];
        }

        return $out;
    }
}
