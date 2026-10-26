<?php

namespace App\Support\CalendarFeed;

use Carbon\CarbonInterface;

/**
 * A minimal RFC 5545 writer for the calendar feed (BRAI-142) — no dependency. CRLF line ends,
 * TEXT escaping (\\ ; , and newlines), and lines folded at 75 octets without splitting a UTF-8
 * character. All-day events use VALUE=DATE with an exclusive DTEND; timed events are UTC (Z).
 */
class Ics
{
    public const LINE_OCTETS = 75;

    /**
     * @param  array<int, array<string, mixed>>  $events  see event()
     */
    public static function calendar(string $name, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Brainitive//Calendar feed//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
        ];
        foreach ($events as $event) {
            array_push($lines, ...self::event($event));
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * One VEVENT. Keys: uid, stamp (Carbon), summary, all_day (bool), start / end (Carbon; for
     * all-day the first and the LAST day — DTEND becomes the day after), optional url, description,
     * rrule (e.g. FREQ=WEEKLY), transparent (bool).
     *
     * @param  array<string, mixed>  $e
     * @return array<int, string>
     */
    public static function event(array $e): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.self::clean($e['uid']),
            'DTSTAMP:'.self::utc($e['stamp']),
        ];
        if ($e['all_day']) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$e['start']->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$e['end']->copy()->addDay()->format('Ymd'); // exclusive
        } else {
            $lines[] = 'DTSTART:'.self::utc($e['start']);
            $lines[] = 'DTEND:'.self::utc($e['end']);
        }
        if (! empty($e['rrule'])) {
            $lines[] = 'RRULE:'.self::clean($e['rrule']);
        }
        $lines[] = 'SUMMARY:'.self::text($e['summary']);
        if (! empty($e['description'])) {
            $lines[] = 'DESCRIPTION:'.self::text($e['description']);
        }
        if (! empty($e['url'])) {
            $lines[] = 'URL:'.self::clean($e['url']);
        }
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:'.(($e['transparent'] ?? $e['all_day']) ? 'TRANSPARENT' : 'OPAQUE');
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** UTC date-time in the basic format, e.g. 20261105T083000Z. */
    public static function utc(CarbonInterface $moment): string
    {
        return $moment->copy()->utc()->format('Ymd\THis\Z');
    }

    /** Escape a TEXT value (RFC 5545 3.3.11). */
    public static function text(string $value): string
    {
        $value = self::stripControl($value);
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([';', ','], ['\\;', '\\,'], $value);

        return (string) preg_replace('/\r\n|\r|\n/', '\\n', $value);
    }

    /**
     * Fold a content line into chunks of at most 75 octets; continuation lines start with one space
     * (which counts toward their 75). Never splits a multi-byte UTF-8 character.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::LINE_OCTETS) {
            return $line;
        }
        $chunks = [];
        $current = '';
        $limit = self::LINE_OCTETS;
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $chunks[] = $current;
                $current = '';
                $limit = self::LINE_OCTETS - 1; // the leading space
            }
            $current .= $char;
        }
        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }

    /** A non-TEXT value (UID, URL, RRULE): no line breaks or control characters. */
    private static function clean(string $value): string
    {
        return (string) preg_replace('/[\x00-\x20\x7F]+/', '', $value);
    }

    private static function stripControl(string $value): string
    {
        // keep \n and \r (escaped by text()), drop other control characters and invalid UTF-8
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }
}
