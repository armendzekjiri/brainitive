<?php

namespace App\Support\RecurringIssues;

use Carbon\CarbonImmutable;

/**
 * The dates of a recurring issue (BRAI-123). Pure calendar maths on `Y-m-d` dates (midnight UTC, no
 * time zone), so DST never shifts a date; the template's time zone only decides which date is "today"
 * (RecurringIssue::localToday()).
 *
 * - daily: every `interval` days from the start date;
 * - weekly: on `weekdays` (ISO 1 = Mon … 7 = Sun) of every `interval`-th week (weeks start on Monday,
 *   counted from the start date's week);
 * - monthly: every `interval`-th month on day `month_day` (clamped: 31 = the last day of shorter
 *   months, 30 → Feb 28/29), or on the `month_week`-th (1–4, -1 = last) `month_weekday` (1–7, 0 = any
 *   weekday Mon–Fri: "the first weekday", "the last weekday");
 * - yearly: every `interval`-th year on the start date's day and month (Feb 29 → Feb 28).
 *
 * Every date is computed from the rule, never from the previous date, so months never drift
 * (Jan 31 → Feb 28 → Mar 31). Occurrences are on or after `start` and on or before `end`.
 */
final class RecurrenceSchedule
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    public const MONTHLY_MODES = ['day', 'weekday'];

    public const MONTH_WEEKS = [1, 2, 3, 4, -1];

    public const MAX_INTERVAL = 99;

    /** Safety stop for the period loops (a valid rule finds a date in the first period it looks at). */
    private const MAX_PERIODS = 2000;

    private const WEEKDAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private const ORDINALS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last'];

    /**
     * @param  list<int>  $weekdays
     */
    public function __construct(
        public readonly string $frequency,
        public readonly int $interval,
        public readonly array $weekdays,
        public readonly string $monthlyMode,
        public readonly int $monthDay,
        public readonly int $monthWeek,
        public readonly int $monthWeekday,
        public readonly CarbonImmutable $start,
        public readonly ?CarbonImmutable $end,
    ) {}

    /**
     * Build from template attributes; fills the defaults from the start date (weekday, day of month)
     * and repairs out-of-range values.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $start = self::date($data['start_date']);
        $frequency = in_array($data['frequency'] ?? null, self::FREQUENCIES, true) ? $data['frequency'] : 'weekly';

        $weekdays = collect($data['weekdays'] ?? [])
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()->sort()->values()->all();

        $monthWeek = (int) ($data['month_week'] ?? 1);
        $monthWeekday = $data['month_weekday'] ?? null;

        return new self(
            frequency: $frequency,
            interval: max(1, min(self::MAX_INTERVAL, (int) ($data['interval'] ?? 1))),
            weekdays: $weekdays === [] ? [$start->dayOfWeekIso] : $weekdays,
            monthlyMode: ($data['monthly_mode'] ?? 'day') === 'weekday' ? 'weekday' : 'day',
            monthDay: max(1, min(31, (int) ($data['month_day'] ?? $start->day))),
            monthWeek: in_array($monthWeek, self::MONTH_WEEKS, true) ? $monthWeek : 1,
            monthWeekday: $monthWeekday === null ? $start->dayOfWeekIso : max(0, min(7, (int) $monthWeekday)),
            start: $start,
            end: empty($data['end_date']) ? null : self::date($data['end_date']),
        );
    }

    /** A calendar date (string, Carbon or DateTime) as midnight UTC. */
    public static function date(mixed $value): CarbonImmutable
    {
        $string = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);

        return CarbonImmutable::createFromFormat('!Y-m-d', $string, 'UTC');
    }

    /** The first occurrence on or after $from (null = the series ended). */
    public function firstOnOrAfter(CarbonImmutable $from): ?CarbonImmutable
    {
        $from = $from->max($this->start);
        $period = $this->periodIndex($from);
        $period = intdiv($period + $this->interval - 1, $this->interval) * $this->interval; // the next active period

        for ($i = 0; $i < self::MAX_PERIODS; $i++, $period += $this->interval) {
            foreach ($this->candidates($period) as $date) {
                if ($this->end && $date->gt($this->end)) {
                    return null;
                }
                if ($date->gte($from)) {
                    return $date;
                }
            }
        }

        return null;
    }

    public function firstAfter(CarbonImmutable $date): ?CarbonImmutable
    {
        return $this->firstOnOrAfter($date->addDay());
    }

    /** The latest occurrence in [$from, $to] (null = none). */
    public function latestBetween(CarbonImmutable $from, CarbonImmutable $to): ?CarbonImmutable
    {
        $from = $from->max($this->start);
        if ($this->end && $to->gt($this->end)) {
            $to = $this->end;
        }
        if ($to->lt($from)) {
            return null;
        }

        $period = intdiv($this->periodIndex($to), $this->interval) * $this->interval;
        for ($i = 0; $i < self::MAX_PERIODS && $period >= 0; $i++, $period -= $this->interval) {
            foreach (array_reverse($this->candidates($period)) as $date) {
                if ($date->lt($from)) {
                    return null; // every earlier date is earlier still
                }
                if ($date->lte($to)) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Up to $count occurrences on or after $from.
     *
     * @return list<CarbonImmutable>
     */
    public function upcoming(CarbonImmutable $from, int $count): array
    {
        $dates = [];
        $next = $this->firstOnOrAfter($from);
        while ($next && count($dates) < $count) {
            $dates[] = $next;
            $next = $this->firstAfter($next);
        }

        return $dates;
    }

    /** The card badge: "Repeats weekly", "Repeats every 2 weeks". */
    public static function badge(string $frequency, int $interval): string
    {
        $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$frequency] ?? 'week';

        return $interval > 1 ? "Repeats every {$interval} {$unit}s" : "Repeats {$frequency}";
    }

    /** "Every 2 weeks on Mon, Fri", "Every month on the last weekday", "Every year on Mar 5". */
    public function summary(): string
    {
        $every = fn (string $unit) => $this->interval > 1 ? "Every {$this->interval} {$unit}s" : "Every {$unit}";

        return match ($this->frequency) {
            'daily' => $every('day'),
            'weekly' => $every('week').' on '.implode(', ', array_map(fn (int $day) => substr(self::WEEKDAY_NAMES[$day], 0, 3), $this->weekdays)),
            'monthly' => $every('month').' on '.($this->monthlyMode === 'weekday'
                ? 'the '.self::ORDINALS[$this->monthWeek].' '.($this->monthWeekday === 0 ? 'weekday' : self::WEEKDAY_NAMES[$this->monthWeekday])
                : match (true) {
                    $this->monthDay === 31 => 'the last day',
                    $this->monthDay >= 29 => "day {$this->monthDay} (or the last day)",
                    default => "day {$this->monthDay}",
                }),
            default => $every('year').' on '.$this->start->format('M j'),
        };
    }

    /** Index of the period (day / week / month / year) that contains $date, counted from the start's. */
    private function periodIndex(CarbonImmutable $date): int
    {
        return match ($this->frequency) {
            'daily' => self::daysBetween($this->start, $date),
            'weekly' => intdiv(self::daysBetween($this->weekStart($this->start), $date), 7),
            'monthly' => ($date->year - $this->start->year) * 12 + $date->month - $this->start->month,
            default => $date->year - $this->start->year,
        };
    }

    /**
     * The dates of period $period in ascending order (on or after the start date).
     *
     * @return list<CarbonImmutable>
     */
    private function candidates(int $period): array
    {
        $dates = match ($this->frequency) {
            'daily' => [$this->start->addDays($period)],
            'weekly' => array_map(
                fn (int $day) => $this->weekStart($this->start)->addDays($period * 7 + $day - 1),
                $this->weekdays,
            ),
            'monthly' => [$this->monthlyDate($this->start->startOfMonth()->addMonthsNoOverflow($period))],
            default => [$this->dayInMonth($this->start->year + $period, $this->start->month, $this->start->day)],
        };

        return array_values(array_filter($dates, fn (CarbonImmutable $date) => $date->gte($this->start)));
    }

    private function monthlyDate(CarbonImmutable $month): CarbonImmutable
    {
        if ($this->monthlyMode === 'day') {
            return $this->dayInMonth($month->year, $month->month, $this->monthDay);
        }

        if ($this->monthWeekday === 0) { // business days (Mon–Fri)
            if ($this->monthWeek === -1) {
                $date = $month->endOfMonth()->startOfDay();
                while ($date->isWeekend()) {
                    $date = $date->subDay();
                }

                return $date;
            }
            $date = $month;
            $count = 0;
            while (true) {
                if (! $date->isWeekend() && ++$count === $this->monthWeek) {
                    return $date;
                }
                $date = $date->addDay();
            }
        }

        if ($this->monthWeek === -1) {
            $last = $month->endOfMonth()->startOfDay();

            return $last->subDays(($last->dayOfWeekIso - $this->monthWeekday + 7) % 7);
        }

        return $month->addDays(($this->monthWeekday - $month->dayOfWeekIso + 7) % 7 + ($this->monthWeek - 1) * 7);
    }

    /** Day $day of the month, clamped to its last day (31 → 30 / 28 / 29). */
    private function dayInMonth(int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');

        return $first->addDays(min($day, $first->daysInMonth) - 1);
    }

    private function weekStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->subDays($date->dayOfWeekIso - 1);
    }

    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }
}
