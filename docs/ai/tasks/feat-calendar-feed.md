# Calendar feed: subscribe to your Brainitive dates in other apps (BRAI-142)

**Branch:** feat/calendar-feed (from `release/v1.42.0`)
**Status:** done
**Started:** 2026-10-26
**Board card:** BRAI-142 (board item 316)

## Problem / goal
People want their Brainitive dates in the calendar they already use (Apple Calendar, Outlook,
Google Calendar — phone and desktop). The user picked "Brainitive feed to other apps" among the
calendar-provider options for v1.42.0. MVP: one private, revocable ICS subscription URL per user.

## Approach
- **One active feed per user** (`calendar_feeds`). The token is shown once as a URL but stored as a
  sha256 `token_hash` (lookup) plus an `encrypted` copy so the owner can copy the URL again. Reset =
  revoke the row + a new row with a new token (the old URL 404s). Turn off = revoke.
- **Public `GET /calendar/feed/{token}.ics`** (`calendar-feed.ics`): stateless (no session, cookies,
  CSRF or Inertia middleware), `throttle:600,1,calendar-feed.ics` per IP (loose: Google / Outlook fetch every subscriber from a few shared IPs) plus a per-feed limit of 30 / min in the controller (`FETCHES_PER_MINUTE`, keyed on the feed id), `text/calendar; charset=utf-8`,
  `Cache-Control: private`, `X-Robots-Tag: noindex`. Unknown / revoked token / suspended owner →
  plain 404. Every fetch re-computes the owner's accessible projects (`User::accessibleProjectIds()`).
- **Caching:** `ETag` = sha256 of the body. The body is deterministic (`DTSTAMP` = each record's
  `updated_at`), so the feed stores `content_hash` + `content_changed_at`; `Last-Modified` is the time
  the body last changed (a removed item or lost access also changes it). `If-None-Match` /
  `If-Modified-Since` → 304 (Symfony `isNotModified`).
- **Content** (options, json): assigned issues (default on), issues I created (off), list tasks
  assigned to me or unassigned + created by me (on; recurring tasks as `RRULE`), My Day (on, all-day
  on the plan date), focus sessions logged with the timer (on; `time_entries.source = timer`, timed
  block ending when it was logged), completed (off), descriptions (off), project filter (empty = all).
  Range: 60 days back, 365 forward (user's local today), max 2000 events.
- **ICS** hand-written (`App\Support\CalendarFeed\Ics`): RFC 5545 escaping, 75-octet UTF-8-safe
  folding, CRLF, all-day `VALUE=DATE` with exclusive `DTEND`, timed in UTC (`Z`) converted from the
  user's `localTimezone()` wall clock. SUMMARY `[KEY-12] Title`; DESCRIPTION always ends with the
  link (Google ignores `URL`). No assignees, comments or other people.

## Changes
- Migration `2026_10_31_160000_create_calendar_feeds_table` (partial unique index: one active feed
  per user on pgsql / sqlite).
- Model `CalendarFeed` (`DEFAULT_OPTIONS`, `issue()`, `findActive()`, `normalizeOptions()`, URLs).
- Support `app/Support/CalendarFeed/{Ics,CalendarFeedBuilder}.php`.
- Controller `CalendarFeedController` (`show` JSON, `store`, `update`, `reset`, `destroy`, `ics`).
- Routes `routes/calendar-feed.php` (required from `web.php`, one line).
- UI `components/calendar/CalendarFeedDialog.tsx` (`CalendarFeedButton`: button + dialog), mounted in
  `Pages/Calendar/Index.tsx` (import + one line). Types appended to `types/index.d.ts`.

## Test plan
- `tests/Unit/CalendarFeedIcsTest.php` — escaping, folding (UTF-8), all-day exclusivity, UTC.
- `tests/Feature/Calendar/CalendarFeedTest.php` — create / one active / reset / turn off, options,
  access re-check, 304, headers, throttle naming, project filter, My Day, focus, recurrence.
- `tests/Feature/Security/RouteThrottleTest.php`, calendar tests.
- e2e `e2e/tests/calendar-feed.spec.ts` — create in the dialog, fetch the URL, see the issue's UID /
  SUMMARY; reset → old URL 404.

## Docs to update (lead merges docs/ai)
- **ARCHITECTURE → Calendar:** "**Feed (BRAI-142):** `CalendarFeed` (`calendar_feeds`: sha256
  `token_hash` + encrypted `token`, `options`, `content_hash` / `content_changed_at`, fetch stats,
  `revoked_at`; one active per user), `CalendarFeedController` (`calendar-feed.show|store|update|reset|destroy`
  JSON; public `calendar-feed.ics` = `/calendar/feed/{token}.ics`), routes `routes/calendar-feed.php`,
  `Support/CalendarFeed/{Ics,CalendarFeedBuilder}`, UI `components/calendar/CalendarFeedDialog.tsx`
  (toolbar "Subscribe"). Tests `tests/Unit/CalendarFeedIcsTest.php`,
  `tests/Feature/Calendar/CalendarFeedTest.php`, e2e `e2e/tests/calendar-feed.spec.ts`."
- **KNOWLEDGE:** a public route in `web.php` that must not touch the session excludes the session /
  cookie / CSRF / Inertia middleware with `withoutMiddleware` (no `Set-Cookie`; calendar apps send
  none). Feed bodies must stay deterministic (DTSTAMP = `updated_at`, never `now()`), or the ETag
  changes every fetch and apps re-download everything; `Last-Modified` is when the body last changed
  (`content_changed_at`), so removals and lost access also count as a change. Google ignores the ICS
  `URL` property — the link back is also the last line of `DESCRIPTION`.
- **Changelog line (v1.42.0):** "Calendar feed: subscribe to your Brainitive dates — assigned issues,
  list tasks, My Day and focus sessions — in Apple Calendar, Google Calendar or Outlook with a private
  link (Calendar → Subscribe). Choose what's included; reset or turn the link off anytime."

## Notes
- Security review (security-reviewer agent): no Critical / High. Medium "per-IP throttle would
  429 Google's shared fetchers and not cap a leaked token" → fixed with the per-feed limit above.
- "Focus time blocks": Brainitive has no scheduled time-block model; the feed shows focus sessions
  logged with the timer (`time_entries.source = timer`, block = `created_at - minutes` → `created_at`).
  The running timer is not shown.
- Recurring list tasks use `RRULE` from the due date (monthly on the 29th–31st skips short months in
  calendar apps, while Brainitive clamps — minor).
