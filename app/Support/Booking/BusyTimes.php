<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\TaskListItem;
use App\Support\Calendar\CalendarProviderException;
use App\Support\Calendar\CalendarProviders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What blocks a booking page's time (BRAI-158), as absolute intervals:
 * - confirmed bookings of ALL the host's pages (always, so two pages never book the same hour);
 * - the page's busy sources: events of the chosen calendars, the host's timed issues and timed tasks.
 *
 * A calendar that can't be read is skipped (decided 2026-10-27: availability goes on without it)
 * and reported in `failed`, so the caller warns the host about possible double bookings. Calendar
 * reads are cached briefly per connection and range: the public page must not hammer providers.
 */
final class BusyTimes
{
    /** Seconds a calendar's events are reused for the public slot list. */
    public const CACHE_SECONDS = 180;

    /** A timed issue / task without a start blocks this long from its due time. */
    public const POINT_MINUTES = 30;

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @param  array<int, CalendarConnection>  $failed  connection id → connection that couldn't be read
     */
    private function __construct(public readonly array $intervals, public readonly array $failed) {}

    /** $fresh: skip the calendar cache (the final check before a booking is written). */
    public static function for(BookingPage $page, CarbonImmutable $from, CarbonImmutable $to, bool $fresh = false): self
    {
        $page->loadMissing('user');
        $sources = $page->busySources();
        $hostZone = $page->user->localTimezone();

        $intervals = self::bookings($page->user_id, $from, $to);
        $failed = [];

        $connections = CalendarConnection::query()
            ->where('user_id', $page->user_id)
            ->where('is_active', true)
            ->where('kind', 'calendar')
            ->when($sources['connections'] !== 'all', fn ($q) => $q->whereIn('id', $sources['connections']))
            ->get();
        foreach ($connections as $connection) {
            $events = self::calendarEvents($connection, $from, $to, $fresh);
            if ($events === null) {
                $failed[$connection->id] = $connection;

                continue;
            }
            array_push($intervals, ...self::eventIntervals($events, $page->timezone, $sources['all_day_blocks']));
        }

        if ($sources['issues']) {
            array_push($intervals, ...self::issues($page->user_id, $from, $to, $hostZone));
        }
        if ($sources['tasks']) {
            array_push($intervals, ...self::tasks($page->user_id, $from, $to, $hostZone));
        }

        return new self($intervals, $failed);
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private static function bookings(int $hostId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Booking::confirmed()
            ->where('user_id', $hostId)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Booking $b) => [$b->starts_at, $b->ends_at])
            ->all();
    }

    /**
     * Normalized events in UTC, or null when the calendar couldn't be read (sync error recorded).
     *
     * @return list<array<string, mixed>>|null
     */
    private static function calendarEvents(CalendarConnection $connection, CarbonImmutable $from, CarbonImmutable $to, bool $fresh): ?array
    {
        $key = 'booking-busy:'.$connection->id.':'.$from->getTimestamp().':'.$to->getTimestamp();
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $events = CalendarProviders::for($connection)->listEvents($connection, $from, $to, 'UTC');
        } catch (Throwable $e) {
            if (! $e instanceof CalendarProviderException) {
                Log::warning('Booking page: calendar provider failed', [
                    'connection_id' => $connection->id,
                    'provider' => $connection->provider,
                    'error' => get_class($e), // never the message: transport errors carry URLs with secrets
                ]);
            }
            $connection->recordSyncError($e instanceof CalendarProviderException
                ? $e->getMessage()
                : 'Couldn’t load this calendar. Try again later, or reconnect it.');

            return null;
        }

        Cache::put($key, $events, self::CACHE_SECONDS);

        return $events;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function eventIntervals(array $events, string $pageZone, bool $allDayBlocks): array
    {
        $out = [];
        foreach ($events as $event) {
            try {
                if ($event['all_day'] ?? false) {
                    if (! $allDayBlocks || empty($event['start_date'])) {
                        continue;
                    }
                    // all-day dates float: they cover the page's local days (end date inclusive)
                    $out[] = [
                        CarbonImmutable::parse($event['start_date'], $pageZone),
                        CarbonImmutable::parse($event['end_date'] ?: $event['start_date'], $pageZone)->addDay(),
                    ];

                    continue;
                }
                if (empty($event['start_date']) || empty($event['start_time'])) {
                    continue;
                }
                $zone = $event['timezone'] ?: 'UTC';
                $start = CarbonImmutable::parse($event['start_date'].' '.$event['start_time'], $zone);
                $end = ! empty($event['end_time'])
                    ? CarbonImmutable::parse(($event['end_date'] ?: $event['start_date']).' '.$event['end_time'], $zone)
                    : $start->addMinutes(self::POINT_MINUTES);
                $out[] = [$start->utc(), $end->max($start)->utc()];
            } catch (Throwable) {
                continue; // a malformed event never breaks the page
            }
        }

        return $out;
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private static function issues(int $hostId, CarbonImmutable $from, CarbonImmutable $to, string $zone): array
    {
        return Item::query()
            ->where('assigned_to', $hostId)
            ->whereNotNull('due_time')
            ->whereNull('completed_at')
            ->whereHas('board')
            ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
            // a booking's own card: the booking already blocks (and stops blocking once cancelled)
            ->whereNotIn('id', Booking::whereNotNull('item_id')->select('item_id'))
            ->whereBetween('due_date', self::localDates($from, $to, $zone))
            ->get(['id', 'start_date', 'start_time', 'due_date', 'due_time'])
            ->map(fn (Item $item) => self::timedEntry($item->start_date, $item->start_time, $item->due_date, $item->due_time, $zone))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private static function tasks(int $hostId, CarbonImmutable $from, CarbonImmutable $to, string $zone): array
    {
        return TaskListItem::query()
            // personal tasks without an assignee belong to their creator (like reminders)
            ->where(fn ($q) => $q->where('assignee_id', $hostId)->orWhere(fn ($q) => $q->whereNull('assignee_id')->where('created_by', $hostId)))
            ->where('status', '!=', 'done')
            ->whereNotNull('due_time')
            ->whereHas('taskList')
            ->whereBetween('due_date', self::localDates($from, $to, $zone))
            ->get(['id', 'start_date', 'start_time', 'due_date', 'due_time'])
            ->map(fn (TaskListItem $task) => self::timedEntry($task->start_date, $task->start_time, $task->due_date, $task->due_time, $zone))
            ->filter()
            ->values()
            ->all();
    }

    /** @return array{0: string, 1: string} the local dates the range touches, for a due_date filter */
    private static function localDates(CarbonImmutable $from, CarbonImmutable $to, string $zone): array
    {
        return [$from->setTimezone($zone)->toDateString(), $to->setTimezone($zone)->toDateString()];
    }

    /**
     * An issue / task with a due time: start → due when it has a timed start before the due (at most a
     * day), otherwise POINT_MINUTES from the due time. Wall-clock in the host's zone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function timedEntry(mixed $startDate, ?string $startTime, mixed $dueDate, ?string $dueTime, string $zone): ?array
    {
        if (! $dueDate || ! $dueTime) {
            return null;
        }
        $day = fn (mixed $d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
        try {
            $due = CarbonImmutable::parse($day($dueDate).' '.substr($dueTime, 0, 5), $zone);
            if ($startDate && $startTime) {
                $start = CarbonImmutable::parse($day($startDate).' '.substr($startTime, 0, 5), $zone);
                if ($start < $due && $start->diffInHours($due) <= 24) {
                    return [$start->utc(), $due->utc()];
                }
            }

            return [$due->utc(), $due->addMinutes(self::POINT_MINUTES)->utc()];
        } catch (Throwable) {
            return null;
        }
    }
}
