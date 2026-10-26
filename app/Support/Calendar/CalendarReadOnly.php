<?php

namespace App\Support\Calendar;

/** A write was attempted on a read-only provider or calendar (ICS links, shared read-only calendars). */
class CalendarReadOnly extends CalendarProviderException
{
    public function __construct(string $message = 'This calendar is read-only.')
    {
        parent::__construct($message);
    }
}
