<?php

namespace App\Support\Calendar;

/**
 * Talking to the provider failed (expired access, server down, unexpected response). The
 * message is user-facing ("Reconnect this calendar.") and is stored in sync_error when it
 * happens while loading the calendar page.
 */
class CalendarSyncFailed extends CalendarProviderException {}
