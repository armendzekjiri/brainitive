<?php

namespace App\Support\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * One external calendar provider (Google, Outlook, CalDAV / iCloud, ICS link, …) — BRAI-140.
 *
 * Everything outside the provider (calendar page, reschedule, event dialog, MCP tools,
 * reminders) talks to calendars only through this contract, resolved with
 * CalendarProviders::for($connection). Extend BaseCalendarProvider instead of implementing
 * this from scratch: it has the defaults (no form, no picker, read-only writes throw).
 *
 * ── Registration ─────────────────────────────────────────────────────────────────────────
 * One line in CalendarProviders::PROVIDERS ('key' => Class::class). The key is stored in
 * calendar_connections.provider and must never change. Instances come from the container
 * (app($class)), so constructor injection works.
 *
 * ── Connect modes (connectMode()) ─────────────────────────────────────────────────────────
 *  'oauth' — the "Connect a calendar" menu links to route(redirectRoute()). The provider's
 *            own controller does the OAuth dance, then calls PendingConnection::put() and
 *            redirects to calendar.index?picking=<key>; the shared picker dialog posts the
 *            chosen calendars to calendar.connections.store, which creates the rows.
 *  'form'  — the menu opens a generic dialog rendering formFields(); it POSTs to
 *            calendar.connections.manual.store (provider = key), which validates the declared
 *            fields and calls connectWithForm($user, $input).
 *  'url'   — like 'form' with exactly one field of type 'url' (an ICS / webcal link).
 *
 * ── Connection row conventions ──────────────────────────────────────────────────────────
 *  provider = key(); kind = 'calendar' ('tasks' is Google Tasks only);
 *  account_email = the account (email / username, or a stable label such as "ICS link");
 *  external_calendar_id = unique per (user, provider, kind, account_email), ≤ 255 chars
 *  (a calendar href, or a sha1 of a long URL); server_url = CalDAV base / ICS host (display);
 *  secrets go in `credentials` (encrypted array) or the OAuth token columns — never elsewhere.
 *  Create rows with CalendarConnection::upsertFor() (revives soft-deleted duplicates).
 *
 * ── Normalized event (what listEvents / getEvent / createEvent / updateEvent return) ───────
 *  NormalizedEvent::KEYS — build with NormalizedEvent::timed() / ::allDay():
 *   id           string  provider event id; must round-trip into getEvent/updateEvent/deleteEvent
 *                        and match the route constraint [^/]+ (no slashes — encode hrefs).
 *   title, description, location   string|null
 *   all_day      bool
 *   start_date   'Y-m-d'
 *   start_time   'H:i' | null  (null when all_day)
 *   end_date     'Y-m-d' — INCLUSIVE for all-day events (Google/iCal DTEND is exclusive: subtract a day)
 *   end_time     'H:i' | null
 *   timezone     string|null — the zone the wall-clock times are in. When listEvents/getEvent
 *                get a $timezone, timed events MUST be converted to it and report it; otherwise
 *                report the event's own zone (IANA name or an offset like "+02:00").
 *   external_url string|null — "open in <provider>" link, if any.
 *
 * ── Write input (createEvent / updateEvent $data) ───────────────────────────────────────
 *  Same field names: title, description, location, start_date, start_time, end_date,
 *  end_time, timezone (IANA, the zone the given times are in). Use EventInput::from($data).
 *  createEvent: title + start_date required; no end_date = start_date; timed without
 *  end_time = start + 30 min; all-day end_date inclusive.
 *  updateEvent: partial — only keys present change. When start_date is present the times are
 *  replaced as a whole (start_time null = all-day).
 *
 * ── Errors ──────────────────────────────────────────────────────────────────────────────
 *  - Read-only provider/calendar: write methods throw CalendarReadOnly (→ 422 "This calendar
 *    is read-only."). BaseCalendarProvider does this when capabilities()['write'] is false.
 *  - Anything else that fails (auth expired, server down, bad response): throw
 *    CalendarSyncFailed with a short message a user can act on ("Reconnect this calendar.").
 *    The calendar page catches every Throwable from listEvents per connection, stores the
 *    message in sync_error and still renders; it never breaks the page.
 *  - getEvent returns null for "not found". deleteEvent treats "already gone" as success.
 *  - Logs: never log exception messages of transport errors — they carry URLs with secrets.
 *  - Per-calendar read-only: the picker stores credentials.read_only = true for calendars whose
 *    listCalendars() entry had read_only = true; BaseCalendarProvider::isWritable() honours it.
 *
 * ── Outbound requests to user-supplied URLs (CalDAV servers, ICS links) — SSRF ───────────
 *  - Every request goes through App\Support\OutboundUrl::options($url) — at connect time AND
 *    in listEvents / getEvent / writes / listCalendars (DNS can change; never cache it).
 *    Never pass $allowPrivate = true.
 *  - Rewrite webcal:// to https:// before calling options() (it only allows http/https).
 *  - Redirects are off: follow them by hand (/.well-known/caldav, iCloud's pNN hosts), re-check
 *    each Location with options(), cap the hops, and never resend credentials to another host.
 *  - URLs a CalDAV server returns (home set, calendar and object hrefs) are untrusted: resolve
 *    them against the base URL and run each through options() before requesting it.
 *  - Set connect_timeout + timeout and cap the response size (ICS feeds can be huge).
 *  - Catch transport exceptions and rethrow CalendarSyncFailed with a generic message: the
 *    message is stored in sync_error and shown in the page and MCP output.
 *  - server_url = host / base URL only (it reaches the page). A full ICS URL (its token is a
 *    secret) lives in `credentials` only. Only text / email fields are flashed back after a
 *    validation error; url and password fields never are.
 *  - Event ids put into URLs are encoded or verified by the provider (the routes already
 *    refuse "." and "..").
 */
interface CalendarProvider
{
    /** Registry key, stored in calendar_connections.provider (e.g. 'google'). */
    public function key(): string;

    /** Human name shown in the UI (e.g. 'Google Calendar'). */
    public function label(): string;

    /** One or two characters for the small provider badge on event chips (e.g. 'G'). */
    public function badge(): string;

    /** Default colour for events of a connection without its own colour. */
    public function defaultColor(): string;

    /** 'oauth' | 'form' | 'url' — see the class docblock. */
    public function connectMode(): string;

    /** Whether "Connect a calendar" offers this provider (e.g. OAuth client configured). */
    public function available(): bool;

    /** Route name that starts the OAuth flow ('oauth' mode only), else null. */
    public function redirectRoute(): ?string;

    /**
     * Fields of the generic connect dialog ('form' / 'url' modes).
     *
     * @return list<array{name: string, label: string, type: 'text'|'url'|'email'|'password', help?: string|null, placeholder?: string|null, required?: bool}>
     */
    public function formFields(): array;

    /** @return array{write: bool, tasks: bool} */
    public function capabilities(): array;

    /** Whether events of this particular connection can be created / changed / deleted. */
    public function isWritable(CalendarConnection $connection): bool;

    /**
     * Connect from the generic dialog ('form' / 'url'). $input holds the validated formFields()
     * values. Return the created connections, or a PendingConnection to let the user pick which
     * calendars to add (the controller stores it and opens the picker). Throw a
     * ValidationException (field errors) or CalendarSyncFailed (message) when it can't connect.
     *
     * @param  array<string, string|null>  $input
     * @return list<CalendarConnection>|PendingConnection
     */
    public function connectWithForm(User $user, array $input): array|PendingConnection;

    /**
     * The calendars this connection's account can see (for the picker after connecting).
     *
     * @return list<array{id: string, name: string, color: string|null, primary: bool, read_only: bool}>
     */
    public function listCalendars(CalendarConnection $connection): array;

    /**
     * Events overlapping [$start, $end] (whole days), as normalized events.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CalendarSyncFailed
     */
    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array;

    /** @return array<string, mixed>|null normalized event, null when not found */
    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the created event, normalized
     *
     * @throws CalendarReadOnly|CalendarSyncFailed
     */
    public function createEvent(CalendarConnection $connection, array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the updated event, normalized
     *
     * @throws CalendarReadOnly|CalendarSyncFailed
     */
    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): array;

    /** @throws CalendarReadOnly|CalendarSyncFailed */
    public function deleteEvent(CalendarConnection $connection, string $eventId): void;
}
