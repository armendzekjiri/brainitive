<?php

namespace App\Support\Calendar;

use App\Models\User;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\ApiKeys\ApiKeyAreas;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A calendar page event (CalendarEvents, the web's CalendarEvent shape) as the App API shows it: one
 * id per occurrence, instants in UTC for timed events and plain dates for all-day ones. Built for
 * GET /api/v1/calendar/events and GET /api/v1/me/today.
 */
final class AgendaEvent
{
    /** page type → API type */
    private const TYPES = [
        'board_item' => 'issue',
        'task_list_item' => 'task',
        'external_event' => 'event',
        'google_task' => 'google_task',
        'booking' => 'booking',
    ];

    /**
     * The user's calendar for their local days $from … $to (inclusive), soonest first: CalendarEvents
     * (the web calendar's sources) turned into agenda events and cut to exactly those days.
     *
     * @param  list<string>  $sources  CalendarEvents::SOURCES
     * @return array{events: list<array<string, mixed>>, errors: list<array{connection_id: int, message: string}>}
     */
    public static function forDays(User $user, string $from, string $to, array $sources, bool $meOnly, bool $showCompleted): array
    {
        $zone = $user->localTimezone();

        // CalendarEvents works on calendar dates (app zone): ask one day more on each side, then cut
        // by the user's local days below, so no event near midnight is lost or doubled.
        $result = CalendarEvents::between(
            $user,
            Carbon::parse($from)->subDay()->startOfDay(),
            Carbon::parse($to)->addDay()->endOfDay(),
            $user->accessibleProjectIds(),
            $sources,
            $meOnly,
            $showCompleted,
            CalendarEvents::connections($user)->where('is_active', true)->values(),
            $zone,
        );

        $events = collect($result['events'])
            ->map(fn (array $event) => self::from($event, $zone))
            ->filter(fn (?array $event) => $event !== null && self::within($event, $from, $to, $zone));

        // a personal API key: issues only with Issues & boards read (and its boards), to-dos only with My Work read
        if (($key = ApiKeyAccess::forUser($user)) !== null) {
            $issueIds = $events->where('type', 'issue')->map(fn (array $e) => (int) substr((string) $e['work_id'], 6))->values()->all();
            $visible = array_flip(ApiKeyAccess::visibleIssueIds($user, $issueIds));
            $tasks = $key->can('work', ApiKeyAreas::READ);
            $events = $events->filter(fn (array $e) => match ($e['type']) {
                'issue' => isset($visible[(int) substr((string) $e['work_id'], 6)]),
                'task' => $tasks,
                default => true,
            });
        }

        $events = $events
            // by local day; on a day all-day ones first, then by start time; ties by id (a stable order)
            ->sortBy(fn (array $event) => $event['all_day']
                ? [$event['start_date'], 0, '', $event['id']]
                : [$event['starts_at']->copy()->setTimezone($zone)->toDateString(), 1, $event['starts_at']->toIso8601ZuluString(), $event['id']])
            ->values()
            ->all();

        return ['events' => $events, 'errors' => $result['errors']];
    }

    /**
     * @param  array<string, mixed>  $event  a CalendarEvents event
     * @param  string  $zone  the user's time zone: Brainitive's own dates and times are its wall clock
     * @return array{id: string, type: string, work_id: string|null, title: string, all_day: bool, start: string, end: string, starts_at: CarbonInterface|null, ends_at: CarbonInterface|null, start_date: string, end_date: string, location: string|null, conference_url: string|null, conference_provider: string|null, color: string|null, calendar: array{connection_id: int, name: string|null, provider: string, provider_label: string}|null, project: array{id: int, name: string, key: string|null}|null, url: string|null, external_url: string|null, done: bool, read_only: bool}|null
     */
    public static function from(array $event, string $zone): ?array
    {
        $type = self::TYPES[$event['type'] ?? ''] ?? null;
        $startDate = $event['start_date'] ?? null ?: ($event['due_date'] ?? null);
        $endDate = $event['due_date'] ?? null ?: $startDate;
        if ($type === null || ! $startDate) {
            return null;
        }

        $startTime = self::time($event['start_time'] ?? null);
        $endTime = self::time($event['due_time'] ?? null);
        $allDay = in_array($type, ['event'], true) ? (bool) ($event['all_day'] ?? false) : ($startTime === null && $endTime === null);

        $startsAt = $endsAt = null;
        if (! $allDay) {
            $eventZone = is_string($event['timezone'] ?? null) && $event['timezone'] !== '' ? $event['timezone'] : $zone;
            $startsAt = Carbon::parse($startDate.' '.($startTime ?? $endTime), $eventZone)->utc();
            $endsAt = Carbon::parse($endDate.' '.($endTime ?? $startTime), $eventZone)->utc();
            if ($endsAt->lt($startsAt)) {
                $endsAt = $startsAt->copy();
            }
        }

        return [
            'id' => self::id($type, $event, $endDate),
            'type' => $type,
            'work_id' => match ($type) {
                'issue' => 'issue:'.$event['id'],
                'task' => 'task:'.$event['id'],
                default => null,
            },
            'title' => (string) ($event['title'] ?? ''),
            'all_day' => $allDay,
            'start' => $allDay ? $startDate : $startsAt->toIso8601ZuluString(),
            'end' => $allDay ? max($startDate, $endDate) : $endsAt->toIso8601ZuluString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'start_date' => $startDate,
            'end_date' => max($startDate, $endDate),
            'location' => self::string($event['location'] ?? null),
            'conference_url' => self::string($event['conference_url'] ?? null),
            'conference_provider' => self::string($event['conference_provider'] ?? null),
            'color' => self::string($event['color'] ?? null),
            'calendar' => isset($event['connection_id']) ? [
                'connection_id' => (int) $event['connection_id'],
                'name' => self::string($event['source']['name'] ?? null),
                'provider' => (string) ($event['provider'] ?? ''),
                'provider_label' => (string) ($event['provider_label'] ?? ''),
            ] : null,
            'project' => is_array($event['project'] ?? null) ? [
                'id' => (int) $event['project']['id'],
                'name' => (string) $event['project']['name'],
                'key' => $event['project']['key'] ?? null,
            ] : null,
            'url' => self::url($event),
            'external_url' => self::string($event['external_url'] ?? null),
            'done' => (bool) ($event['is_completed'] ?? false),
            'read_only' => (bool) ($event['read_only'] ?? false),
        ];
    }

    /**
     * Whether the event touches the user's local days $from … $to (inclusive).
     *
     * @param  array{all_day: bool, start_date: string, end_date: string, starts_at: CarbonInterface|null, ends_at: CarbonInterface|null}  $event
     */
    public static function within(array $event, string $from, string $to, string $zone): bool
    {
        if ($event['all_day']) {
            return $event['start_date'] <= $to && $event['end_date'] >= $from;
        }

        $windowStart = Carbon::parse($from, $zone)->startOfDay();
        $windowEnd = Carbon::parse($to, $zone)->endOfDay();

        return $event['starts_at']->lte($windowEnd) && $event['ends_at']->gte($windowStart);
    }

    /** A stable id per occurrence (a repeating task appears once per date, whatever the range). */
    private static function id(string $type, array $event, string $date): string
    {
        return match ($type) {
            'issue' => 'issue:'.$event['id'],
            // a repeating task: one entry per date
            'task' => 'task:'.$event['id'].(! empty($event['recurrence']) ? ':'.$date : ''),
            'event', 'google_task' => $type.':'.($event['connection_id'] ?? 0).':'.$event['id'],
            default => 'booking:'.substr((string) $event['id'], strlen('booking-')),
        };
    }

    private static function url(array $event): ?string
    {
        $params = $event['href_params'] ?? [];

        return match ($event['type']) {
            'board_item' => route('boards.items.show', $params),
            'task_list_item' => route('task-lists.show', $params),
            'booking' => route('booking-pages.show', $params),
            default => null,
        };
    }

    private static function time(mixed $time): ?string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
