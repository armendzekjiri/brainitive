<?php

namespace App\Support\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Calendar\PendingConnection;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A read-only 'url' provider that exists only with APP_ENV=e2e (CalendarProviders::all), so
 * the Playwright journeys can drive the generic connect dialog, read-only events and the
 * sync warning without any network. Never registered in dev or production.
 *
 * A link containing "fail" connects, but loading it fails (→ sync_error warning).
 */
class E2eCalendarProvider extends BaseCalendarProvider
{
    public function key(): string
    {
        return 'e2e';
    }

    public function label(): string
    {
        return 'E2E calendar link';
    }

    public function badge(): string
    {
        return 'E';
    }

    public function defaultColor(): string
    {
        return '#7C3AED';
    }

    public function connectMode(): string
    {
        return 'url';
    }

    public function formFields(): array
    {
        return [[
            'name' => 'url',
            'label' => 'Calendar link',
            'type' => 'url',
            'help' => 'Any https link; the events are made up.',
            'placeholder' => 'https://example.test/calendar.ics',
        ]];
    }

    public function connectWithForm(User $user, array $input): array|PendingConnection
    {
        $url = (string) $input['url'];

        return [CalendarConnection::upsertFor($user->id, 'e2e', 'calendar', 'Calendar link', sha1($url), [
            'external_calendar_name' => (string) (parse_url($url, PHP_URL_HOST) ?: 'Calendar link'),
            'server_url' => parse_url($url, PHP_URL_HOST) ?: null,
            'credentials' => ['url' => $url],
            'is_active' => true,
            'sync_error' => null,
            'sync_error_at' => null,
        ])];
    }

    /** One all-day and one timed event on the 20th of the month in view (clear of other journeys). */
    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        if (str_contains((string) ($connection->credentials['url'] ?? ''), 'fail')) {
            throw new CalendarSyncFailed('The calendar link did not answer.');
        }

        $day = Carbon::instance($start)->addDays(10)->setDay(20)->toDateString();
        $zone = $timezone ?? 'UTC';
        // The calendar's name (the link's host) in the titles keeps parallel journeys apart.
        $name = $connection->external_calendar_name;

        return [
            NormalizedEvent::allDay('e2e-offsite', "E2E offsite {$name}", $day, $day, ['location' => 'Lisbon']),
            NormalizedEvent::timed('e2e-standup', "E2E standup {$name}", Carbon::parse("{$day} 09:00", $zone), Carbon::parse("{$day} 09:30", $zone), $zone),
        ];
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        $month = now();

        foreach ($this->listEvents($connection, $month->copy()->startOfMonth()->subDays(7), $month->copy()->endOfMonth(), $timezone) as $event) {
            if ($event['id'] === $eventId) {
                return $event;
            }
        }

        return null;
    }
}
