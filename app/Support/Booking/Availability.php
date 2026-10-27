<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;

/**
 * The free slots of a meeting type (BRAI-158): its open hours minus busy time. Pure — no database,
 * no calendars: callers pass the busy intervals (BusyTimes) and the bookings per day.
 *
 * Open hours are built as wall-clock times in the page's timezone on each local date, then compared
 * in absolute time, so slots stay at 09:00 local across a DST switch. A slot fits when
 * [start − buffer before, end + buffer after] touches no busy interval.
 */
final class Availability
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     * @param  array<string, int>  $bookedPerDay  local date ('Y-m-d', page zone) → confirmed bookings of this type
     * @return list<CarbonImmutable> slot starts in UTC, ascending, within [$from, $to)
     */
    public static function slots(
        BookingRules $rules,
        string $timezone,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $busy,
        array $bookedPerDay,
        CarbonImmutable $now,
    ): array {
        $earliest = $now->addMinutes($rules->minNoticeMinutes);
        $latest = $now->addDays($rules->horizonDays);
        $from = $from->max($earliest);
        $to = $to->min($latest);
        if ($from >= $to) {
            return [];
        }

        usort($busy, fn ($a, $b) => $a[0] <=> $b[0]);

        $slots = [];
        $day = $from->setTimezone($timezone)->startOfDay();
        $lastDay = $to->setTimezone($timezone)->startOfDay();
        for (; $day <= $lastDay; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            // the daily max counts bookings, not offered slots: below it, every free slot stays bookable
            if ($rules->dailyMax !== null && ($bookedPerDay[$date] ?? 0) >= $rules->dailyMax) {
                continue;
            }
            foreach ($rules->rangesFor($date, $day->isoWeekday()) as [$open, $close]) {
                $windowStart = self::at($date, $open, $timezone);
                $windowEnd = self::at($date, $close, $timezone);
                for ($start = $windowStart; ; $start = $start->addMinutes($rules->stepMinutes)) {
                    $end = $start->addMinutes($rules->durationMinutes);
                    if ($end > $windowEnd) {
                        break;
                    }
                    if ($start < $from || $start >= $to) {
                        continue;
                    }
                    if (self::overlaps($busy, $start->subMinutes($rules->bufferBefore), $end->addMinutes($rules->bufferAfter))) {
                        continue;
                    }
                    $slots[] = $start->utc();
                }
            }
        }

        return $slots;
    }

    /** Local wall-clock time on a date (minutes after midnight; 1440 = next midnight), as UTC. */
    public static function at(string $date, int $minutes, string $timezone): CarbonImmutable
    {
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' 00:00', $timezone);
        if ($minutes >= 1440) {
            return $local->addDay()->startOfDay()->utc();
        }

        // setTime keeps the wall clock (a time inside a DST gap moves forward, like every calendar)
        return $local->setTime(intdiv($minutes, 60), $minutes % 60)->utc();
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy  sorted by start
     */
    private static function overlaps(array $busy, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($busyStart >= $end) {
                return false;
            }
            if ($busyEnd > $start) {
                return true;
            }
        }

        return false;
    }
}
