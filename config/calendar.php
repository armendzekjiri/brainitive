<?php

/*
 * External calendars on user-supplied servers: CalDAV / iCloud and ICS links (v1.42.0, BRAI-141).
 * See App\Support\Calendar\SafeRemoteUrl.
 */
return [
    // Ports a CalDAV server / ICS link may use besides 80 and 443 (comma-separated). The defaults are
    // common self-hosted CalDAV ports: 8443, Synology (5001) and Radicale (5232).
    'allowed_ports' => array_values(array_filter(array_map('intval', explode(',', (string) env('CALENDAR_ALLOWED_PORTS', '8443,5001,5232'))))),

    // Biggest ICS file / CalDAV response we read, in bytes.
    'max_response_bytes' => (int) env('CALENDAR_MAX_RESPONSE_BYTES', 5_000_000),

    // Minutes an ICS link is served from the cache before it is fetched (revalidated) again.
    'ics_refresh_minutes' => (int) env('CALENDAR_ICS_REFRESH_MINUTES', 15),

    // E2E ONLY: lets the Playwright suite connect an ICS link / CalDAV server on 127.0.0.1 (any port).
    // Honoured only when APP_ENV=e2e (SafeRemoteUrl::allowPrivate()); every other environment ignores it.
    'allow_private_links' => env('CALENDAR_LINKS_ALLOW_PRIVATE', false),
];
