<?php

namespace App\Support\Calendar\Ics;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * RRULE expansion (RFC 5545 §3.3.10) for what calendars actually use: FREQ DAILY / WEEKLY /
 * MONTHLY / YEARLY, INTERVAL, COUNT, UNTIL, BYDAY (with ordinals: 2TU, -1FR), BYMONTHDAY
 * (negative = from the month's end), BYMONTH, BYSETPOS, WKST. Occurrences keep DTSTART's
 * wall-clock time in its zone (DST-correct). BYHOUR / BYMINUTE / BYSECOND are ignored;
 * rules we can't expand (sub-daily FREQ, BYWEEKNO, BYYEARDAY) give null — the event then shows
 * once, at DTSTART. Every expansion is capped (MAX_PERIODS periods, $max occurrences).
 */
final class RecurrenceRule
{
    public const MAX_PERIODS = 5000;

    private const WEEKDAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * @param  list<array{0: int, 1: int}>  $byDay  [ordinal (0 = every), ISO weekday]
     * @param  list<int>  $byMonthDay
     * @param  list<int>  $byMonth
     * @param  list<int>  $bySetPos
     */
    private function __construct(
        public readonly string $freq,
        public readonly int $interval,
        public readonly ?int $count,
        public readonly ?CarbonImmutable $until,
        public readonly array $byDay,
        public readonly array $byMonthDay,
        public readonly array $byMonth,
        public readonly array $bySetPos,
        public readonly int $wkst,
    ) {}

    /** Null when the rule is missing, broken or uses parts we can't expand. */
    public static function parse(string $value, DateTimeZone $zone, bool $allDay): ?self
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($value))) as $pair) {
            [$key, $val] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '') {
                $parts[trim($key)] = trim($val);
            }
        }

        $freq = $parts['FREQ'] ?? '';
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true) || isset($parts['BYWEEKNO']) || isset($parts['BYYEARDAY'])) {
            return null;
        }

        $byDay = [];
        foreach (self::list($parts['BYDAY'] ?? '') as $day) {
            if (! preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $day, $m)) {
                return null;
            }
            $byDay[] = [(int) ($m[1] ?? 0), self::WEEKDAYS[$m[2]]];
        }
        $byDay = array_values(array_unique($byDay, SORT_REGULAR)); // "MO,MO,MO,…" must not multiply the work

        $ints = fn (string $key, int $min, int $max) => array_values(array_unique(array_filter(
            array_map('intval', self::list($parts[$key] ?? '')),
            fn (int $n) => $n !== 0 && abs($n) >= $min && abs($n) <= $max,
        )));

        $count = isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null;

        return new self(
            $freq,
            max(1, min(1000, (int) ($parts['INTERVAL'] ?? 1))),
            $count,
            isset($parts['UNTIL']) ? self::until($parts['UNTIL'], $zone, $allDay) : null,
            $byDay,
            $ints('BYMONTHDAY', 1, 31),
            array_values(array_filter($ints('BYMONTH', 1, 12), fn (int $n) => $n > 0)),
            $ints('BYSETPOS', 1, 366),
            self::WEEKDAYS[$parts['WKST'] ?? 'MO'] ?? 1,
        );
    }

    /**
     * Occurrences starting in [$from, $to), DTSTART included (it is always the first occurrence).
     * $budget (shared by every rule of a calendar) is the number of periods left to walk: a feed
     * full of long COUNT rules can't burn the request's time.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrences(CarbonImmutable $dtstart, CarbonImmutable $from, CarbonImmutable $to, int $max = 1000, ?int &$budget = null): array
    {
        $budget ??= self::MAX_PERIODS;
        $out = [];
        if ($dtstart >= $from && $dtstart < $to) {
            $out[] = $dtstart;
        }
        if ($this->count === 1 || ($this->until !== null && $dtstart > $this->until)) {
            return $out;
        }

        // Without COUNT nothing before the window matters: jump close to it.
        $period = 0;
        if ($this->count === null && $from > $dtstart) {
            $period = max(0, intdiv($this->periodsBetween($dtstart, $from), $this->interval) - 1) * $this->interval;
        }

        $emitted = 1;
        for ($i = 0; $i < self::MAX_PERIODS && $budget > 0; $i++, $period += $this->interval, $budget--) {
            if ($this->periodStart($dtstart, $period) >= $to) {
                break;
            }
            foreach ($this->candidates($dtstart, $period) as $candidate) {
                if ($candidate <= $dtstart) {
                    continue;
                }
                if (($this->until !== null && $candidate > $this->until) || $candidate >= $to) {
                    return $out;
                }
                $emitted++;
                if ($this->count !== null && $emitted > $this->count) {
                    return $out;
                }
                if ($candidate >= $from) {
                    $out[] = $candidate;
                    if (count($out) >= $max) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v) => $v !== ''));
    }

    private static function until(string $value, DateTimeZone $zone, bool $allDay): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        if ($allDay) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC');
        }
        if (! isset($m[4]) || $m[4] === '') {
            // a DATE UNTIL on a timed rule: the whole day counts
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 23, 59, 59, $zone);
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], ! empty($m[7]) ? 'UTC' : $zone);
    }

    /** Whole periods between DTSTART's period and the one containing $at. */
    private function periodsBetween(CarbonImmutable $dtstart, CarbonImmutable $at): int
    {
        $at = $at->setTimezone($dtstart->getTimezone());

        return match ($this->freq) {
            'DAILY' => (int) $dtstart->startOfDay()->diffInDays($at->startOfDay()),
            'WEEKLY' => intdiv((int) $this->weekStart($dtstart)->diffInDays($this->weekStart($at)), 7),
            'MONTHLY' => ($at->year - $dtstart->year) * 12 + $at->month - $dtstart->month,
            default => $at->year - $dtstart->year,
        };
    }

    /** First moment of period number $n (DTSTART's zone). */
    private function periodStart(CarbonImmutable $dtstart, int $n): CarbonImmutable
    {
        return match ($this->freq) {
            'DAILY' => $dtstart->startOfDay()->addDays($n),
            'WEEKLY' => $this->weekStart($dtstart)->addWeeks($n),
            'MONTHLY' => $dtstart->startOfMonth()->addMonthsNoOverflow($n),
            default => $dtstart->startOfYear()->addYears($n),
        };
    }

    private function weekStart(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfDay()->subDays(($day->dayOfWeekIso - $this->wkst + 7) % 7);
    }

    /**
     * The occurrences of period $n, sorted, BYSETPOS applied.
     *
     * @return list<CarbonImmutable>
     */
    private function candidates(CarbonImmutable $dtstart, int $n): array
    {
        $start = $this->periodStart($dtstart, $n);
        $dates = []; // [year, month, day]

        switch ($this->freq) {
            case 'DAILY':
                if ($this->matchesFilters($start)) {
                    $dates[] = [$start->year, $start->month, $start->day];
                }
                break;
            case 'WEEKLY':
                $weekdays = $this->byDay ? array_unique(array_map(fn (array $d) => $d[1], $this->byDay)) : [$dtstart->dayOfWeekIso];
                foreach ($weekdays as $weekday) {
                    $day = $start->addDays(($weekday - $this->wkst + 7) % 7);
                    if ($this->byMonth === [] || in_array($day->month, $this->byMonth, true)) {
                        $dates[] = [$day->year, $day->month, $day->day];
                    }
                }
                break;
            case 'MONTHLY':
                if ($this->byMonth === [] || in_array($start->month, $this->byMonth, true)) {
                    foreach ($this->monthDays($dtstart, $start->year, $start->month) as $d) {
                        $dates[] = [$start->year, $start->month, $d];
                    }
                }
                break;
            default: // YEARLY
                $year = $start->year;
                if ($this->byMonth === [] && $this->byDay !== [] && $this->byMonthDay === []) {
                    foreach ($this->yearWeekdays($year) as [$m, $d]) {
                        $dates[] = [$year, $m, $d];
                    }
                    break;
                }
                $months = $this->byMonth !== [] ? $this->byMonth : ($this->byMonthDay !== [] ? range(1, 12) : [$dtstart->month]);
                sort($months);
                foreach ($months as $m) {
                    foreach ($this->monthDays($dtstart, $year, $m) as $d) {
                        $dates[] = [$year, $m, $d];
                    }
                }
        }

        usort($dates, fn (array $a, array $b) => $a <=> $b);
        $dates = array_values(array_unique($dates, SORT_REGULAR));
        if ($this->bySetPos !== [] && in_array($this->freq, ['MONTHLY', 'YEARLY', 'WEEKLY'], true)) {
            $picked = [];
            foreach ($this->bySetPos as $pos) {
                $index = $pos > 0 ? $pos - 1 : count($dates) + $pos;
                if (isset($dates[$index])) {
                    $picked[$index] = $dates[$index];
                }
            }
            ksort($picked);
            $dates = array_values($picked);
        }

        $zone = $dtstart->getTimezone();

        return array_map(fn (array $d) => CarbonImmutable::create($d[0], $d[1], $d[2], $dtstart->hour, $dtstart->minute, $dtstart->second, $zone), $dates);
    }

    /** DAILY: BYMONTH / BYMONTHDAY / BYDAY limit the days. */
    private function matchesFilters(CarbonImmutable $day): bool
    {
        if ($this->byMonth !== [] && ! in_array($day->month, $this->byMonth, true)) {
            return false;
        }
        if ($this->byMonthDay !== []) {
            $dim = $day->daysInMonth;
            $hit = false;
            foreach ($this->byMonthDay as $md) {
                $hit = $hit || ($md > 0 ? $md : $dim + $md + 1) === $day->day;
            }
            if (! $hit) {
                return false;
            }
        }

        return $this->byDay === [] || in_array($day->dayOfWeekIso, array_map(fn (array $d) => $d[1], $this->byDay), true);
    }

    /** @return list<int> days of the month the rule picks */
    private function monthDays(CarbonImmutable $dtstart, int $year, int $month): array
    {
        $dim = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->daysInMonth;
        $days = null;

        if ($this->byMonthDay !== []) {
            $days = [];
            foreach ($this->byMonthDay as $md) {
                $d = $md > 0 ? $md : $dim + $md + 1;
                if ($d >= 1 && $d <= $dim) {
                    $days[] = $d;
                }
            }
        }

        if ($this->byDay !== []) {
            $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->dayOfWeekIso;
            $weekdayDays = [];
            foreach ($this->byDay as [$ordinal, $weekday]) {
                $all = range(1 + ($weekday - $first + 7) % 7, $dim, 7);
                if ($ordinal === 0) {
                    array_push($weekdayDays, ...$all);
                } elseif (isset($all[$ordinal > 0 ? $ordinal - 1 : count($all) + $ordinal])) {
                    $weekdayDays[] = $all[$ordinal > 0 ? $ordinal - 1 : count($all) + $ordinal];
                }
            }
            $days = $days === null ? $weekdayDays : array_values(array_intersect($days, $weekdayDays));
        }

        if ($days === null) {
            // no BYMONTHDAY / BYDAY: DTSTART's day (months without it are skipped, per RFC 5545)
            $days = $dtstart->day <= $dim ? [$dtstart->day] : [];
        }

        return array_values(array_unique($days));
    }

    /**
     * YEARLY with BYDAY and no BYMONTH: weekdays counted through the whole year (20MO, -1SU).
     *
     * @return list<array{0: int, 1: int}> [month, day]
     */
    private function yearWeekdays(int $year): array
    {
        $out = [];
        $jan1 = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'UTC');
        $daysInYear = $jan1->daysInYear;
        foreach ($this->byDay as [$ordinal, $weekday]) {
            $all = range(($weekday - $jan1->dayOfWeekIso + 7) % 7, $daysInYear - 1, 7);
            $picked = $ordinal === 0 ? $all : (isset($all[$ordinal > 0 ? $ordinal - 1 : count($all) + $ordinal]) ? [$all[$ordinal > 0 ? $ordinal - 1 : count($all) + $ordinal]] : []);
            foreach ($picked as $offset) {
                $day = $jan1->addDays($offset);
                $out[] = [$day->month, $day->day];
            }
        }

        return $out;
    }
}
