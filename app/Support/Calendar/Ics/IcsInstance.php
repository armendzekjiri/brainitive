<?php

namespace App\Support\Calendar\Ics;

use App\Support\Calendar\NormalizedEvent;
use Carbon\CarbonImmutable;

/**
 * One occurrence of a VEVENT in a window: a single event, an occurrence of a series, or an
 * override (RECURRENCE-ID). $recurrenceKey identifies the occurrence of a series (IcsTime::key
 * of its original start); null for a one-off event. All-day: $start / $end are midnight UTC and
 * $end is exclusive (iCal DTEND).
 */
final class IcsInstance
{
    public function __construct(
        public readonly IcsComponent $event,
        public readonly string $uid,
        public readonly ?string $recurrenceKey,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $allDay,
    ) {}

    /** The normalized event (see NormalizedEvent); $timezone converts timed events. */
    public function normalize(string $id, ?string $timezone): array
    {
        $extra = [
            'description' => $this->event->text('DESCRIPTION'),
            'location' => $this->event->text('LOCATION'),
            'external_url' => $this->event->text('URL'),
        ];
        $title = $this->event->text('SUMMARY');

        if ($this->allDay) {
            return NormalizedEvent::allDay($id, $title, $this->start->toDateString(), $this->end->subDay()->toDateString(), $extra);
        }

        return NormalizedEvent::timed($id, $title, $this->start, $this->end, $timezone, $extra);
    }
}
