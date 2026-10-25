<?php

namespace App\Support\Helpdesks\Sla;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Business-time arithmetic for helpdesk SLAs (BRAI-125). Either 24/7, or opening hours on some ISO
 * weekdays (1 = Monday) between `from` and `to` in one time zone. `to` before `from` is an overnight
 * shift: the window opens on the listed day and closes the next morning.
 *
 * Windows are built from LOCAL wall-clock times per date and only then turned into instants, so a DST
 * change makes that day's window an hour shorter or longer instead of shifting it. All results are
 * UTC instants. Pure: no database, no clock.
 */
final class BusinessCalendar
{
    /** Enough for a 365-business-day target with one opening day a week. */
    private const MAX_DAYS = 2600;

    /**
     * @param  list<int>|null  $days  ISO weekdays; null = 24/7
     */
    private function __construct(
        private readonly ?array $days,
        private readonly int $from,
        private readonly int $to,
        private readonly string $timezone,
    ) {}

    public static function always(): self
    {
        return new self(null, 0, 0, 'UTC');
    }

    /**
     * @param  array<int|string>  $days  ISO weekdays (1 = Monday … 7 = Sunday)
     */
    public static function hours(array $days, string $from, string $to, string $timezone): self
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn (int $d) => $d >= 1 && $d <= 7)));
        sort($days);
        $fromMinute = self::minuteOfDay($from);
        $toMinute = self::minuteOfDay($to);

        if ($days === [] || $fromMinute === null || $toMinute === null || $fromMinute === $toMinute) {
            throw new InvalidArgumentException('Business hours need at least one day and two different times.');
        }
        if (! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException("Unknown time zone [{$timezone}].");
        }

        return new self($days, $fromMinute, $toMinute, $timezone);
    }

    /** "HH:MM" → minutes after midnight, or null. */
    public static function minuteOfDay(string $time): ?int
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    public function isAlwaysOpen(): bool
    {
        return $this->days === null;
    }

    /** One business day in minutes (1440 for 24/7): what a target of "1 day" means. */
    public function dayMinutes(): int
    {
        return $this->days === null ? 1440 : ($this->to - $this->from + 1440) % 1440;
    }

    /** The instant after $minutes of business time, counted from $start. */
    public function addMinutes(CarbonInterface $start, int $minutes): CarbonImmutable
    {
        $startTs = $start->getTimestamp();
        $remaining = max(0, $minutes) * 60;

        if ($this->days === null) {
            return CarbonImmutable::createFromTimestampUTC($startTs + $remaining);
        }

        foreach ($this->windows($start) as [$open, $close]) {
            if ($close <= $startTs) {
                continue;
            }
            $from = max($open, $startTs);
            $available = $close - $from;
            if ($remaining <= $available) {
                return CarbonImmutable::createFromTimestampUTC($from + $remaining);
            }
            $remaining -= $available;
        }

        throw new RuntimeException('The SLA target lies beyond the business calendar.');
    }

    /** Whole minutes of business time between two instants (0 when $to is not after $from). */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        if ($toTs <= $fromTs) {
            return 0;
        }
        if ($this->days === null) {
            return intdiv($toTs - $fromTs, 60);
        }

        $seconds = 0;
        foreach ($this->windows($from) as [$open, $close]) {
            if ($open >= $toTs) {
                break;
            }
            $seconds += max(0, min($close, $toTs) - max($open, $fromTs));
        }

        return intdiv($seconds, 60);
    }

    /**
     * Opening windows [open, close) as UTC timestamps, in order, starting with the local day before
     * $from (its overnight shift may still be running).
     *
     * @return Generator<int, array{int, int}>
     */
    private function windows(CarbonInterface $from): Generator
    {
        $date = CarbonImmutable::createFromTimestampUTC($from->getTimestamp())->setTimezone($this->timezone)->startOfDay()->subDay();

        for ($i = 0; $i < self::MAX_DAYS; $i++, $date = $date->addDay()) {
            if (! in_array($date->dayOfWeekIso, $this->days ?? [], true)) {
                continue;
            }
            $open = $this->at($date, $this->from);
            $close = $this->at($this->to > $this->from ? $date : $date->addDay(), $this->to);
            if ($close > $open) {
                yield [$open, $close];
            }
        }
    }

    /** A local wall-clock time on a local date, as a UTC timestamp (a time in a DST gap moves forward). */
    private function at(CarbonImmutable $localDate, int $minute): int
    {
        return CarbonImmutable::create($localDate->year, $localDate->month, $localDate->day, intdiv($minute, 60), $minute % 60, 0, $this->timezone)
            ->getTimestamp();
    }
}
