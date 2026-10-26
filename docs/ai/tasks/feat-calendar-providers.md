# Calendar provider layer (Google moved onto it)

**Branch:** feat/calendar-providers (from `release/v1.42.0`)
**Status:** done
**Started:** 2026-10-26
**Board card:** BRAI-140 (board item 314)

## Problem / goal
The calendar was Google-only everywhere (controllers, MCP tools, reminders, UI). v1.42.0 adds
Outlook / Microsoft 365, Apple iCloud + any CalDAV server, and read-only ICS links. This branch
builds the shared provider layer those providers plug into and moves Google onto it, with no
visible change for Google users. Outlook and CalDAV/ICS are follow-up branches (see "How to add
a provider" below — that section is the contract they build against).

## Approach
- `App\Support\Calendar\CalendarProvider` (interface, the contract is in its docblock) +
  `BaseCalendarProvider` (defaults) + `CalendarProviders` registry (`PROVIDERS` key → class,
  `for($connection)`, `make($key)`, `writable($connection)`, `catalog()` for the UI, `fake()` for tests).
- `Providers\GoogleCalendarProvider` adapts `GoogleCalendarService` (HTTP stays in the service;
  Google JSON ↔ normalized events lives in the adapter). **Google Tasks are not part of the
  contract**: `kind = 'tasks'` rows keep `GoogleTasksService` and the `google_task` event type.
- Shared helpers: `NormalizedEvent` (the event shape), `EventInput` (write rules),
  `PendingConnection` (picker state in the session, secrets encrypted),
  `CalendarProviderException` → `CalendarReadOnly` / `CalendarSyncFailed` (render as 422 JSON, or
  `back()->withErrors(['calendar' => …])` for Inertia).
- `Providers\E2eCalendarProvider`: a made-up read-only `url` provider, registered **only** with
  `APP_ENV=e2e` (`CalendarProviders::all()`), for the Playwright journeys.

## Changes
- **Migration** `2026_10_31_150000_add_provider_fields_to_calendar_connections_table`:
  `server_url` (string 2048, nullable), `credentials` (text, cast `encrypted:array`, `$hidden`),
  `sync_error` (text), `sync_error_at` (timestamp). The partial unique index
  `(user_id, provider, kind, account_email, external_calendar_id) WHERE deleted_at IS NULL` is
  unchanged: it already lets two ICS links of one user coexist (different `external_calendar_id`).
- **Model** `CalendarConnection`: fillable/casts/hidden for the new columns; `upsertFor()` (find-
  or-create, revives soft-deleted rows — was a private controller method); `recordSyncError()` /
  `clearSyncError()` (quiet saves; clear writes only when there was an error). Factory added.
- **CalendarController**: `index` asks every active connection's provider for events (in the user's
  timezone when set), maps them to `type: 'external_event'` with `provider`, `provider_label`,
  `provider_badge`, `connection_id`, `read_only`, `all_day`, `location`; any `Throwable` → `sync_error`
  on that connection, page still 200. `connections` rows gain `provider_label`, `provider_badge`,
  `server_url`, `read_only`, `sync_error`, `sync_error_at`; new prop `calendarProviders` (catalog).
  Source filter key `'external'` (a legacy `'google'` in the URL maps to it). `reschedule` takes
  `external_event` (and the legacy `google_event`) through `CalendarProviders::writable()`.
- **CalendarConnectionController**: Google callback lists calendars through the adapter and stores a
  `PendingConnection`; `pendingPicker` / `store` are provider-neutral (token propagation now limited
  to the same provider + account); new `manualStore` (route `calendar.connections.manual.store`,
  `POST calendar/connections/manual/{provider}`, throttle 10/min); `showEvent` returns
  `{event: <normalized>, provider, provider_label, read_only}` and takes `?timezone=`;
  create/update/delete go through `CalendarProviders::writable()`; disconnect also clears `credentials`.
  Event id route constraint widened to `[^/]+`.
- **MCP**: `list_calendar_connections` (+ `provider`, `writable`), `list_calendar_events` (any
  provider, `errors` list for calendars that failed), create/update/delete through the registry
  (read-only → tool error "This calendar is read-only."). `CalendarFormatter::normalized()`.
  All-day `end` is now the inclusive last day.
- **SendCalendarReminders**: every active calendar connection through its provider
  (`NormalizedEvent::startsAt()`); one failing connection is skipped, not fatal. Reminder keys unchanged.
- **Frontend** `Pages/Calendar/Index.tsx`: chips/bars/dialog use the provider label + badge; read-only
  events can't be dragged/resized and open view-only; quick create tab "Calendar" (writable
  connections of any provider + Google Tasks lists); "Add calendar" menu from `calendarProviders`
  (oauth → link, form/url → `ManualConnectDialog`); picker for any `?picking=<provider>`;
  `SyncWarnings` strip; refusals (`errors.calendar`) shown as toasts. Types: `CalendarEvent`,
  `CalendarConnectionRow` changed; new section at the end of `types/index.d.ts`
  (`CalendarProviderInfo`, `CalendarProviderField`, `NormalizedCalendarEvent`, `CalendarPickerData`).
- Fixed on the way: Google Tasks without a time were shown at 00:00 (the "UTC midnight" check
  compared `timezoneName === 'UTC'`, which is `'Z'` for Google's timestamps).

---

## How to add a provider (contract for the Outlook and CalDAV / ICS branches)

### 1. The class
`app/Support/Calendar/Providers/<Name>CalendarProvider.php`, `extends BaseCalendarProvider`.
Read the docblock of `app/Support/Calendar/CalendarProvider.php` first — it is the normative
contract. Methods:

| Method | Notes |
|---|---|
| `key(): string` | Registry key, stored in `calendar_connections.provider`. Never change it. Suggested: `outlook`, `caldav`, `ics`. |
| `label(): string` | UI name, e.g. "Outlook / Microsoft 365", "Apple iCloud / CalDAV", "Calendar link (ICS)". |
| `badge(): string` | 1–2 chars on event chips (default: first letter of the label). |
| `defaultColor(): string` | Colour when the connection has none. |
| `connectMode(): 'oauth'\|'form'\|'url'` | See below. |
| `available(): bool` | Hide from "Add calendar" when not configured (Outlook: client id/secret empty). |
| `redirectRoute(): ?string` | `oauth` only: route name that starts the flow. |
| `formFields(): array` | `form` / `url` only: `[{name, label, type: text\|url\|email\|password, help?, placeholder?, required? (default true)}]`. Names must be simple `snake_case` (they become request keys). |
| `capabilities(): array` | `['write' => bool, 'tasks' => false]` (tasks = Google only). |
| `isWritable(CalendarConnection)` | Default = `capabilities()['write']`. Override for per-calendar rights (a read-only shared CalDAV calendar): e.g. keep a flag in `credentials`. |
| `connectWithForm(User, array $input)` | `form` / `url`: `$input` = validated declared fields. Return `list<CalendarConnection>` (created) **or** a `PendingConnection` to show the picker. Throw `ValidationException::withMessages(['password' => …])` for field errors, `CalendarSyncFailed('…')` for a general message. |
| `listCalendars(CalendarConnection)` | For pickers: `[{id, name, color, primary, read_only}]`. |
| `listEvents($conn, $start, $end, ?$timezone)` | Normalized events overlapping the whole days `[$start, $end]`. Throw `CalendarSyncFailed` on failure (never return `[]` for an error). |
| `getEvent($conn, $id, ?$timezone)` | Normalized event or `null` (not found). |
| `createEvent / updateEvent / deleteEvent` | Writable providers only (the base class throws `CalendarReadOnly`). Call `$this->assertWritable($connection)` first. Return the normalized event (create/update). `deleteEvent` treats "already gone" as success. |

### 2. Normalized event (`NormalizedEvent`)
`{id, title, description, location, all_day, start_date (Y-m-d), start_time (H:i|null),
end_date (Y-m-d, INCLUSIVE for all-day), end_time (H:i|null), timezone, external_url}`.
Build with `NormalizedEvent::timed($id, $title, $startInstant, $endInstant, $timezone, $extra)` /
`NormalizedEvent::allDay($id, $title, $startDate, $endDateInclusive, $extra)`.
- iCal `DTEND;VALUE=DATE` and Graph all-day `end` are **exclusive** → pass end − 1 day.
- When `$timezone` is given, timed events must be converted to it (`timed()` does that).
- `id` must round-trip into get/update/delete and match `[^/]+` (no slashes). For CalDAV, use
  a URL-safe encoding of the object href (e.g. base64url of the href), not the raw path.
  Recurring CalDAV/Graph instances need ids that identify the instance (e.g. `uid` + recurrence id).

### 3. Write input (`EventInput::from($data)`)
Keys: `title, description, location, start_date, start_time, end_date, end_time, timezone`.
`isAllDay()`, `startDate()`, `endDate()` (inclusive), `endDateExclusive()`, `start()` / `end()`
(Carbon in `timezone()`; no end → +30 min), `timezone()` (validated IANA, default app tz),
`has($key)` (update = only keys present change), `changesTimes()`.

### 4. Connect modes
- **oauth** (Outlook): own routes in `routes/web.php` next to the Google ones, e.g.
  `calendar/connections/outlook/redirect|callback` → `calendar.connections.outlook.redirect|callback`
  (methods on `CalendarConnectionController` or a small `OutlookCalendarConnectController`). The
  callback builds `new PendingConnection(provider: 'outlook', accountEmail:, accountName:,
  calendars: $provider->listCalendars($probe), secrets: ['access_token' =>, 'refresh_token' =>,
  'expires_in' =>])->put()` and redirects to `route('calendar.index', ['picking' => 'outlook'])`.
  The shared picker + `calendar.connections.store` create the rows and copy the tokens
  (`$probe` = an unsaved `CalendarConnection` holding the fresh tokens, like the Google callback).
  Refresh tokens inside the provider (see `GoogleCalendarService::ensureFreshToken`); save rotated
  tokens with `$connection->forceFill([...])->save()` (never a query-builder update — the
  `encrypted` cast would be bypassed).
- **form** (CalDAV / iCloud): fields e.g. `server_url` (url, iCloud default
  `https://caldav.icloud.com`), `username` (email/text), `password` (password, help: "an
  app-specific password"). `connectWithForm` discovers calendars, then either returns a
  `PendingConnection` (secrets: `['credentials' => ['username' =>, 'password' =>]]`,
  `serverUrl:`) for the picker, or creates the rows directly with `CalendarConnection::upsertFor()`.
- **url** (ICS): exactly one field (`url`, type `url`; `webcal://` is accepted — rewrite to
  `https://`). Read-only (`capabilities()['write'] = false`, nothing else to implement for writes).
  Row: `account_email = 'Calendar link'` (or the host), `external_calendar_id = sha1($url)`,
  `server_url = host`, `credentials = ['url' => $url]` (the URL often holds a secret token —
  never put it in `server_url`/name). See `Providers/E2eCalendarProvider` for a complete minimal
  `url` provider.

### 5. Rows & secrets
`provider = key()`, `kind = 'calendar'`, `account_email` NOT NULL (account / username / label),
`external_calendar_id` ≤ 255 chars and unique per (user, provider, kind, account_email).
Secrets only in `access_token` / `refresh_token` (OAuth) or `credentials` (encrypted array,
hidden). Every request to a user-supplied URL (CalDAV server, ICS link) goes through
`App\Support\OutboundUrl::options()` (SSRF guard). Error messages are shown to users — no
internals, no URLs with tokens. No migration needed.

### 6. Registry line
`app/Support/Calendar/CalendarProviders.php` → `PROVIDERS`:
```php
'outlook' => Providers\OutlookCalendarProvider::class, // BRAI-…
'caldav' => Providers\CalDavCalendarProvider::class,
'ics' => Providers\IcsCalendarProvider::class,
```
The menu order is the registry order. Nothing else to register: the page, reschedule, event
dialog, quick create, MCP tools and reminders pick it up.

### 7. Tests to copy
- `tests/Feature/Calendar/GoogleCalendarProviderTest.php` — adapter normalization with
  `Http::fake()` (all-day exclusive end, timed + timezone conversion, failure → `CalendarSyncFailed`,
  write payloads). Copy for Graph / CalDAV REPORT / ICS parsing.
- `tests/Feature/Calendar/CalendarProvidersTest.php` — manual connect route (validation, encrypted
  credentials, provider errors, picker flow). Use your real provider with `Http::fake()`.
- `tests/Feature/Calendar/CalendarProviderDispatchTest.php` + `FakeCalendarProvider` — page /
  reschedule / dialog dispatch, read-only 422, `sync_error`. Usually no change needed.
- e2e: `e2e/tests/calendar-providers.spec.ts` (generic dialog, read-only, sync warning). A real
  provider needs a reachable server; keep e2e on the e2e provider unless you add a local fixture.

### Notes / limits
- `GoogleCalendarService` still deactivates a Google connection when the token refresh fails (as
  before); the page keeps listing inactive connections that have a `sync_error`, so the
  "Reconnect" warning stays. Other providers should **not** deactivate — throw `CalendarSyncFailed`.
- Per-calendar read-only: the picker stores `credentials.read_only = true` for calendars whose
  `listCalendars()` entry had `read_only`; `BaseCalendarProvider::isWritable()` honours it.
- Reconnecting an account (picker `store`) copies new tokens and `credentials` to all of the
  account's rows (keeping each row's `read_only`) and revives rows switched off by a failure.
- `manualStore` flashes back only text / email fields after an error — never url / password.
- The month-view drop of a timed external event onto a day sends only `due_date` → the provider
  receives an all-day move (pre-existing Google behaviour, unchanged).
- Review follow-ups applied: non-http(s) `external_url` dropped (server + `safeExternalHref`), logs
  carry exception classes only, `.`/`..` event ids refused, provider exceptions not reported,
  event dialog fetch aborted on change. Not done: HelpModal changelog (release adds it); the first
  render after a timezone change uses the stored zone until `useTimezoneSync` updates it.

## Test plan / results
- PHPUnit (`TEST_DB_SUFFIX=calprov`): `tests/Feature/Calendar` (4 files, 42 tests) + Calendar*,
  CalendarFormatter, Mcp, Security/AccessControl, Notifications/NotificationPreferences,
  Sidebar/SidebarPreferences → 90 passed (891 assertions).
- e2e (`E2E_PORT=8035`): `calendar-providers.spec.ts` 3 passed; `content.spec.ts -g calendar` 2 passed.
- `pint --test`, `npx tsc --noEmit`, `npm run build`: green.
