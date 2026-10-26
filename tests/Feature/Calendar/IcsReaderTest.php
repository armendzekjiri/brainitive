<?php

namespace Tests\Feature\Calendar;

use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\Ics\IcsComponent;
use App\Support\Calendar\Ics\IcsEvents;
use App\Support\Calendar\Ics\IcsInstance;
use App\Support\Calendar\Ics\IcsProperty;
use App\Support\Calendar\Ics\IcsReader;
use App\Support\Calendar\Ics\IcsTime;
use Tests\TestCase;

/**
 * The RFC 5545 reader behind ICS links and CalDAV (BRAI-141): real-world feeds (Google,
 * Outlook / Exchange, Apple, holiday calendars), recurrence, time zones and the writer.
 */
class IcsReaderTest extends TestCase
{
    private function fixture(string $name): IcsComponent
    {
        $calendar = IcsReader::parse((string) file_get_contents(base_path("tests/Fixtures/calendar/{$name}.ics")));
        $this->assertNotNull($calendar);

        return $calendar;
    }

    /** @return list<string> "title|start_date start_time|end_date end_time" per occurrence */
    private function summary(IcsComponent $calendar, string $from, string $to, string $zone = 'Europe/Berlin'): array
    {
        return array_map(function (IcsInstance $i) use ($zone) {
            $e = $i->normalize('x', $zone);

            return trim("{$e['title']}|{$e['start_date']} {$e['start_time']}").'|'.trim("{$e['end_date']} {$e['end_time']}");
        }, IcsEvents::between($calendar, $from, $to, $zone));
    }

    private function inline(string $body): IcsComponent
    {
        return IcsReader::parse("BEGIN:VCALENDAR\nVERSION:2.0\n{$body}\nEND:VCALENDAR\n");
    }

    public function test_google_feed_with_tzid_weekly_series_exdate_override_and_folded_text(): void
    {
        $calendar = $this->fixture('google');
        $this->assertSame('Team Calendar', $calendar->text('X-WR-CALNAME'));

        $this->assertSame([
            'Standup|2026-11-02 09:00|2026-11-02 09:15',   // DTSTART was in summer time: still 09:00 local
            'Sprint planning|2026-11-04 10:00|2026-11-04 11:30',
            'Team offsite|2026-11-11|2026-11-13',           // DTEND;VALUE=DATE is exclusive
            'Standup (moved)|2026-11-17 14:00|2026-11-17 14:15', // RECURRENCE-ID override of the 16th
            'Call with NYC|2026-11-20 16:00|2026-11-20 17:00', // UTC → Berlin
            'Standup|2026-11-23 09:00|2026-11-23 09:15',
            'Standup|2026-11-30 09:00|2026-11-30 09:15',
        ], $this->summary($calendar, '2026-11-01', '2026-11-30')); // the 9th is an EXDATE, the cancelled one is gone

        $planning = IcsEvents::between($calendar, '2026-11-04', '2026-11-04', 'Europe/Berlin')[0]->normalize('p', null);
        $this->assertSame("Agenda:\n1. Review, then plan; bring notes. This line is long enough to be folded by Google at seventy-five octets – ünïcödé too.", $planning['description']);
        $this->assertSame('Room 4, Berlin', $planning['location']);
        $this->assertSame('Europe/Berlin', $planning['timezone']); // no $timezone: the event's own zone

        $call = IcsEvents::between($calendar, '2026-11-20', '2026-11-20', 'UTC')[0];
        $this->assertSame('https://meet.example.com/abc', $call->normalize('c', 'UTC')['external_url']);
        $this->assertSame('15:00', $call->normalize('c', 'UTC')['start_time']);
    }

    public function test_outlook_feed_with_windows_zone_names_custom_vtimezone_and_monthly_ordinal(): void
    {
        $calendar = $this->fixture('outlook');

        $this->assertSame([
            'Call with Pune|2026-11-05 10:00|2026-11-05 11:00', // "Custom Zone" = its VTIMEZONE's +05:30
            'Monthly review|2026-11-10 15:00|2026-11-10 16:00', // 2nd Tuesday, "W. Europe Standard Time"
            'Public holiday|2026-11-26|2026-11-26',
        ], $this->summary($calendar, '2026-11-01', '2026-11-30'));

        $this->assertSame(['Monthly review|2026-12-08 15:00|2026-12-08 16:00'], $this->summary($calendar, '2026-12-01', '2026-12-31'));
        $this->assertSame([], $this->summary($calendar, '2027-01-01', '2027-01-31')); // UNTIL 2027-01-01
    }

    public function test_apple_feed_with_duration_floating_time_yearly_birthday_and_alarm(): void
    {
        $calendar = $this->fixture('apple');

        $this->assertSame([
            'Dinner|2026-11-06 01:00|2026-11-06 02:30',      // 19:00 New York + PT1H30M, shown in Berlin
            "Grandma's birthday|2026-11-15|2026-11-15",
            'Floating breakfast|2026-11-18 08:00|2026-11-18 09:00', // floating: the viewer's zone
        ], $this->summary($calendar, '2026-11-01', '2026-11-30'));

        $this->assertSame([
            'Dinner|2026-11-05 19:00|2026-11-05 20:30',
            "Grandma's birthday|2026-11-15|2026-11-15",
            'Floating breakfast|2026-11-18 08:00|2026-11-18 09:00',
        ], $this->summary($calendar, '2026-11-01', '2026-11-30', 'America/New_York'));
    }

    public function test_holiday_feed_with_yearly_rules_count_exdate_and_cancelled_override(): void
    {
        $calendar = $this->fixture('holidays');

        $this->assertSame([
            'School day|2026-11-02|2026-11-02',
            'School day|2026-11-03|2026-11-03', // the 4th is an EXDATE, the 5th a cancelled override
            'School day|2026-11-06|2026-11-06',
            'Thanksgiving|2026-11-26|2026-11-26', // BYMONTH=11;BYDAY=4TH
            'Payday|2026-11-27|2026-11-27',       // BYDAY=-1FR
        ], $this->summary($calendar, '2026-11-01', '2026-11-30'));

        $this->assertSame(['Memorial Day|2027-05-31|2027-05-31'], $this->summary($calendar, '2027-05-01', '2027-05-31'));
        $this->assertSame(["New Year's Day|2027-01-01|2027-01-01", 'Payday|2027-01-29|2027-01-29'], $this->summary($calendar, '2027-01-01', '2027-01-31'));
        $this->assertSame(['Payday|2027-02-26|2027-02-26'], $this->summary($calendar, '2027-02-01', '2027-02-28'));
        $this->assertSame([], $this->summary($calendar, '2027-03-01', '2027-03-31')); // COUNT=6 used up
    }

    public function test_recurrence_rules(): void
    {
        $dates = fn (string $rule, string $from, string $to, string $start = '20100104', string $extra = '') => array_map(
            fn (string $line) => explode('|', $line)[1],
            $this->summary($this->inline("BEGIN:VEVENT\nUID:r\nSUMMARY:R\nDTSTART;VALUE=DATE:{$start}\nRRULE:{$rule}\n{$extra}\nEND:VEVENT"), $from, $to),
        );

        // every other day since 2010: the window is reached by skipping ahead, the parity stays right
        $this->assertSame(['2026-11-02', '2026-11-04', '2026-11-06'], $dates('FREQ=DAILY;INTERVAL=2', '2026-11-01', '2026-11-07')); // 6146 days after DTSTART
        // every second week, Mon / Wed / Fri (2010-01-04 is a Monday)
        $this->assertSame(['2026-11-02', '2026-11-04', '2026-11-06'], $dates('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR', '2026-11-01', '2026-11-14'));
        // last day of the month; the 31st skips short months
        $this->assertSame(['2027-02-28'], $dates('FREQ=MONTHLY;BYMONTHDAY=-1', '2027-02-01', '2027-02-28'));
        $this->assertSame([], $dates('FREQ=MONTHLY;BYMONTHDAY=31', '2026-11-01', '2026-11-30', '20100131'));
        $this->assertSame(['2026-12-31'], $dates('FREQ=MONTHLY', '2026-12-01', '2026-12-31', '20100131'));
        // last weekday of the month
        $this->assertSame(['2026-10-30'], $dates('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1', '2026-10-01', '2026-10-31'));
        // 29 February: leap years only
        $this->assertSame([], $dates('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29', '2027-01-01', '2027-12-31', '20120229'));
        $this->assertSame(['2028-02-29'], $dates('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29', '2028-01-01', '2028-12-31', '20120229'));
        // UNTIL (a date) is inclusive; RDATE adds, a multi-value EXDATE removes
        $this->assertSame(['2026-11-02', '2026-11-04', '2026-11-20'], $dates(
            'FREQ=DAILY;UNTIL=20261105',
            '2026-11-01', '2026-11-30', '20261102',
            "RDATE;VALUE=DATE:20261120\nEXDATE;VALUE=DATE:20261103,20261105",
        ));
        // sub-daily rules aren't expanded: the event shows once
        $this->assertSame(['2026-11-02'], $dates('FREQ=HOURLY', '2026-11-01', '2026-11-30', '20261102'));
    }

    public function test_expansion_is_capped(): void
    {
        $calendar = $this->inline("BEGIN:VEVENT\nUID:many\nDTSTART:20260101T090000Z\nRRULE:FREQ=DAILY\nEND:VEVENT");
        $this->assertCount(IcsEvents::MAX_INSTANCES, IcsEvents::between($calendar, '2026-01-01', '2036-12-31', 'UTC'));
        $this->assertCount(10, IcsEvents::between($calendar, '2026-01-01', '2036-12-31', 'UTC', max: 10));

        // a rule that never matches ends after MAX_PERIODS periods (only DTSTART)
        $never = $this->inline("BEGIN:VEVENT\nUID:never\nDTSTART;VALUE=DATE:20260101\nRRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30\nEND:VEVENT");
        $this->assertCount(1, IcsEvents::between($never, '2026-01-01', '2999-12-31', 'UTC'));
    }

    public function test_time_zone_names(): void
    {
        $time = new IcsTime(null, 'UTC');
        $this->assertSame('America/New_York', $time->zone('Eastern Standard Time')->getName());
        $this->assertSame('Europe/Berlin', $time->zone('/mozilla.org/20050126_1/Europe/Berlin')->getName());
        $this->assertSame('Asia/Kolkata', $time->zone('"Asia/Kolkata"')->getName());
        $this->assertSame('UTC', $time->zone('Nowhere Standard Time')->getName()); // unknown → floating zone
        $this->assertSame([1, 7200], IcsTime::duration('P1DT2H'));
        $this->assertSame([-14, 0], IcsTime::duration('-P2W'));
        $this->assertNull(IcsTime::duration('soon'));
    }

    public function test_content_lines_parameters_escaping_and_folding_round_trip(): void
    {
        $line = IcsReader::parseLine('ATTENDEE;CN="Doe, Jane: PM";ROLE=REQ-PARTICIPANT;X-LIST=a,"b;c":mailto:jane@example.com');
        $this->assertSame('ATTENDEE', $line->name);
        $this->assertSame('Doe, Jane: PM', $line->param('cn'));
        $this->assertSame('a,b;c', $line->param('X-LIST'));
        $this->assertSame('mailto:jane@example.com', $line->value);
        $this->assertNull(IcsReader::parseLine('not a content line'));

        $long = str_repeat('Grüße, Ärger; Ölpreis \\ ', 12)."\nzweite Zeile";
        $event = new IcsComponent('VEVENT');
        $event->add(IcsProperty::text('DESCRIPTION', $long))
            ->add(IcsReader::parseLine('X-CUSTOM;X-P="a:b":keep \\, me'));
        $calendar = new IcsComponent('VCALENDAR');
        $calendar->children[] = $event;
        $text = $calendar->toString();

        foreach (explode("\r\n", $text) as $physical) {
            $this->assertLessThanOrEqual(75, strlen($physical));
            $this->assertTrue(mb_check_encoding($physical, 'UTF-8'), 'folding never splits a character');
        }
        $parsed = IcsReader::parse($text)->children('VEVENT')[0];
        $this->assertSame($long, $parsed->text('DESCRIPTION'));
        $this->assertSame('X-CUSTOM;X-P="a:b":keep \\, me', rtrim($parsed->first('X-CUSTOM')->toLine()));
    }

    public function test_messy_input_is_tolerated(): void
    {
        $text = "\xEF\xBB\xBFjunk before\r\nBEGIN:VCALENDAR\nbegin:vevent\nuid:lower\nsummary:Lower case\ndtstart;value=date:20261102\nBROKEN LINE\nEND:VEVENT\nBEGIN:VEVENT\nUID:unclosed\nSUMMARY:Unclosed\nDTSTART:20261103T100000Z\nEND:VCALENDAR";
        $calendar = IcsReader::parse($text);
        $this->assertSame(['Lower case|2026-11-02|2026-11-02', 'Unclosed|2026-11-03 10:00|2026-11-03 10:00'], $this->summary($calendar, '2026-11-01', '2026-11-30', 'UTC'));
        $this->assertNull(IcsReader::parse('<html>not a calendar</html>'));
    }

    public function test_one_off_events_sharing_a_uid_all_show_and_are_found_again(): void
    {
        $calendar = $this->inline(
            "BEGIN:VEVENT\nUID:lesson\nSUMMARY:Maths\nDTSTART:20261102T080000Z\nDTEND:20261102T090000Z\nEND:VEVENT\n"
            ."BEGIN:VEVENT\nUID:lesson\nSUMMARY:Physics\nDTSTART:20261103T080000Z\nDTEND:20261103T090000Z\nEND:VEVENT\n"
            ."BEGIN:VEVENT\nSUMMARY:No uid\nDTSTART;VALUE=DATE:20261104\nEND:VEVENT"
        );
        $instances = IcsEvents::between($calendar, '2026-11-01', '2026-11-30', 'UTC');
        $this->assertSame(['Maths', 'Physics', 'No uid'], array_map(fn (IcsInstance $i) => $i->event->text('SUMMARY'), $instances));
        $this->assertSame(['20261102T080000Z', '20261103T080000Z', null], array_map(fn (IcsInstance $i) => $i->recurrenceKey, $instances));

        $lessons = $calendar->children('VEVENT');
        $this->assertSame('Physics', IcsEvents::occurrence($calendar, array_slice($lessons, 0, 2), '20261103T080000Z', 'UTC')->event->text('SUMMARY'));
        $this->assertSame($instances[2]->uid, IcsEvents::occurrence($calendar, [$lessons[2]], null, 'UTC')->uid);
        $this->assertStringStartsWith('nouid-', $instances[2]->uid);
    }

    public function test_an_all_day_start_with_a_date_time_end_keeps_its_days(): void
    {
        $calendar = $this->inline("BEGIN:VEVENT\nUID:x\nSUMMARY:Camp\nDTSTART;VALUE=DATE:20261102\nDTEND;TZID=Europe/Berlin:20261104T000000\nEND:VEVENT");
        $this->assertSame(['Camp|2026-11-02|2026-11-03'], $this->summary($calendar, '2026-11-01', '2026-11-30'));
    }

    public function test_huge_or_hostile_calendars_are_bounded(): void
    {
        // many long COUNT rules from 1970: one shared budget of periods, not 5000 each
        $events = '';
        for ($i = 0; $i < 400; $i++) {
            $events .= "BEGIN:VEVENT\nUID:c{$i}\nDTSTART:19700101T000000Z\nRRULE:FREQ=DAILY;COUNT=999999;BYDAY=MO,MO,MO,MO,MO,MO,MO,MO\nEND:VEVENT\n";
        }
        $started = microtime(true);
        IcsEvents::between($this->inline(rtrim($events)), '2026-11-01', '2026-11-30', 'UTC');
        $this->assertLessThan(5, microtime(true) - $started);

        $this->expectExceptionObject(new CalendarSyncFailed('This calendar is too big to load.'));
        IcsReader::parse("BEGIN:VCALENDAR\n".str_repeat("BEGIN:VEVENT\nEND:VEVENT\n", IcsReader::MAX_COMPONENTS + 1).'END:VCALENDAR');
    }

    public function test_one_occurrence_is_found_by_its_key(): void
    {
        $calendar = $this->fixture('google');
        $standups = array_values(array_filter($calendar->children('VEVENT'), fn (IcsComponent $e) => $e->text('UID') === 'standup@google.com'));

        $this->assertSame('2026-11-23', IcsEvents::occurrence($calendar, $standups, '20261123T080000Z', 'Europe/Berlin')->normalize('x', null)['start_date']);
        $this->assertSame('Standup (moved)', IcsEvents::occurrence($calendar, $standups, '20261116T080000Z', 'Europe/Berlin')->normalize('x', null)['title']);
        $this->assertNull(IcsEvents::occurrence($calendar, $standups, '20261124T080000Z', 'Europe/Berlin')); // not an occurrence
        $this->assertSame('2026-10-05', IcsEvents::occurrence($calendar, $standups, null, 'Europe/Berlin')->normalize('x', null)['start_date']);
    }
}
