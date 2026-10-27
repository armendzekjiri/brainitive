<?php

namespace App\Support\Booking;

use InvalidArgumentException;

/**
 * The rules of one meeting type (BRAI-158), parsed from `booking_types` (duration, availability,
 * rules). Weekly ranges and date overrides are wall-clock minutes in the page's timezone, so a
 * DST switch never moves "09:00–17:00".
 */
final class BookingRules
{
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    public const DEFAULTS = [
        'step_minutes' => 30,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'min_notice_minutes' => 240,
        'horizon_days' => 60,
        'daily_max' => null,
    ];

    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $weekly  ISO weekday (1 = Monday) → [start, end] minutes
     * @param  array<string, list<array{0: int, 1: int}>>  $overrides  'Y-m-d' → ranges ([] = day off)
     */
    public function __construct(
        public readonly int $durationMinutes,
        public readonly array $weekly,
        public readonly array $overrides = [],
        public readonly int $stepMinutes = 30,
        public readonly int $bufferBefore = 0,
        public readonly int $bufferAfter = 0,
        public readonly int $minNoticeMinutes = 240,
        public readonly int $horizonDays = 60,
        public readonly ?int $dailyMax = null,
    ) {
        if ($durationMinutes < 5 || $durationMinutes > 720) {
            throw new InvalidArgumentException('duration');
        }
        if ($stepMinutes < 5) {
            throw new InvalidArgumentException('step');
        }
    }

    /**
     * @param  array{weekly?: array<string, list<array{0: string, 1: string}>>, overrides?: array<string, list<array{0: string, 1: string}>>}  $availability
     * @param  array<string, mixed>  $rules
     */
    public static function from(int $durationMinutes, array $availability, array $rules): self
    {
        $weekly = [];
        foreach (self::DAYS as $key => $iso) {
            $weekly[$iso] = self::ranges($availability['weekly'][$key] ?? []);
        }
        $overrides = [];
        foreach ((array) ($availability['overrides'] ?? []) as $date => $ranges) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $overrides[$date] = self::ranges((array) $ranges);
            }
        }
        $rules = array_merge(self::DEFAULTS, array_intersect_key($rules, self::DEFAULTS));

        return new self(
            durationMinutes: $durationMinutes,
            weekly: $weekly,
            overrides: $overrides,
            stepMinutes: (int) $rules['step_minutes'],
            bufferBefore: (int) $rules['buffer_before'],
            bufferAfter: (int) $rules['buffer_after'],
            minNoticeMinutes: (int) $rules['min_notice_minutes'],
            horizonDays: (int) $rules['horizon_days'],
            dailyMax: $rules['daily_max'] === null ? null : (int) $rules['daily_max'],
        );
    }

    /** "HH:MM" → minutes after midnight; "24:00" is the end of the day. Null when malformed. */
    public static function minutes(string $time): ?int
    {
        if (! preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $time, $m) || ($m[1] === '24' && $m[2] !== '00')) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * Valid ranges only (start < end), sorted, overlaps merged.
     *
     * @param  array<mixed>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function ranges(array $ranges): array
    {
        $out = [];
        foreach ($ranges as $range) {
            if (! is_array($range) || count($range) !== 2) {
                continue;
            }
            [$start, $end] = [self::minutes((string) $range[0]), self::minutes((string) $range[1])];
            if ($start !== null && $end !== null && $start < $end) {
                $out[] = [$start, $end];
            }
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($out as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * The open ranges of a local date: its override, else its weekday.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function rangesFor(string $date, int $isoWeekday): array
    {
        return $this->overrides[$date] ?? $this->weekly[$isoWeekday] ?? [];
    }
}
