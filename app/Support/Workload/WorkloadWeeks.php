<?php

namespace App\Support\Workload;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Week math for workload planning (BRAI-75). Weeks run Monday–Sunday (ISO 8601) and are
 * identified by their Monday's calendar date (`Y-m-d`). Dates are handled as UTC calendar days,
 * so DST changes can't shift a week; "this week" is taken from the viewer's own time zone.
 */
class WorkloadWeeks
{
    /** Week counts the grid offers. */
    public const SPANS = [4, 6, 8, 12];

    public const DEFAULT_SPAN = 6;

    /** The Monday (Y-m-d) of the week that contains the given calendar day. */
    public static function monday(string|DateTimeInterface $day): string
    {
        $date = $day instanceof DateTimeInterface ? $day->format('Y-m-d') : $day;

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** True for a real `Y-m-d` date that is a Monday. */
    public static function isMonday(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== null && $date->toDateString() === $value && $date->isMonday();
    }

    /** Monday of the viewer's current week, in their own time zone. */
    public static function current(User $user, ?DateTimeInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($user->localTimezone());

        return self::monday($now->format('Y-m-d'));
    }

    /** Monday + n weeks (n may be negative). */
    public static function add(string $monday, int $weeks): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC')->addWeeks($weeks)->toDateString();
    }

    /**
     * The Mondays of `count` weeks starting with `from`.
     *
     * @return list<string>
     */
    public static function range(string $from, int $count): array
    {
        return array_map(fn (int $i) => self::add($from, $i), range(0, max(1, $count) - 1));
    }

    /**
     * Display data of a week: ISO week number + ISO year (the year of its Thursday), and Sunday.
     *
     * @return array{start: string, end: string, week: int, year: int, label: string}
     */
    public static function describe(string $monday): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC');

        return [
            'start' => $monday,
            'end' => $date->addDays(6)->toDateString(),
            'week' => (int) $date->format('W'),
            'year' => (int) $date->format('o'),
            'label' => 'W'.(int) $date->format('W'),
        ];
    }
}
