<?php

namespace Tests\Unit;

use App\Support\Booking\Availability;
use App\Support\Booking\BookingRules;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Free slots of a booking page's meeting type (BRAI-158): open hours minus busy time, DST-safe. */
class BookingAvailabilityTest extends TestCase
{
    private const TZ = 'Europe/Berlin';

    /** Monday 2027-01-04 … ; "now" is the Friday before, so notice and horizon don't interfere. */
    private const NOW = '2027-01-01 08:00:00';

    private function rules(array $weekly = ['mon' => [['09:00', '12:00']]], array $rules = [], int $duration = 30, array $overrides = []): BookingRules
    {
        return BookingRules::from($duration, ['weekly' => $weekly, 'overrides' => $overrides], $rules);
    }

    /** @return list<string> slot starts as "Y-m-d H:i" in the page's zone */
    private function slots(BookingRules $rules, string $from, string $to, array $busy = [], array $bookedPerDay = [], string $now = self::NOW): array
    {
        $busy = array_map(fn ($b) => [CarbonImmutable::parse($b[0], self::TZ), CarbonImmutable::parse($b[1], self::TZ)], $busy);
        $slots = Availability::slots(
            $rules,
            self::TZ,
            CarbonImmutable::parse($from, self::TZ),
            CarbonImmutable::parse($to, self::TZ),
            $busy,
            $bookedPerDay,
            CarbonImmutable::parse($now, 'UTC'),
        );

        return array_map(fn (CarbonImmutable $s) => $s->setTimezone(self::TZ)->format('Y-m-d H:i'), $slots);
    }

    public function test_weekly_hours_become_slots_in_utc(): void
    {
        $slots = Availability::slots($this->rules(), self::TZ, CarbonImmutable::parse('2027-01-04', self::TZ), CarbonImmutable::parse('2027-01-05', self::TZ), [], [], CarbonImmutable::parse(self::NOW));

        $this->assertCount(6, $slots);
        $this->assertSame('2027-01-04 08:00', $slots[0]->format('Y-m-d H:i')); // 09:00 Berlin = 08:00 UTC in winter
        $this->assertSame('UTC', $slots[0]->getTimezone()->getName());
        $this->assertSame('2027-01-04 10:30', $slots[5]->format('Y-m-d H:i'));
    }

    public function test_only_the_open_weekdays_have_slots(): void
    {
        $this->assertSame([], $this->slots($this->rules(), '2027-01-05', '2027-01-11')); // Tue … Sun
        $this->assertCount(6, $this->slots($this->rules(), '2027-01-05', '2027-01-12')); // … next Monday
    }

    public function test_a_slot_must_fit_before_closing_time(): void
    {
        $slots = $this->slots($this->rules(duration: 45), '2027-01-04', '2027-01-05');

        $this->assertSame(['2027-01-04 09:00', '2027-01-04 09:30', '2027-01-04 10:00', '2027-01-04 10:30', '2027-01-04 11:00'], $slots);
    }

    public function test_busy_time_removes_overlapping_slots_and_touching_is_fine(): void
    {
        $slots = $this->slots($this->rules(), '2027-01-04', '2027-01-05', [['2027-01-04 10:00', '2027-01-04 10:45']]);

        $this->assertSame(['2027-01-04 09:00', '2027-01-04 09:30', '2027-01-04 11:00', '2027-01-04 11:30'], $slots);
    }

    public function test_buffers_keep_distance_from_busy_time(): void
    {
        $slots = $this->slots($this->rules(rules: ['buffer_before' => 15, 'buffer_after' => 15]), '2027-01-04', '2027-01-05', [['2027-01-04 10:00', '2027-01-04 10:30']]);

        // 09:30–10:00 + 15 min after touches 10:00 → out; 10:30 − 15 min before → out
        $this->assertSame(['2027-01-04 09:00', '2027-01-04 11:00', '2027-01-04 11:30'], $slots);
    }

    public function test_minimum_notice_and_horizon(): void
    {
        $rules = $this->rules(rules: ['min_notice_minutes' => 120, 'horizon_days' => 3]);

        // now = Monday 08:00 Berlin: 09:00 and 09:30 are within 2 h
        $this->assertSame(['2027-01-04 10:00', '2027-01-04 10:30', '2027-01-04 11:00', '2027-01-04 11:30'], $this->slots($rules, '2027-01-04', '2027-01-05', now: '2027-01-04 07:00:00'));
        // horizon 3 days from Friday: the Monday is out of reach
        $this->assertSame([], $this->slots($rules, '2027-01-04', '2027-01-05'));
    }

    public function test_daily_max_closes_the_day(): void
    {
        $rules = $this->rules(rules: ['daily_max' => 2]);

        $this->assertCount(6, $this->slots($rules, '2027-01-04', '2027-01-05', bookedPerDay: ['2027-01-04' => 1]));
        $this->assertSame([], $this->slots($rules, '2027-01-04', '2027-01-05', bookedPerDay: ['2027-01-04' => 2]));
    }

    public function test_a_date_override_replaces_the_weekday(): void
    {
        $rules = $this->rules(overrides: ['2027-01-04' => [], '2027-01-06' => [['14:00', '15:00']]]);

        $this->assertSame([], $this->slots($rules, '2027-01-04', '2027-01-05')); // day off
        $this->assertSame(['2027-01-06 14:00', '2027-01-06 14:30'], $this->slots($rules, '2027-01-06', '2027-01-07')); // extra Wednesday
    }

    public function test_step_and_split_ranges(): void
    {
        $rules = $this->rules(['mon' => [['09:00', '10:00'], ['13:00', '14:00']]], ['step_minutes' => 15]);

        $this->assertSame(
            ['2027-01-04 09:00', '2027-01-04 09:15', '2027-01-04 09:30', '2027-01-04 13:00', '2027-01-04 13:15', '2027-01-04 13:30'],
            $this->slots($rules, '2027-01-04', '2027-01-05'),
        );
    }

    public function test_hours_stay_on_the_wall_clock_across_both_dst_switches(): void
    {
        $rules = $this->rules(['sat' => [['09:00', '09:30']], 'sun' => [['09:00', '09:30']]], ['horizon_days' => 400]);

        // Europe/Berlin: summer time starts Sunday 2027-03-28, ends Sunday 2027-10-31
        $spring = Availability::slots($rules, self::TZ, CarbonImmutable::parse('2027-03-27', self::TZ), CarbonImmutable::parse('2027-03-29', self::TZ), [], [], CarbonImmutable::parse(self::NOW));
        $this->assertSame(['2027-03-27 08:00', '2027-03-28 07:00'], array_map(fn ($s) => $s->format('Y-m-d H:i'), $spring));

        $autumn = Availability::slots($rules, self::TZ, CarbonImmutable::parse('2027-10-30', self::TZ), CarbonImmutable::parse('2027-11-01', self::TZ), [], [], CarbonImmutable::parse(self::NOW));
        $this->assertSame(['2027-10-30 07:00', '2027-10-31 08:00'], array_map(fn ($s) => $s->format('Y-m-d H:i'), $autumn));
    }

    public function test_hours_until_midnight(): void
    {
        $rules = $this->rules(['mon' => [['23:00', '24:00']]]);

        $this->assertSame(['2027-01-04 23:00', '2027-01-04 23:30'], $this->slots($rules, '2027-01-04', '2027-01-06'));
    }

    public function test_bad_ranges_are_dropped_and_overlaps_merged(): void
    {
        $rules = $this->rules(['mon' => [['12:00', '09:00'], ['25:00', '26:00'], ['nonsense'], ['09:00', '10:00'], ['09:30', '10:30']]]);

        $this->assertSame([[540, 630]], $rules->weekly[1]);
        $this->assertSame([], $rules->weekly[2]);
    }

    public function test_the_range_limits_what_is_returned(): void
    {
        // a visitor asking for Monday afternoon (their zone doesn't matter: the range is absolute)
        $this->assertSame(['2027-01-04 11:00', '2027-01-04 11:30'], $this->slots($this->rules(), '2027-01-04 11:00', '2027-01-04 13:00'));
    }
}
