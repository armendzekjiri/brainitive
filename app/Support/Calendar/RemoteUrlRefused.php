<?php

namespace App\Support\Calendar;

/** A user-supplied calendar address (or a redirect / href it led to) failed the SSRF guard. */
class RemoteUrlRefused extends CalendarSyncFailed
{
    public function __construct(string $message = 'This address can’t be used: it must be a public http(s) address on port 80 or 443 (no localhost or private networks).')
    {
        parent::__construct($message);
    }
}
