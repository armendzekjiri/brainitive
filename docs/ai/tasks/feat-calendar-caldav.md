# Calendar providers: CalDAV / iCloud + ICS links (BRAI-141)

**Branch:** feat/calendar-caldav (from `release/v1.42.0`)
**Status:** done
**Started:** 2026-10-26
**Board card:** BRAI-141 (board item 315)

## Problem / goal
The calendar shows Google calendars only. Users asked for "other providers or a manual
provider". This branch adds two providers on the BRAI-140 provider layer
(`docs/ai/tasks/feat-calendar-providers.md`, "How to add a provider"):

1. **Apple iCloud / CalDAV** (`caldav`, connect mode `form`): server URL + username + app
   password. Works with iCloud, Fastmail, Nextcloud, Zoho, Synology, Radicale, … Read + write.
2. **Calendar link (ICS)** (`ics`, connect mode `url`): any secret iCal / ICS / webcal address
   (Google "secret address in iCal format", Outlook published calendar, Apple public calendar,
   holidays, school timetables). Read-only, refreshed about every 15 minutes.

## Approach
- **No new dependency.** A small RFC 5545 reader/writer in `app/Support/Calendar/Ics/`
  (unfolding, parameters, escaping, DATE / DATE-TIME, UTC / TZID / floating, DURATION, RRULE with
  DAILY / WEEKLY / MONTHLY / YEARLY, INTERVAL, COUNT, UNTIL, BYDAY (+ ordinals), BYMONTHDAY,
  BYMONTH, BYSETPOS, EXDATE, RDATE, RECURRENCE-ID overrides, hard instance caps). sabre/vobject
  would do more, but dependency changes need the maintainer's approval (CONVENTIONS "Licence &
  dependencies") and the subset we need is small and fully tested.
- **SSRF:** `App\Support\Calendar\SafeRemoteUrl` wraps the existing `App\Support\OutboundUrl`
  guard (public IPs only, pinned resolve) and adds: webcal → https, allowed ports (80, 443 +
  `CALENDAR_ALLOWED_PORTS`), manual redirects (max 3, each hop re-checked, credentials only to
  the same origin), timeouts, a response size cap, transport errors → generic `CalendarSyncFailed`.
  Private targets only with `APP_ENV=e2e` + `CALENDAR_LINKS_ALLOW_PRIVATE` (e2e fixture server).
- **CalDAV:** PROPFIND current-user-principal (falls back to `/.well-known/caldav`) →
  calendar-home-set → Depth 1 list (displayname, calendar-color, supported component set =
  VEVENT, current-user-privilege-set → `read_only`) → `PendingConnection` picker. Events: REPORT
  calendar-query with a time-range; recurrences expanded locally by the reader (servers differ in
  `<expand>` support, and writes need the master anyway). Writes: PUT new `.ics` (`If-None-Match:
  *`), update = GET + modify + PUT `If-Match`, delete = DELETE `If-Match`; a single occurrence of
  a series becomes a RECURRENCE-ID override (edit) or an EXDATE (delete).
- **ICS:** each link is its own connection (`external_calendar_id = sha1(url)`, URL only in
  encrypted `credentials`, host in `server_url`), body cached 15 min per connection with
  ETag / If-Modified-Since revalidation, 5 MB cap.

## Changes
- **Guard** `app/Support/Calendar/SafeRemoteUrl.php` (+ `RemoteUrlRefused`, `CappedStream`), `config/calendar.php`
  (`CALENDAR_ALLOWED_PORTS` default `8443,5001,5232` on top of 80 / 443, `CALENDAR_MAX_RESPONSE_BYTES`
  5 MB, `CALENDAR_ICS_REFRESH_MINUTES` 15, `CALENDAR_LINKS_ALLOW_PRIVATE` e2e only).
- **Reader / writer** `app/Support/Calendar/Ics/`: `IcsReader` (parse), `IcsComponent` / `IcsProperty`
  (tree, escaping, folding, lossless re-serialisation of unknown lines), `IcsTime` (DATE / DATE-TIME,
  TZID incl. Windows names, vendor prefixes, VTIMEZONE fallback, DURATION), `RecurrenceRule`
  (RRULE), `IcsEvents` (occurrences in a day range; `occurrence()` for one id), `IcsInstance`
  (→ normalized event).
- **CalDAV** `app/Support/Calendar/Caldav/CaldavClient.php` (+ `CaldavAuthFailed`),
  `Providers/CaldavCalendarProvider.php`.
- **ICS** `Providers/IcsSubscriptionProvider.php`.
- `CalendarProviders::PROVIDERS`: two lines (`caldav`, `ics`).
- **Shared-layer fixes (minimal):** `routes/web.php` — the BRAI-140 manual connect route had an
  unprefixed `throttle:10,1` (RouteThrottleTest failed on the base branch) → `throttle:10,1,calendar.connections.manual.store`;
  `Pages/Calendar/Index.tsx` — the picker effect ran only on mount, so a `form` provider returning a
  `PendingConnection` (Inertia redirect to `?picking=caldav`) never opened the picker → the effect
  now follows `usePage().url`; `CalendarProviderDispatchTest` no longer counts the providers;
  `CalendarProvider` docblock: "never pass $allowPrivate = true" notes SafeRemoteUrl's e2e-only switch.
- `e2e/env.ts`: `CALENDAR_LINKS_ALLOW_PRIVATE=true`; `e2e/helpers/calendar-server.ts` (ICS feed +
  tiny CalDAV server on 127.0.0.1); `e2e/tests/calendar-links.spec.ts`.
- No migration, no new dependency, no new route.

## Decisions / limits
- **No sabre/vobject:** dependency changes need maintainer approval, and the subset needed is small.
  Not supported: BYHOUR / BYMINUTE / BYSECOND (ignored), BYWEEKNO / BYYEARDAY and sub-daily FREQ
  (the event shows once, at DTSTART), RANGE=THISANDFUTURE, VTIMEZONE rules (a zone we can't name falls
  back to its standard offset — right most of the year).
- **Caps:** 2000 occurrences per expansion, 50 000 recurrence periods per call *shared by all series*
  (5000 per rule), 20 000 VEVENTs, 200 000 content lines / 30 000 components per file (memory),
  duplicate BYDAY / BYMONTHDAY / BYSETPOS values dropped, 5 MB per response (a `CappedStream` sink
  makes curl abort a chunked body at the cap — Guzzle's `progress` callback can't), 3 redirects,
  5 s connect / 20 s total, parser depth 8.
- **Several one-off VEVENTs with one UID** (school timetables, fixtures) are all shown, each with its
  DTSTART key as occurrence key; events without UID get a stable stand-in (`IcsEvents::uidOf`).
- **Floating times** (no Z, no TZID) are keyed by wall clock (`20261109T090000`, no `Z`), so ids are the
  same in every viewer zone; RECURRENCE-ID / EXDATE for a floating series are written floating too.
- **Moving a whole CalDAV series** (an id without occurrence key + new times) is refused: writing its
  DTSTART in UTC would lose its zone. Title / description / location of a series can change.
- **Deleting one occurrence** of an object without a master (an invitation to single occurrences)
  removes only that VEVENT (DELETE only when none is left); an unreadable object is never deleted
  for an occurrence.
- **Recurring CalDAV events are expanded locally**, not with `<C:expand>` (support differs between
  servers, and an edit needs the master). The REPORT asks the range ± 1 day; the exact filter is local.
- **Writes** put timed events in UTC (`DTSTART:…Z`, no VTIMEZONE needed); one occurrence of a series is
  edited through a RECURRENCE-ID override (in the series' own TZID form) and deleted with an EXDATE.
- **App password routing:** sent only over https (except the e2e fixture), only to the "site" of the
  server entered (`CaldavClient::site()`: iCloud's `pNN-caldav.icloud.com` homes are fine, another
  domain is refused; a heuristic, not the Public Suffix List), and redirects to another origin drop it
  — an answer that came from another origin is refused, its hrefs are never followed. Multistatus XML
  with a DOCTYPE is refused (string check + `$dom->doctype` after parsing, for UTF-16 bodies). Event ids
  are confined to the calendar collection also after percent-decoding (`%2e%2e`). An update without an
  ETag sends no precondition (only creates send `If-None-Match: *`).
- **Ports:** 80 / 443 plus `CALENDAR_ALLOWED_PORTS` (default 8443, Synology 5001, Radicale 5232).
- **ICS failures are not hidden by the cache:** after 15 minutes a failing link raises
  `CalendarSyncFailed` (→ `sync_error`), it doesn't keep serving the old copy.
- **401 on CalDAV** → "Check your username and app password." (`sync_error`, connection stays active).
- An ICS connection: `account_email` = `server_url` = the host, name = `X-WR-CALNAME` (else the host),
  colour = `X-APPLE-CALENDAR-COLOR`. A CalDAV connection: `account_email` = the username
  (`username@host` when it isn't an email), `external_calendar_id` = the collection URL.

## Test plan
- PHPUnit: reader (folding, escaping, TZID / Windows zones, all-day, RRULE / EXDATE / overrides,
  caps), ICS provider (fixtures Google / Outlook / Apple / holidays, cache + revalidation, two
  links, read-only 422, SSRF refusals incl. redirect to private), CalDAV provider (`Http::fake`
  XML fixtures: discovery, picker, REPORT, create / update / delete with ETags, 401 / 403 / 404 /
  412).
- e2e `calendar-links.spec.ts` (Node server in the worker = ICS feed + small CalDAV server):
  an ICS link shows its events read-only and never its secret path; the CalDAV dialog validates,
  a wrong app password is a field error, the picker adds the calendar and an edited event is PUT back.

## Results
- PHPUnit (`TEST_DB_SUFFIX=caldav`): `tests/Feature/Calendar` (7 files) + `tests/Unit/CalendarFeedIcsTest`,
  `CalendarFormatterTest`, `CalendarDeletedBoardTest`, `CalendarViewPreferenceTest`,
  `Security/RouteThrottleTest`, `Security/SecurityRegressionTest`, MCP calendar tests → 141 passed
  (1248 assertions). New: IcsReaderTest 13, IcsSubscriptionProviderTest 13, CaldavCalendarProviderTest 22.
- Reviews: security-reviewer + php-reviewer (no critical / high); every medium and low finding fixed
  with a regression test, except the `site()` heuristic (documented above).
- e2e (`E2E_PORT=8038`): `calendar-links.spec.ts` 2 passed; with `calendar-providers.spec.ts` and
  `calendar-feed.spec.ts` 8 passed (incl. setup); `content.spec.ts -g calendar` 4 passed.
- `pint --test`, `npx tsc --noEmit`, `npm run build`: green.

## How-to for users (help text / changelog material)

**Apple iCloud** — Calendar → Add calendar → *Apple iCloud / CalDAV*:
1. Go to **appleid.apple.com** → *Sign-In and Security* → *App-Specific Passwords* → **+**, name it
   "Brainitive", copy the password (`xxxx-xxxx-xxxx-xxxx`). Your normal Apple ID password won't work
   (two-factor authentication).
2. Leave *Server URL* empty (= `https://caldav.icloud.com`), enter your Apple ID email and the app password.
3. Pick the calendars to show. Shared calendars you may only read are marked read-only.

**Other CalDAV servers** — same dialog, with the server URL:
- Fastmail: `https://caldav.fastmail.com` + an app password (Settings → Privacy & Security → App passwords).
- Nextcloud: `https://<your cloud>/remote.php/dav` + an app password (Settings → Security → Devices & sessions).
- Zoho: `https://calendar.zoho.com`; Synology: `https://<nas>:5001/caldav/`; Radicale: `https://<host>:5232/`.
  The server must be reachable from the internet over https (no local network addresses).

**Calendar links (ICS, read-only, refreshed about every 15 minutes)** — Add calendar → *Calendar link (ICS)*:
- **Google Calendar:** calendar.google.com → ⚙ Settings → *Settings for my calendars* → your calendar →
  *Integrate calendar* → **Secret address in iCal format** (keep it private; "Reset" invalidates it).
- **Outlook / Microsoft 365:** outlook.com / Outlook on the web → ⚙ Settings → Calendar → *Shared
  calendars* → **Publish a calendar** → pick the calendar + "Can view all details" → copy the **ICS** link.
- **Apple iCloud:** Calendar app → right-click a calendar → *Share Calendar…* → **Public Calendar** →
  copy the `webcal://` link (or connect iCloud through CalDAV above to also edit).
- Holidays, school timetables, sports fixtures: any `https://…/….ics` or `webcal://…` link.
Each link is its own calendar; the link itself is stored encrypted and never shown again (the page shows
only its host). To change it, remove the calendar and add the new link.
