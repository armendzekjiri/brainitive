<?php

namespace App\Support\Calendar\Ics;

use Carbon\CarbonImmutable;

/**
 * The VEVENT occurrences of a calendar that overlap a range of whole days (BRAI-141):
 * one-off events, RRULE / RDATE series minus EXDATEs, and RECURRENCE-ID overrides replacing (or,
 * with STATUS:CANCELLED, removing) the occurrence they stand for. Several one-off VEVENTs with the
 * same UID (badly generated feeds) are all kept, each told apart by the key of its DTSTART.
 * Bounded: $max occurrences, MAX_EVENTS VEVENTs and MAX_PERIODS recurrence periods per call.
 */
final class IcsEvents
{
    public const MAX_INSTANCES = 2000;

    public const MAX_EVENTS = 20000;

    /** Recurrence periods walked per call, shared by every series of the calendar. */
    public const MAX_PERIODS = 50000;

    /** The UID of a VEVENT, or a stable stand-in for one without (DTSTART + SUMMARY). */
    public static function uidOf(IcsComponent $event): string
    {
        return $event->text('UID') ?? 'nouid-'.sha1(($event->first('DTSTART')?->value ?? '').'|'.($event->text('SUMMARY') ?? ''));
    }

    /**
     * Occurrences overlapping the days [$fromDate, $toDate] (inclusive, Y-m-d) in $zone — the
     * zone that also stands in for floating times when the calendar has no X-WR-TIMEZONE.
     *
     * @param  list<IcsComponent>|null  $events  VEVENTs to use (default: all of $calendar)
     * @return list<IcsInstance> sorted by start
     */
    public static function between(IcsComponent $calendar, string $fromDate, string $toDate, string $zone, ?array $events = null, int $max = self::MAX_INSTANCES): array
    {
        $time = new IcsTime($calendar, $zone);
        $from = CarbonImmutable::parse($fromDate, $zone)->startOfDay();
        $to = CarbonImmutable::parse($toDate, $zone)->startOfDay()->addDay();
        $fromDay = CarbonImmutable::parse($fromDate, 'UTC')->startOfDay();
        $toDay = CarbonImmutable::parse($toDate, 'UTC')->startOfDay()->addDay();

        $masters = [];
        $overrides = [];
        foreach (array_slice($events ?? $calendar->children('VEVENT'), 0, self::MAX_EVENTS) as $event) {
            $uid = self::uidOf($event);
            $recurrenceId = $time->parse($event->first('RECURRENCE-ID'));
            if ($recurrenceId !== null) {
                $overrides[$uid][IcsTime::key(...$recurrenceId)] = $event;
            } else {
                $masters[$uid][] = $event;
            }
        }
        $budget = self::MAX_PERIODS;

        $out = [];
        $overlaps = function (CarbonImmutable $start, CarbonImmutable $end, bool $allDay) use ($from, $to, $fromDay, $toDay): bool {
            return $allDay
                ? $start < $toDay && $end > $fromDay
                : $start < $to && ($end > $from || ($end == $start && $start >= $from));
        };

        foreach ($masters as $uid => $sameUid) {
            foreach ($sameUid as $event) {
                if (count($out) >= $max) {
                    break 2;
                }
                $span = self::span($event, $time);
                if ($span === null || strtoupper((string) $event->text('STATUS')) === 'CANCELLED') {
                    continue;
                }
                [$start, $end, $allDay] = $span;
                $floating = self::isFloating($event, $time);
                $length = $end->getTimestamp() - $start->getTimestamp();
                $days = (int) round($length / 86400);

                $rrule = $event->first('RRULE');
                $rule = $rrule ? RecurrenceRule::parse($rrule->value, $start->getTimezone(), $allDay) : null;
                $rdates = $event->all('RDATE');
                if ($rule === null && $rdates === []) {
                    if ($overlaps($start, $end, $allDay)) {
                        // one of several events sharing a UID: its start tells it apart
                        $key = count($sameUid) > 1 ? IcsTime::key($start, $allDay, $floating) : null;
                        $out[] = new IcsInstance($event, (string) $uid, $key, $start, $end, $allDay);
                    }

                    continue;
                }

                // occurrences that may overlap: starting after (window start − length)
                $earliest = $allDay ? $fromDay->subDays(max(0, $days)) : $from->subSeconds(max(0, $length));
                $latest = $allDay ? $toDay : $to;
                $starts = $rule ? $rule->occurrences($start, $earliest, $latest, $max, $budget) : ($start >= $earliest && $start < $latest ? [$start] : []);
                foreach ($rdates as $rdate) {
                    foreach ($time->parseList($rdate) as [$at, $isDate]) {
                        $at = $allDay ? $at : ($isDate ? CarbonImmutable::create($at->year, $at->month, $at->day, $start->hour, $start->minute, $start->second, $start->getTimezone()) : $at);
                        if ($at >= $earliest && $at < $latest) {
                            $starts[] = $at;
                        }
                    }
                }

                $excluded = [];
                $excludedDays = [];
                foreach ($event->all('EXDATE') as $exdate) {
                    foreach ($time->parseList($exdate) as [$at, $isDate, $exFloating]) {
                        $excluded[IcsTime::key($at, $allDay, $exFloating)] = true;
                        if ($isDate && ! $allDay) {
                            $excludedDays[$at->format('Ymd')] = true; // a DATE EXDATE on a timed series
                        }
                    }
                }

                $seen = [];
                foreach ($starts as $occurrence) {
                    $key = IcsTime::key($occurrence, $allDay, $floating);
                    if (isset($seen[$key]) || isset($excluded[$key]) || isset($excludedDays[$occurrence->format('Ymd')]) || isset($overrides[$uid][$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $occurrenceEnd = $allDay ? $occurrence->addDays($days) : self::addLength($occurrence, $start, $end);
                    if ($overlaps($occurrence, $occurrenceEnd, $allDay)) {
                        $out[] = new IcsInstance($event, (string) $uid, $key, $occurrence, $occurrenceEnd, $allDay);
                    }
                }
            }
        }

        foreach ($overrides as $uid => $byKey) {
            foreach ($byKey as $key => $event) {
                $span = self::span($event, $time);
                if ($span === null || strtoupper((string) $event->text('STATUS')) === 'CANCELLED' || count($out) >= $max) {
                    continue;
                }
                [$start, $end, $allDay] = $span;
                if ($overlaps($start, $end, $allDay)) {
                    $out[] = new IcsInstance($event, (string) $uid, (string) $key, $start, $end, $allDay);
                }
            }
        }

        usort($out, fn (IcsInstance $a, IcsInstance $b) => [$a->start->getTimestamp(), $a->uid] <=> [$b->start->getTimestamp(), $b->uid]);

        return array_slice($out, 0, $max);
    }

    /**
     * One occurrence of the event made of $events (a master and / or its overrides, one UID):
     * the event itself when $key is null, else the override for $key or the master's occurrence
     * whose original start has that key. Null when there is no such (non-cancelled) occurrence.
     *
     * @param  list<IcsComponent>  $events
     */
    public static function occurrence(IcsComponent $calendar, array $events, ?string $key, string $zone): ?IcsInstance
    {
        $time = new IcsTime($calendar, $zone);
        $masters = [];
        foreach ($events as $event) {
            $recurrenceId = $time->parse($event->first('RECURRENCE-ID'));
            if ($recurrenceId === null) {
                $masters[] = $event;
            } elseif ($key !== null && IcsTime::key(...$recurrenceId) === $key) {
                $span = self::span($event, $time);
                if ($span === null || strtoupper((string) $event->text('STATUS')) === 'CANCELLED') {
                    return null;
                }

                return new IcsInstance($event, self::uidOf($event), $key, ...$span);
            }
        }
        if ($masters === []) {
            return null;
        }
        if ($key === null) {
            $span = self::span($masters[0], $time);

            return $span !== null ? new IcsInstance($masters[0], self::uidOf($masters[0]), null, ...$span) : null;
        }

        $day = preg_match('/^(\d{4})(\d{2})(\d{2})/', $key, $m) ? CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC') : null;
        foreach ($masters as $master) {
            $span = self::span($master, $time);
            if ($span === null || $day === null) {
                continue;
            }
            if ($master->first('RRULE') === null && $master->all('RDATE') === []) {
                // one of several one-off events sharing a UID, told apart by its start
                if (IcsTime::key($span[0], $span[2], self::isFloating($master, $time)) === $key) {
                    return new IcsInstance($master, self::uidOf($master), $key, ...$span);
                }

                continue;
            }
            // an occurrence of the series starting on the key's day (± a day for zone differences)
            $lengthDays = (int) ceil(($span[1]->getTimestamp() - $span[0]->getTimestamp()) / 86400);
            foreach (self::between($calendar, $day->subDay()->toDateString(), $day->addDays(1 + $lengthDays)->toDateString(), $zone, [$master]) as $instance) {
                if ($instance->recurrenceKey === $key) {
                    return $instance;
                }
            }
        }

        return null;
    }

    /** Whether the VEVENT's DTSTART is a floating DATE-TIME (no Z, no TZID). */
    public static function isFloating(IcsComponent $event, IcsTime $time): bool
    {
        return (bool) ($time->parse($event->first('DTSTART'))[2] ?? false);
    }

    /**
     * Start, end and all-day flag of a VEVENT: DTEND, else DURATION, else (RFC 5545) one day for
     * a DATE start and zero length for a DATE-TIME start. An end before the start is clamped.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}|null
     */
    public static function span(IcsComponent $event, IcsTime $time): ?array
    {
        $start = $time->parse($event->first('DTSTART'));
        if ($start === null) {
            return null;
        }
        [$startAt, $allDay] = $start;

        $end = $time->parse($event->first('DTEND'));
        if ($end !== null) {
            // an all-day start with a DATE-TIME end (broken feeds): the end's wall-clock day
            $endAt = $allDay
                ? ($end[1] ? $end[0] : CarbonImmutable::create($end[0]->year, $end[0]->month, $end[0]->day, 0, 0, 0, 'UTC'))
                : ($end[1] ? $startAt : $end[0]);
        } elseif (($duration = IcsTime::duration($event->first('DURATION')?->value)) !== null) {
            $endAt = $allDay ? $startAt->addDays(max(1, $duration[0])) : $startAt->addDays($duration[0])->addSeconds($duration[1]);
        } else {
            $endAt = $allDay ? $startAt->addDay() : $startAt;
        }

        if ($allDay && $endAt <= $startAt) {
            $endAt = $startAt->addDay();
        } elseif ($endAt < $startAt) {
            $endAt = $startAt;
        }

        return [$startAt, $endAt, $allDay];
    }

    /** The end of an occurrence: same wall-clock length as the master (DST-safe for whole days). */
    private static function addLength(CarbonImmutable $occurrence, CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        $endInZone = $end->setTimezone($start->getTimezone());
        $days = (int) $start->startOfDay()->diffInDays($endInZone->startOfDay());
        $seconds = ($endInZone->secondsSinceMidnight() - $start->secondsSinceMidnight());

        return $occurrence->addDays($days)->addSeconds((int) $seconds);
    }
}
