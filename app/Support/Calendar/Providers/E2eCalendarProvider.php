<?php

namespace App\Support\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\EventInput;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Calendar\PendingConnection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * A read-only 'url' provider that exists only with APP_ENV=e2e (CalendarProviders::all), so
 * the Playwright journeys can drive the generic connect dialog, read-only events and the
 * sync warning without any network. Never registered in dev or production.
 *
 * A link containing "fail" connects, but loading it fails (→ sync_error warning).
 * A link containing "meet" is writable and offers Google Meet + Teams with a made-up join link
 * (BRAI-147 journeys); its created events live in the connection's encrypted `credentials`.
 */
class E2eCalendarProvider extends BaseCalendarProvider
{
    /** Writable + conferencing calendars (the link contains "meet"). */
    public function isWritable(CalendarConnection $connection): bool
    {
        return $this->meets($connection) && empty(((array) $connection->credentials)['read_only']);
    }

    public function conferenceProviders(CalendarConnection $connection): array
    {
        return $this->isWritable($connection) ? ['google_meet', 'teams'] : [];
    }

    public function createEvent(CalendarConnection $connection, array $data): array
    {
        $this->assertWritable($connection);
        $input = EventInput::from($data);
        $conference = $this->requestedConference($connection, $data);
        $id = 'e2e-'.Str::lower(Str::random(12));

        $stored = [
            'id' => $id,
            'title' => (string) ($data['title'] ?? ''),
            'description' => $data['description'] ?? null,
            'all_day' => $input->isAllDay(),
            'start' => $input->isAllDay() ? $input->startDate() : $input->start()->utc()->toIso8601String(),
            'end' => $input->isAllDay() ? $input->endDate() : $input->end()->utc()->toIso8601String(),
            'conference_url' => $conference ? 'https://meet.e2e.test/'.Str::lower(Str::random(10)) : null,
            'conference_provider' => $conference,
            'attendees' => $this->requestedAttendees($data),
        ];
        $credentials = (array) $connection->credentials;
        $credentials['events'] = array_merge(array_values((array) ($credentials['events'] ?? [])), [$stored]);
        $connection->forceFill(['credentials' => $credentials])->save();

        return $this->fromStored($stored, null);
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $this->assertWritable($connection);
        $credentials = (array) $connection->credentials;
        $credentials['events'] = array_values(array_filter((array) ($credentials['events'] ?? []), fn ($e) => ($e['id'] ?? null) !== $eventId));
        $connection->forceFill(['credentials' => $credentials])->save();
    }

    private function meets(CalendarConnection $connection): bool
    {
        return str_contains((string) (((array) $connection->credentials)['url'] ?? ''), 'meet');
    }

    /** @param array<string, mixed> $e */
    private function fromStored(array $e, ?string $timezone): array
    {
        $extra = ['description' => $e['description'] ?? null, 'conference_url' => $e['conference_url'] ?? null, 'conference_provider' => $e['conference_provider'] ?? null];

        return $e['all_day']
            ? NormalizedEvent::allDay($e['id'], $e['title'], $e['start'], $e['end'], $extra)
            : NormalizedEvent::timed($e['id'], $e['title'], Carbon::parse($e['start']), Carbon::parse($e['end']), $timezone ?? 'UTC', $extra);
    }

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

        $created = [];
        foreach ((array) (((array) $connection->credentials)['events'] ?? []) as $stored) {
            $event = $this->fromStored($stored, $timezone);
            if ($event['end_date'] >= Carbon::instance($start)->toDateString() && $event['start_date'] <= Carbon::instance($end)->toDateString()) {
                $created[] = $event;
            }
        }

        return [
            NormalizedEvent::allDay('e2e-offsite', "E2E offsite {$name}", $day, $day, ['location' => 'Lisbon']),
            NormalizedEvent::timed('e2e-standup', "E2E standup {$name}", Carbon::parse("{$day} 09:00", $zone), Carbon::parse("{$day} 09:30", $zone), $zone),
            ...$created,
        ];
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        foreach ((array) (((array) $connection->credentials)['events'] ?? []) as $stored) {
            if (($stored['id'] ?? null) === $eventId) {
                return $this->fromStored($stored, $timezone);
            }
        }

        $month = now();

        foreach ($this->listEvents($connection, $month->copy()->startOfMonth()->subDays(7), $month->copy()->endOfMonth(), $timezone) as $event) {
            if ($event['id'] === $eventId) {
                return $event;
            }
        }

        return null;
    }
}
