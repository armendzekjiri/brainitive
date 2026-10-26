<?php

namespace App\Support\Calendar;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Builds the provider-neutral event shape every CalendarProvider returns (see the
 * CalendarProvider docblock for the meaning of each key).
 */
final class NormalizedEvent
{
    public const KEYS = [
        'id', 'title', 'description', 'location', 'all_day',
        'start_date', 'start_time', 'end_date', 'end_time', 'timezone', 'external_url',
    ];

    /**
     * A timed event from two instants. With $timezone the wall-clock times are converted to
     * it; without, they stay in the instants' own zone (reported as `timezone`).
     *
     * @param  array{description?: string|null, location?: string|null, external_url?: string|null}  $extra
     */
    public static function timed(string $id, ?string $title, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null, array $extra = []): array
    {
        if ($timezone !== null) {
            $start = Carbon::instance($start)->setTimezone($timezone);
            $end = Carbon::instance($end)->setTimezone($timezone);
        }

        return self::make([
            'id' => $id,
            'title' => $title,
            'all_day' => false,
            'start_date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_date' => $end->format('Y-m-d'),
            'end_time' => $end->format('H:i'),
            'timezone' => $timezone ?? self::zoneName($start),
        ] + $extra);
    }

    /**
     * An all-day event. $endDateInclusive is the last day it covers (for iCal / Google
     * exclusive ends, pass the end date minus one day). An end before the start is clamped.
     *
     * @param  array{description?: string|null, location?: string|null, external_url?: string|null}  $extra
     */
    public static function allDay(string $id, ?string $title, string $startDate, ?string $endDateInclusive = null, array $extra = []): array
    {
        $end = $endDateInclusive !== null && $endDateInclusive >= $startDate ? $endDateInclusive : $startDate;

        return self::make([
            'id' => $id,
            'title' => $title,
            'all_day' => true,
            'start_date' => $startDate,
            'start_time' => null,
            'end_date' => $end,
            'end_time' => null,
            'timezone' => null,
        ] + $extra);
    }

    /**
     * Fill every key (missing → null), coerce types, drop unknown keys.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function make(array $event): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $event[$key] ?? null;
        }
        $out['id'] = (string) $out['id'];
        $out['title'] = ($out['title'] ?? '') !== '' ? (string) $out['title'] : '(untitled)';
        $out['all_day'] = (bool) $out['all_day'];
        $out['end_date'] ??= $out['start_date'];
        // Feeds (ICS, CalDAV) are third-party data: only http(s) links may become an href.
        $url = is_string($out['external_url']) ? $out['external_url'] : '';
        $out['external_url'] = in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;

        return $out;
    }

    /** The instant the event starts (timed events), or null for all-day events. */
    public static function startsAt(array $event): ?Carbon
    {
        if (($event['all_day'] ?? false) || empty($event['start_date']) || empty($event['start_time'])) {
            return null;
        }

        return Carbon::parse($event['start_date'].' '.$event['start_time'], $event['timezone'] ?: config('app.timezone'));
    }

    /** RFC3339 start / end for timed events, Y-m-d (end inclusive) for all-day ones. */
    public static function boundary(array $event, string $which): string
    {
        $date = $event[$which.'_date'] ?? null;
        $time = $event[$which.'_time'] ?? null;
        if (! $date) {
            return '';
        }
        if (($event['all_day'] ?? false) || ! $time) {
            return $date;
        }

        return Carbon::parse("{$date} {$time}", $event['timezone'] ?: config('app.timezone'))->toRfc3339String();
    }

    private static function zoneName(CarbonInterface $at): string
    {
        $name = $at->getTimezone()->getName();

        return $name === 'Z' ? 'UTC' : $name;
    }
}
