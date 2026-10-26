<?php

namespace Tests\Unit;

use App\Support\CalendarFeed\Ics;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** The hand-written RFC 5545 output of the calendar feed (BRAI-142). */
class CalendarFeedIcsTest extends TestCase
{
    public function test_text_values_escape_backslash_semicolon_comma_and_newlines(): void
    {
        $this->assertSame('a\\\\b\\;c\\,d\\ne\\nf\\ng', Ics::text("a\\b;c,d\ne\r\nf\rg"));
        $this->assertSame('no control', Ics::text("no\x07 control"));
    }

    public function test_long_lines_fold_at_75_octets_without_splitting_utf8(): void
    {
        $line = 'SUMMARY:'.str_repeat('ü', 100); // 2 octets each
        $folded = Ics::fold($line);
        $physical = explode("\r\n", $folded);

        $this->assertGreaterThan(1, count($physical));
        foreach ($physical as $i => $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'), "line $i is valid UTF-8");
            if ($i > 0) {
                $this->assertSame(' ', $part[0]);
            }
        }
        // unfolding gives the original line back
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
        $this->assertSame('SHORT:x', Ics::fold('SHORT:x'));
    }

    public function test_all_day_events_use_dates_with_an_exclusive_end(): void
    {
        $lines = Ics::event([
            'uid' => 'item-1@example.test',
            'stamp' => Carbon::parse('2026-10-01 10:00', 'UTC'),
            'all_day' => true,
            'start' => Carbon::parse('2026-11-05'),
            'end' => Carbon::parse('2026-11-07'),
            'summary' => '[BRAI-1] Launch',
        ]);

        $this->assertContains('DTSTART;VALUE=DATE:20261105', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20261108', $lines);
        $this->assertContains('TRANSP:TRANSPARENT', $lines);
    }

    public function test_timed_events_are_written_in_utc(): void
    {
        $lines = Ics::event([
            'uid' => 'item-2@example.test',
            'stamp' => Carbon::parse('2026-10-01 12:00', 'Europe/Berlin'),
            'all_day' => false,
            'start' => Carbon::parse('2026-11-05 09:00', 'Europe/Berlin'),
            'end' => Carbon::parse('2026-11-05 10:30', 'Europe/Berlin'),
            'summary' => 'Call',
            'rrule' => 'FREQ=WEEKLY',
        ]);

        $this->assertContains('DTSTAMP:20261001T100000Z', $lines);
        $this->assertContains('DTSTART:20261105T080000Z', $lines);
        $this->assertContains('DTEND:20261105T093000Z', $lines);
        $this->assertContains('RRULE:FREQ=WEEKLY', $lines);
        $this->assertContains('TRANSP:OPAQUE', $lines);
    }

    public function test_calendar_has_the_header_and_crlf_line_ends(): void
    {
        $body = Ics::calendar('Brainitive — Ada, Lovelace', []);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Brainitive//Calendar feed//EN\r\n", $body);
        $this->assertStringContainsString("X-WR-CALNAME:Brainitive — Ada\\, Lovelace\r\n", $body);
        $this->assertStringContainsString("REFRESH-INTERVAL;VALUE=DURATION:PT15M\r\nX-PUBLISHED-TTL:PT15M\r\n", $body);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
        $this->assertSame(0, preg_match("/(?<!\r)\n/", $body), 'every line ends with CRLF');
    }
}
