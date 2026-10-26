<?php

namespace App\Support\Calendar\Caldav;

use App\Support\Calendar\CalendarSyncFailed;

/** The CalDAV server answered 401: wrong username or (app) password. */
class CaldavAuthFailed extends CalendarSyncFailed
{
    public function __construct(string $message = 'Check your username and app password.')
    {
        parent::__construct($message);
    }
}
