<?php

namespace App\Support\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\EventInput;
use App\Support\Calendar\NormalizedEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Google Calendar on the provider contract. HTTP stays in GoogleCalendarService; this class
 * maps Google's JSON to the normalized event shape and back. OAuth lives in
 * CalendarConnectionController (redirectToGoogle / googleCallback). Google Tasks are not part
 * of the contract: kind = 'tasks' rows keep using GoogleTasksService directly.
 */
class GoogleCalendarProvider extends BaseCalendarProvider
{
    public function __construct(private GoogleCalendarService $service) {}

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Calendar';
    }

    public function badge(): string
    {
        return 'G';
    }

    public function defaultColor(): string
    {
        return '#4285F4';
    }

    public function connectMode(): string
    {
        return 'oauth';
    }

    public function redirectRoute(): ?string
    {
        return 'calendar.connections.google.redirect';
    }

    public function capabilities(): array
    {
        return ['write' => true, 'tasks' => true, 'conferencing' => true];
    }

    /**
     * Google Meet, when the calendar allows it (a Workspace admin can switch Meet off). When
     * Google doesn't answer, Meet is offered anyway: creating the event reports the real error.
     */
    public function conferenceProviders(CalendarConnection $connection): array
    {
        if ($connection->kind !== 'calendar' || ! $this->isWritable($connection)) {
            return [];
        }
        $types = $this->service->conferenceSolutionTypes($connection);

        return $types === null || in_array('hangoutsMeet', $types, true) ? ['google_meet'] : [];
    }

    public function listCalendars(CalendarConnection $connection): array
    {
        $calendars = $this->service->listCalendars($connection);
        if ($calendars === null) {
            throw new CalendarSyncFailed('Could not load your Google calendars.');
        }

        return array_values(array_map(fn (array $c) => [
            'id' => (string) $c['id'],
            'name' => (string) ($c['summary'] ?? $c['id']),
            'color' => $c['backgroundColor'] ?? null,
            'primary' => (bool) ($c['primary'] ?? false),
            'read_only' => ($c['access_role'] ?? null) === 'reader',
        ], array_filter($calendars, fn (array $c) => in_array($c['access_role'] ?? null, ['owner', 'writer', 'reader'], true))));
    }

    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        $raw = $this->service->listEvents($connection, $start, $end);
        if ($raw === null) {
            throw new CalendarSyncFailed($this->failureMessage($connection));
        }

        $events = [];
        foreach ($raw as $event) {
            $normalized = $this->normalize($event, $timezone);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        $event = $this->service->getEvent($connection, $eventId);

        return $event ? $this->normalize($event, $timezone) : null;
    }

    public function createEvent(CalendarConnection $connection, array $data): array
    {
        $this->assertWritable($connection);
        $input = EventInput::from($data);
        $conference = $this->requestedConference($connection, $data);

        $created = $this->service->createEvent($connection, [
            'title' => (string) ($data['title'] ?? ''),
            'date' => $input->startDate(),
            'time' => $input->isAllDay() ? null : $input->start()->format('H:i'),
            'end_date' => $input->isAllDay() ? $input->endDate() : $input->end()->toDateString(),
            'end_time' => $input->isAllDay() ? null : $input->end()->format('H:i'),
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'timezone' => $input->timezone(),
            'conference' => $conference,
            'attendees' => $this->requestedAttendees($data),
        ]);

        // Google may create the Meet asynchronously ("pending"): read the event once more.
        if ($conference !== null && is_array($created) && ! empty($created['id']) && $this->joinUrl($created) === null
            && ($created['conferenceData']['createRequest']['status']['statusCode'] ?? null) === 'pending') {
            $created = $this->service->getEvent($connection, (string) $created['id']) ?? $created;
        }

        return $this->normalize($created ?? [], null)
            ?? throw new CalendarSyncFailed('Google rejected the event. Try again, or reconnect this calendar.');
    }

    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): array
    {
        $this->assertWritable($connection);
        $input = EventInput::from($data);

        $changes = array_intersect_key($data, array_flip(['title', 'description', 'location']));
        if ($input->changesTimes()) {
            $changes += [
                'start_date' => $input->startDate(),
                'start_time' => $input->isAllDay() ? null : $input->start()->format('H:i'),
                'end_date' => $input->isAllDay() ? $input->endDate() : $input->end()->toDateString(),
                'end_time' => $input->isAllDay() ? null : $input->end()->format('H:i'),
            ];
        }
        $changes['timezone'] = $input->timezone();

        $updated = $this->service->updateEvent($connection, $eventId, $changes);

        return $this->normalize($updated ?? [], null)
            ?? throw new CalendarSyncFailed('Google rejected the change. Try again, or reconnect this calendar.');
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $this->assertWritable($connection);
        if (! $this->service->deleteEvent($connection, $eventId)) {
            throw new CalendarSyncFailed('Google refused to delete the event. Try again, or reconnect this calendar.');
        }
    }

    /**
     * Google Calendar API event → normalized event. All-day `end.date` is exclusive in Google;
     * the normalized end_date is inclusive. Timed events keep Google's wall-clock (the
     * calendar's zone) unless $timezone asks for another zone. Null when there is no start.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    public function normalize(array $event, ?string $timezone): ?array
    {
        $id = $event['id'] ?? null;
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        if (! $id || (empty($start['date']) && empty($start['dateTime']))) {
            return null;
        }

        $extra = [
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'external_url' => $event['htmlLink'] ?? null,
            'conference_url' => $this->joinUrl($event),
            'conference_provider' => ($event['conferenceData']['conferenceSolution']['key']['type'] ?? null) === 'hangoutsMeet'
                || (! isset($event['conferenceData']) && isset($event['hangoutLink'])) ? 'google_meet' : 'other',
        ];

        if (! empty($start['date'])) {
            $endInclusive = ! empty($end['date']) ? Carbon::parse($end['date'])->subDay()->toDateString() : null;

            return NormalizedEvent::allDay((string) $id, $event['summary'] ?? null, $start['date'], $endInclusive, $extra);
        }

        $startAt = Carbon::parse($start['dateTime']);
        $endAt = ! empty($end['dateTime']) ? Carbon::parse($end['dateTime']) : $startAt->copy();

        return NormalizedEvent::timed((string) $id, $event['summary'] ?? null, $startAt, $endAt, $timezone, $extra);
    }

    /**
     * The video call's link: the conference's video entry point, else the classic hangoutLink.
     *
     * @param  array<string, mixed>  $event
     */
    private function joinUrl(array $event): ?string
    {
        foreach ((array) ($event['conferenceData']['entryPoints'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['entryPointType'] ?? null) === 'video' && is_string($entry['uri'] ?? null)) {
                return $entry['uri'];
            }
        }

        return is_string($event['hangoutLink'] ?? null) ? $event['hangoutLink'] : null;
    }

    private function failureMessage(CalendarConnection $connection): string
    {
        return $connection->is_active
            ? 'Google Calendar didn’t answer. Try again later, or reconnect this calendar.'
            : 'Google access expired. Reconnect this calendar.';
    }
}
