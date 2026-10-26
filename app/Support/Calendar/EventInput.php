<?php

namespace App\Support\Calendar;

use Carbon\Carbon;

/**
 * The write input of CalendarProvider::createEvent / updateEvent, parsed once so every
 * provider applies the same rules (see the CalendarProvider docblock):
 *  - all-day when start_time is empty; end_date is inclusive and defaults to start_date;
 *  - a timed event without end_time lasts 30 minutes;
 *  - times are wall-clock in `timezone` (IANA), default the app timezone.
 * For updates, has() tells which keys were sent: only those change.
 */
final class EventInput
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        return new self($data);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** Whether the request moves the event (start_date given). */
    public function changesTimes(): bool
    {
        return ! empty($this->data['start_date']);
    }

    public function timezone(): string
    {
        $tz = $this->data['timezone'] ?? null;

        return is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $tz
            : config('app.timezone');
    }

    public function isAllDay(): bool
    {
        return empty($this->data['start_time']);
    }

    public function startDate(): string
    {
        return (string) $this->data['start_date'];
    }

    /** Last day of an all-day event (inclusive). */
    public function endDate(): string
    {
        $end = $this->data['end_date'] ?? null;

        return $end && $end >= $this->startDate() ? (string) $end : $this->startDate();
    }

    /** Day after the last day (iCal / Google / Graph all-day DTEND). */
    public function endDateExclusive(): string
    {
        return Carbon::parse($this->endDate())->addDay()->toDateString();
    }

    /** Start instant of a timed event, in timezone(). */
    public function start(): Carbon
    {
        return Carbon::parse($this->startDate().' '.$this->data['start_time'], $this->timezone());
    }

    /** End instant of a timed event, in timezone(); start + 30 min when no (or no later) end. */
    public function end(): Carbon
    {
        $start = $this->start();
        if (empty($this->data['end_time'])) {
            return $start->copy()->addMinutes(30);
        }
        $end = Carbon::parse((($this->data['end_date'] ?? null) ?: $this->startDate()).' '.$this->data['end_time'], $this->timezone());

        return $end->gt($start) ? $end : $start->copy()->addMinutes(30);
    }
}
