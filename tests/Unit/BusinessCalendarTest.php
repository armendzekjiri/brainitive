<?php

namespace Tests\Unit;

use App\Support\Helpdesks\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Business-hours maths of the helpdesk SLA (BRAI-125): pure, no database. */
class BusinessCalendarTest extends TestCase
{
    private const WEEKDAYS = [1, 2, 3, 4, 5];

    private static function local(string $time, string $tz = 'Europe/Berlin'): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $tz);
    }

    /** The result as local wall-clock time, for readable assertions. */
    private static function wall(CarbonImmutable $at, string $tz = 'Europe/Berlin'): string
    {
        return $at->setTimezone($tz)->format('D Y-m-d H:i');
    }

    public function test_round_the_clock_adds_plain_minutes(): void
    {
        $calendar = BusinessCalendar::always();
        $start = CarbonImmutable::parse('2026-10-24 23:30:00', 'UTC');

        $this->assertTrue($calendar->isAlwaysOpen());
        $this->assertSame('2026-10-25 01:30:00', $calendar->addMinutes($start, 120)->format('Y-m-d H:i:s'));
        $this->assertSame(90, $calendar->minutesBetween($start, $start->addMinutes(90)));
        $this->assertSame(0, $calendar->minutesBetween($start, $start->subMinutes(5)));
        $this->assertSame(1440, $calendar->dayMinutes());
    }

    public function test_office_hours_skip_the_night_and_the_weekend(): void
    {
        $calendar = BusinessCalendar::hours(self::WEEKDAYS, '09:00', '17:00', 'Europe/Berlin');

        // Friday 16:00 + 2 h → 1 h on Friday, 1 h on Monday morning
        $this->assertSame('Mon 2026-10-19 10:00', self::wall($calendar->addMinutes(self::local('2026-10-16 16:00'), 120)));
        // before opening: the clock starts at 09:00
        $this->assertSame('Mon 2026-10-19 09:30', self::wall($calendar->addMinutes(self::local('2026-10-19 07:00'), 30)));
        // on Saturday: the clock starts Monday
        $this->assertSame('Mon 2026-10-19 09:45', self::wall($calendar->addMinutes(self::local('2026-10-17 12:00'), 45)));
        // ends exactly at closing time, not at the next opening
        $this->assertSame('Mon 2026-10-19 17:00', self::wall($calendar->addMinutes(self::local('2026-10-19 09:00'), 480)));

        $this->assertSame(120, $calendar->minutesBetween(self::local('2026-10-16 16:00'), self::local('2026-10-19 10:00')));
        $this->assertSame(0, $calendar->minutesBetween(self::local('2026-10-17 08:00'), self::local('2026-10-18 20:00')));
        $this->assertSame(480, $calendar->dayMinutes());
    }

    public function test_overnight_shift_runs_from_the_listed_day_into_the_next_morning(): void
    {
        // Mon–Fri 22:00 → 06:00 (Friday's shift ends Saturday 06:00; Monday's starts Monday 22:00)
        $calendar = BusinessCalendar::hours(self::WEEKDAYS, '22:00', '06:00', 'UTC');
        $at = fn (string $t) => CarbonImmutable::parse($t, 'UTC');

        $this->assertSame(480, $calendar->dayMinutes());
        // Tuesday 05:00 is inside Monday's shift: 1 h left, then Tuesday 22:00 + 1 h
        $this->assertSame('2026-10-20 23:00', $calendar->addMinutes($at('2026-10-20 05:00'), 120)->format('Y-m-d H:i'));
        // Saturday 05:00 is inside Friday's shift; the next shift is Monday night
        $this->assertSame('2026-10-26 22:30', $calendar->addMinutes($at('2026-10-24 05:00'), 90)->format('Y-m-d H:i'));
        // Monday noon → Tuesday noon: Monday 22:00–Tuesday 06:00
        $this->assertSame(480, $calendar->minutesBetween($at('2026-10-19 12:00'), $at('2026-10-20 12:00')));
    }

    public function test_opening_hours_stay_on_local_time_across_dst(): void
    {
        $calendar = BusinessCalendar::hours(self::WEEKDAYS, '09:00', '17:00', 'Europe/Berlin');

        // Friday before the spring change (CET, UTC+1) → Monday after it (CEST, UTC+2): still 09:00 local
        $due = $calendar->addMinutes(self::local('2026-03-27 16:00'), 120);
        $this->assertSame('Mon 2026-03-30 10:00', self::wall($due));
        $this->assertSame('2026-03-30 08:00', $due->utc()->format('Y-m-d H:i'));

        // autumn: Friday CEST → Monday CET
        $due = $calendar->addMinutes(self::local('2026-10-23 16:00'), 120);
        $this->assertSame('Mon 2026-10-26 10:00', self::wall($due));
        $this->assertSame('2026-10-26 09:00', $due->utc()->format('Y-m-d H:i'));
    }

    public function test_a_window_over_the_dst_change_is_an_hour_shorter_or_longer(): void
    {
        // every day 01:00–05:00 local
        $calendar = BusinessCalendar::hours([1, 2, 3, 4, 5, 6, 7], '01:00', '05:00', 'Europe/Berlin');

        // 2026-03-29: 02:00 → 03:00, so Sunday's window is 3 real hours
        $this->assertSame(180, $calendar->minutesBetween(self::local('2026-03-29 00:00'), self::local('2026-03-29 12:00')));
        // 4 h from Saturday after closing: 3 h on Sunday, 1 h on Monday
        $this->assertSame('Mon 2026-03-30 02:00', self::wall($calendar->addMinutes(self::local('2026-03-28 06:00'), 240)));

        // 2026-10-25: 03:00 → 02:00, so Sunday's window is 5 real hours
        $this->assertSame(300, $calendar->minutesBetween(self::local('2026-10-25 00:00'), self::local('2026-10-25 12:00')));
    }

    public function test_invalid_hours_are_refused(): void
    {
        foreach ([[[], '09:00', '17:00', 'UTC'], [[1], '09:00', '09:00', 'UTC'], [[1], '9am', '17:00', 'UTC'], [[1], '09:00', '17:00', 'Mars/Olympus']] as [$days, $from, $to, $tz]) {
            try {
                BusinessCalendar::hours($days, $from, $to, $tz);
                $this->fail("accepted {$from}–{$to} {$tz}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
