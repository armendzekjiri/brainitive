<?php

namespace Tests\Unit;

use App\Support\RecurringIssues\RecurrenceSchedule;
use PHPUnit\Framework\TestCase;

/**
 * BRAI-123: the date maths of recurring issues — intervals, weekdays, month ends (31st → last day),
 * nth / last weekday, leap years, end dates and the catch-up lookup.
 */
class RecurrenceScheduleTest extends TestCase
{
    /** @param  array<string, mixed>  $rule */
    private function dates(array $rule, int $count, ?string $from = null): array
    {
        $schedule = RecurrenceSchedule::fromArray($rule);

        return array_map(
            fn ($date) => $date->toDateString(),
            $schedule->upcoming(RecurrenceSchedule::date($from ?? $rule['start_date']), $count),
        );
    }

    public function test_daily_every_n_days(): void
    {
        $this->assertSame(
            ['2026-10-27', '2026-10-30', '2026-11-02'],
            $this->dates(['frequency' => 'daily', 'interval' => 3, 'start_date' => '2026-10-24'], 3, '2026-10-25'),
        );
    }

    public function test_weekly_on_chosen_weekdays_every_other_week(): void
    {
        // start Sat 2026-10-24: the rest of that week has no Mon/Fri, the next active week is the one of Nov 2
        $this->assertSame(
            ['2026-11-02', '2026-11-06', '2026-11-16', '2026-11-20'],
            $this->dates(['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [5, 1], 'start_date' => '2026-10-24'], 4),
        );
    }

    public function test_weekly_defaults_to_the_start_dates_weekday(): void
    {
        $this->assertSame(
            ['2026-10-24', '2026-10-31', '2026-11-07'],
            $this->dates(['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [], 'start_date' => '2026-10-24'], 3),
        );
    }

    public function test_the_31st_is_the_last_day_of_shorter_months_without_drifting(): void
    {
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
            $this->dates(['frequency' => 'monthly', 'monthly_mode' => 'day', 'month_day' => 31, 'start_date' => '2026-01-31'], 6),
        );
    }

    public function test_leap_years_in_february(): void
    {
        $this->assertSame(
            ['2028-01-31', '2028-02-29', '2028-03-31'],
            $this->dates(['frequency' => 'monthly', 'month_day' => 31, 'start_date' => '2028-01-31'], 3),
        );
        // every two months on the 30th: Feb 2028 has 29 days
        $this->assertSame(
            ['2027-12-30', '2028-02-29', '2028-04-30'],
            $this->dates(['frequency' => 'monthly', 'interval' => 2, 'month_day' => 30, 'start_date' => '2027-12-30'], 3),
        );
        // yearly on Feb 29: Feb 28 in common years, Feb 29 again in the next leap year
        $this->assertSame(
            ['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'],
            $this->dates(['frequency' => 'yearly', 'start_date' => '2028-02-29'], 5),
        );
    }

    public function test_monthly_on_the_nth_or_last_weekday(): void
    {
        $base = ['frequency' => 'monthly', 'monthly_mode' => 'weekday', 'start_date' => '2026-11-01'];

        $this->assertSame(['2026-11-02', '2026-12-07', '2027-01-04'], $this->dates($base + ['month_week' => 1, 'month_weekday' => 1], 3));
        $this->assertSame(['2026-11-27', '2026-12-25', '2027-01-29'], $this->dates($base + ['month_week' => -1, 'month_weekday' => 5], 3));
        $this->assertSame(['2026-11-16', '2026-12-21', '2027-01-18'], $this->dates($base + ['month_week' => 3, 'month_weekday' => 1], 3));
        // 0 = any weekday (Mon–Fri): the first / last business day
        $this->assertSame(['2026-11-02', '2026-12-01', '2027-01-01'], $this->dates($base + ['month_week' => 1, 'month_weekday' => 0], 3));
        $this->assertSame(['2026-11-30', '2026-12-31', '2027-01-29'], $this->dates($base + ['month_week' => -1, 'month_weekday' => 0], 3));
    }

    public function test_the_end_date_stops_the_series(): void
    {
        $this->assertSame(
            ['2026-10-24', '2026-10-31', '2026-11-07'],
            $this->dates(['frequency' => 'weekly', 'start_date' => '2026-10-24', 'end_date' => '2026-11-07'], 5),
        );
    }

    public function test_latest_between_finds_the_newest_missed_date_only(): void
    {
        $schedule = RecurrenceSchedule::fromArray(['frequency' => 'weekly', 'start_date' => '2026-10-03']); // Saturdays
        $date = fn (string $d) => RecurrenceSchedule::date($d);

        $this->assertSame('2026-10-24', $schedule->latestBetween($date('2026-10-04'), $date('2026-10-24'))?->toDateString());
        $this->assertSame('2026-10-17', $schedule->latestBetween($date('2026-10-04'), $date('2026-10-23'))?->toDateString());
        $this->assertNull($schedule->latestBetween($date('2026-10-18'), $date('2026-10-23')));
        $this->assertNull($schedule->latestBetween($date('2026-01-01'), $date('2026-10-02')), 'nothing before the start date');

        $monthly = RecurrenceSchedule::fromArray(['frequency' => 'monthly', 'month_day' => 31, 'start_date' => '2026-01-31']);
        $this->assertSame('2026-03-31', $monthly->latestBetween($date('2026-01-01'), $date('2026-04-15'))?->toDateString());

        $ended = RecurrenceSchedule::fromArray(['frequency' => 'daily', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        $this->assertSame('2026-10-10', $ended->latestBetween($date('2026-10-05'), $date('2026-12-01'))?->toDateString());
    }

    public function test_labels(): void
    {
        $this->assertSame('Repeats weekly', RecurrenceSchedule::badge('weekly', 1));
        $this->assertSame('Repeats every 2 weeks', RecurrenceSchedule::badge('weekly', 2));
        $this->assertSame('Every 2 weeks on Mon, Fri', RecurrenceSchedule::fromArray(['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [1, 5], 'start_date' => '2026-10-24'])->summary());
        $this->assertSame('Every month on the last day', RecurrenceSchedule::fromArray(['frequency' => 'monthly', 'month_day' => 31, 'start_date' => '2026-10-24'])->summary());
        $this->assertSame('Every month on the last weekday', RecurrenceSchedule::fromArray(['frequency' => 'monthly', 'monthly_mode' => 'weekday', 'month_week' => -1, 'month_weekday' => 0, 'start_date' => '2026-10-24'])->summary());
        $this->assertSame('Every year on Feb 29', RecurrenceSchedule::fromArray(['frequency' => 'yearly', 'start_date' => '2028-02-29'])->summary());
    }
}
