<?php

namespace App\Support\Calendar\Ics;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * DATE / DATE-TIME / DURATION values and TZID resolution for one calendar (RFC 5545 §3.3).
 *  - DATE (all-day) values become midnight UTC: wall-clock dates without any zone maths.
 *  - DATE-TIME with Z is UTC; with TZID it is in that zone; without either it is "floating"
 *    and taken in the calendar's X-WR-TIMEZONE, else the viewer's / app zone.
 *  - TZID: IANA names, vendor prefixes (/mozilla.org/…/Europe/Berlin), Windows names (Outlook,
 *    Exchange), the VTIMEZONE's X-LIC-LOCATION, else its standard offset as a fixed zone.
 */
final class IcsTime
{
    /** Windows zone names (Outlook / Exchange feeds) → IANA. The common ones; others fall back. */
    private const WINDOWS_ZONES = [
        'UTC' => 'UTC', 'Coordinated Universal Time' => 'UTC', 'GMT Standard Time' => 'Europe/London',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik', 'W. Europe Standard Time' => 'Europe/Berlin',
        'Central Europe Standard Time' => 'Europe/Budapest', 'Romance Standard Time' => 'Europe/Paris',
        'Central European Standard Time' => 'Europe/Warsaw', 'E. Europe Standard Time' => 'Europe/Chisinau',
        'FLE Standard Time' => 'Europe/Kiev', 'GTB Standard Time' => 'Europe/Bucharest',
        'Russian Standard Time' => 'Europe/Moscow', 'Turkey Standard Time' => 'Europe/Istanbul',
        'Israel Standard Time' => 'Asia/Jerusalem', 'Egypt Standard Time' => 'Africa/Cairo',
        'South Africa Standard Time' => 'Africa/Johannesburg', 'W. Central Africa Standard Time' => 'Africa/Lagos',
        'E. Africa Standard Time' => 'Africa/Nairobi', 'Morocco Standard Time' => 'Africa/Casablanca',
        'Arabian Standard Time' => 'Asia/Dubai', 'Arab Standard Time' => 'Asia/Riyadh', 'Iran Standard Time' => 'Asia/Tehran',
        'Pakistan Standard Time' => 'Asia/Karachi', 'India Standard Time' => 'Asia/Kolkata', 'Nepal Standard Time' => 'Asia/Kathmandu',
        'Bangladesh Standard Time' => 'Asia/Dhaka', 'SE Asia Standard Time' => 'Asia/Bangkok', 'China Standard Time' => 'Asia/Shanghai',
        'Singapore Standard Time' => 'Asia/Singapore', 'Taipei Standard Time' => 'Asia/Taipei', 'Tokyo Standard Time' => 'Asia/Tokyo',
        'Korea Standard Time' => 'Asia/Seoul', 'W. Australia Standard Time' => 'Australia/Perth',
        'Cen. Australia Standard Time' => 'Australia/Adelaide', 'E. Australia Standard Time' => 'Australia/Brisbane',
        'AUS Eastern Standard Time' => 'Australia/Sydney', 'New Zealand Standard Time' => 'Pacific/Auckland',
        'Atlantic Standard Time' => 'America/Halifax', 'Newfoundland Standard Time' => 'America/St_Johns',
        'Eastern Standard Time' => 'America/New_York', 'Central Standard Time' => 'America/Chicago',
        'Canada Central Standard Time' => 'America/Regina', 'Central Standard Time (Mexico)' => 'America/Mexico_City',
        'Mountain Standard Time' => 'America/Denver', 'US Mountain Standard Time' => 'America/Phoenix',
        'Pacific Standard Time' => 'America/Los_Angeles', 'Alaskan Standard Time' => 'America/Anchorage',
        'Hawaiian Standard Time' => 'Pacific/Honolulu', 'SA Pacific Standard Time' => 'America/Bogota',
        'Venezuela Standard Time' => 'America/Caracas', 'Pacific SA Standard Time' => 'America/Santiago',
        'Argentina Standard Time' => 'America/Argentina/Buenos_Aires', 'E. South America Standard Time' => 'America/Sao_Paulo',
        'Azores Standard Time' => 'Atlantic/Azores',
    ];

    /** @var array<string, DateTimeZone> */
    private array $zones = [];

    private DateTimeZone $floating;

    /** @var array<string, IcsComponent> VTIMEZONE by TZID */
    private array $vtimezones = [];

    public function __construct(?IcsComponent $calendar, string $fallbackZone)
    {
        foreach ($calendar?->children('VTIMEZONE') ?? [] as $vtz) {
            $id = trim((string) $vtz->first('TZID')?->value, '"');
            if ($id !== '') {
                $this->vtimezones[$id] = $vtz;
            }
        }
        $this->floating = self::iana(trim((string) $calendar?->first('X-WR-TIMEZONE')?->value))
            ?? self::iana($fallbackZone)
            ?? new DateTimeZone('UTC');
    }

    public function floatingZone(): DateTimeZone
    {
        return $this->floating;
    }

    /**
     * A DTSTART / DTEND / RECURRENCE-ID value: [instant, isDate, isFloating]. DATE values are
     * midnight UTC; floating = a DATE-TIME without Z or TZID (its occurrence key is wall-clock).
     *
     * @return array{0: CarbonImmutable, 1: bool, 2: bool}|null
     */
    public function parse(?IcsProperty $property): ?array
    {
        if ($property === null) {
            return null;
        }
        $value = trim(explode(',', $property->value)[0]);

        return $this->parseValue($value, $property->param('TZID'), strtoupper((string) $property->param('VALUE')) === 'DATE');
    }

    /**
     * Every value of a list property (EXDATE, RDATE may hold several, comma-separated).
     *
     * @return list<array{0: CarbonImmutable, 1: bool, 2: bool}>
     */
    public function parseList(IcsProperty $property): array
    {
        $out = [];
        foreach (explode(',', $property->value) as $value) {
            $value = trim(explode('/', $value)[0]); // RDATE;VALUE=PERIOD: start/end → start
            $parsed = $value !== '' ? $this->parseValue($value, $property->param('TZID'), strtoupper((string) $property->param('VALUE')) === 'DATE') : null;
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /** @return array{0: CarbonImmutable, 1: bool, 2: bool}|null */
    public function parseValue(string $value, ?string $tzid, bool $isDate = false): ?array
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? [CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC'), true, false]
                : null;
        }
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z)?$/i', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $floating = empty($m[7]) && ($tzid === null || $tzid === '');
        $zone = ! empty($m[7]) ? new DateTimeZone('UTC') : ($floating ? $this->floating : $this->zone($tzid));
        $at = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), $zone);

        // A DATE-TIME given as VALUE=DATE (broken feeds): keep the day.
        return $isDate ? [CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC'), true, false] : [$at, false, $floating];
    }

    public function zone(string $tzid): DateTimeZone
    {
        $tzid = trim($tzid, " \"'");

        return $this->zones[$tzid] ??= self::iana($tzid)
            ?? self::fromWindows($tzid)
            ?? self::fromVendorPrefix($tzid)
            ?? $this->fromVtimezone($tzid)
            ?? $this->floating;
    }

    /** An IANA zone (or UTC / fixed offset accepted by PHP), or null. */
    public static function iana(?string $name): ?DateTimeZone
    {
        $name = trim((string) $name);
        if ($name === '' || ! preg_match('~^[A-Za-z0-9/_+\-]+$~', $name)) {
            return null;
        }
        self::$identifiers ??= array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));
        try {
            return isset(self::$identifiers[$name]) || in_array($name, ['UTC', 'GMT', 'Etc/UTC', 'Etc/GMT'], true)
                ? new DateTimeZone($name)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @var array<string, int>|null */
    private static ?array $identifiers = null;

    private static function fromWindows(string $tzid): ?DateTimeZone
    {
        $name = self::WINDOWS_ZONES[$tzid] ?? null;

        return $name ? new DateTimeZone($name) : null;
    }

    /** /mozilla.org/20050126_1/Europe/Berlin, /freeassociation.sourceforge.net/Tzfile/Europe/Berlin, … */
    private static function fromVendorPrefix(string $tzid): ?DateTimeZone
    {
        return preg_match('~([A-Za-z]+/[A-Za-z_+\-]+(?:/[A-Za-z_+\-]+)?)$~', $tzid, $m) ? self::iana($m[1]) : null;
    }

    private function fromVtimezone(string $tzid): ?DateTimeZone
    {
        $vtz = $this->vtimezones[$tzid] ?? null;
        if ($vtz === null) {
            return null;
        }
        if ($zone = self::iana($vtz->first('X-LIC-LOCATION')?->value)) {
            return $zone;
        }
        // Last resort: the standard-time offset as a fixed zone (no DST, but the right hour most of the year).
        $offset = ($vtz->children('STANDARD')[0] ?? $vtz->children('DAYLIGHT')[0] ?? null)?->first('TZOFFSETTO')?->value;
        if ($offset !== null && preg_match('/^([+-])(\d{2})(\d{2})/', trim($offset), $m)) {
            return new DateTimeZone("{$m[1]}{$m[2]}:{$m[3]}");
        }

        return null;
    }

    /**
     * A DURATION value: [days, seconds] with the sign applied (weeks become days). Null when invalid.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function duration(?string $value): ?array
    {
        if ($value === null || ! preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim($value), $m)) {
            return null;
        }
        $sign = ($m[1] ?? '') === '-' ? -1 : 1;
        $days = ((int) ($m[2] ?? 0)) * 7 + (int) ($m[3] ?? 0);
        $seconds = ((int) ($m[4] ?? 0)) * 3600 + ((int) ($m[5] ?? 0)) * 60 + (int) ($m[6] ?? 0);

        return [$sign * $days, $sign * $seconds];
    }

    /** Format an instant for DTSTART / RECURRENCE-ID / EXDATE: DATE, UTC, or wall-clock in a TZID. */
    public static function format(CarbonImmutable $at, bool $isDate, ?string $tzid = null): string
    {
        if ($isDate) {
            return $at->format('Ymd');
        }

        return $tzid !== null ? $at->format('Ymd\THis') : $at->utc()->format('Ymd\THis\Z');
    }

    /**
     * The key identifying one occurrence (RECURRENCE-ID / EXDATE matching): Ymd for dates, the
     * wall-clock Ymd\THis for floating times (the same whatever zone they're read in), UTC otherwise.
     */
    public static function key(CarbonImmutable $at, bool $isDate, bool $floating = false): string
    {
        return $isDate ? $at->format('Ymd') : ($floating ? $at->format('Ymd\THis') : $at->utc()->format('Ymd\THis\Z'));
    }
}
