# Online meetings (Google Meet, Microsoft Teams) from cards, the calendar and documents

**Branch:** feat/meetings (from `release/v1.42.0`)
**Status:** done
**Started:** 2026-10-26
**Board card:** BRAI-147 (board item 321)

## Goal
"what about creating meeting directly from cards, calendar? maybe document? we will start with
google meet and microsoft teams?" — schedule a calendar event with a Google Meet / Teams link from
an issue, a document or the calendar's quick create, through the user's own connected (writable)
Google / Outlook calendar. The calendar invite notifies the attendees (no in-app notification).

## How it works for the user
- **Issue** (board popup + full page): sidebar action row → **Schedule meeting**; a **Meetings**
  block above it lists upcoming meetings (past ones behind "Show past meetings") with **Join**, and a
  menu: Copy link · Open in Google Calendar / Outlook · Create / Open meeting notes · Remove… (the
  organiser chooses **Cancel event** — deleted in the calendar, attendees get the cancellation — or
  **Only unlink**; other editors can only unlink).
- **Doc**: "…" menu → **Schedule meeting**; upcoming meetings show as a strip under the title with
  **Join** and **Create meeting notes** (then **Notes**).
- **Schedule dialog**: title (issue key + title / doc name), date, start, duration (15/30/45/60/90
  or custom 5–720 min), time zone (the user's), calendar (own active writable Google / Outlook
  calendars), video call (Google Meet / Teams / No video call — only what the calendar allows;
  Outlook personal accounts: "Teams isn't available for this account."), attendees (project members
  by name + typed addresses as chips, ≤ 50), description (prefilled with the link back). Without a
  meeting calendar it explains and links to Calendar → Add calendar. Toast: Join + Copy link.
- **Calendar quick create** ("Calendar" → Event): **Add Google Meet** / **Add Teams meeting**
  toggle + guests when the chosen calendar allows it (a start time is required for a call). Event
  chips with a call show a video icon; the event dialog shows Join + Copy link.
- **Meeting notes**: "Notes — <title> <Mon d, Y>", published, in the same project (under the doc for
  doc meetings), with meeting / when / Join / issue-or-doc link / attendees and Agenda · Notes ·
  Action items headings, linked both ways (`ContentReferences`). Created once (row lock); later
  clicks open it. "Extract action items" (BRAI-62) then works on it as on any doc.
- **History**: issue history shows "scheduled a Google Meet “…” for Thu, Nov 5 · 14:00 CET",
  "cancelled the meeting …", "removed the meeting … from this issue". Pages get the same
  `activity_logs` rows (there is no page history UI yet).

## Changes
- **Provider layer (additive):** `CalendarProvider::conferenceProviders($connection)` (Base: `[]`),
  optional `capabilities()['conferencing']`, `createEvent` accepts optional `conference`
  (`google_meet` | `teams`) + `attendees` (`[{email, name}]`); Base helpers `requestedConference()`
  (refuses a kind the calendar doesn't allow) / `requestedAttendees()` (valid, de-duplicated).
  `NormalizedEvent` gains the **optional** keys `conference_url` (https, host, no userinfo, ≤ 2048)
  + `conference_provider` (`google_meet` | `teams` | `other`), present only when the event has a call
  (`KEYS` unchanged → exact-shape tests of other providers stay green; read with `?? null`).
  - Google: `conferenceData.createRequest` (uuid requestId, `hangoutsMeet`) +
    `?conferenceDataVersion=1`, `sendUpdates=all` when there are attendees, a `pending` create is
    read once more; Meet offered when `calendarList/{id}.conferenceProperties.
    allowedConferenceSolutionTypes` has `hangoutsMeet` (or Google didn't answer). Normalized from
    the video entry point / `hangoutLink`. **Deletes now send `sendUpdates=all`** (guests get the
    cancellation, as with Outlook).
  - Outlook: `isOnlineMeeting: true, onlineMeetingProvider: 'teamsForBusiness'`, attendees
    `required`; Teams offered only when `/me/calendars/{id}?$select=allowedOnlineMeetingProviders`
    has `teamsForBusiness`; a missing join link is read once more; calendarView `$select` adds
    `isOnlineMeeting,onlineMeeting,onlineMeetingProvider`.
  - E2E provider (APP_ENV=e2e only): a link containing `meet` is writable and offers Meet + Teams
    with `https://meet.e2e.test/…`; created events are kept in the connection's `credentials`.
- **Migration** `2026_10_31_190000_create_meetings_table`: `project_id` (cascade), morph `subject`
  (`App\Models\Item` | `App\Models\Page`), `calendar_connection_id` (null on delete), `provider`,
  `external_event_id` (1024), `conference_provider`, `join_url`, `event_url`, `title`, `starts_at`,
  `ends_at` (UTC), `timezone`, `attendees` json (`[{email, name, user_id}]`), `created_by` (null on
  delete), `meeting_notes_page_id` (null on delete), timestamps; indexes (project_id, starts_at),
  calendar_connection_id, created_by, meeting_notes_page_id.
  **No soft deletes**: remove = cancel or unlink, nothing else points at the row.
- `Meeting` model, `MeetingPolicy` (view = view subject; create = update subject + own active
  calendar; delete/unlink = organiser or whoever may update the subject; cancel = organiser with
  their calendar still connected), registered in `AppServiceProvider`.
- `MeetingController` + `routes/meetings.php` (inside `projects/{project:slug}` with scopeBindings):
  `meetings.options` / `meetings.index` (ReadOnlySession), `meetings.store`
  (`throttle:20,1,meetings.store`), `meetings.destroy` (`cancel_event`), `meetings.notes`; plus
  `calendar.meetings.calendars` (quick create). Members are sent by name/id only; the server turns
  ids into addresses (`ProjectMember` rule), drops the organiser, caps at 50. Attendee addresses are
  shown only to the organiser (the notes doc lists member names + "+N guests"). `store` / `index`
  authorize the project before validating (no membership probing). Meetings are listed by subject
  and reachable only through the project the issue / doc is in now (an issue moved to another
  project keeps its meetings; `meetings.project_id` = where it was scheduled). An unpublished notes
  doc gives others a 403, never a second doc. The quick-create route got
  `throttle:30,1,calendar.connections.events.create` (it can now email guests).
- `App\Support\Meetings\MeetingCalendars`: the user's active writable calendars of providers with
  conferencing + allowed kinds, cached per connection (12 h when allowed, 10 min when not).
- `CalendarConnectionController::createEvent` (quick create): optional `conference` + `attendees`
  (only sent to the provider when present). `CalendarController` passes `conference_url` /
  `conference_provider` on external events.
- Frontend: `components/meetings/{ScheduleMeetingDialog,SubjectMeetings,EmailChipsInput,
  QuickCreateMeetingFields}.tsx`, `lib/meetings.ts`; mounts in `ItemDetailSidebar`, `Pages/Show`,
  `Calendar/Index` (quick create, chip icon, event dialog Join); history cases in `ItemDetail`.
  Types: optional conference fields on `CalendarEvent` / `NormalizedCalendarEvent`, new section at
  the end of `types/index.d.ts`.

## Setup (Google Cloud / Microsoft Entra) — nothing new required
- **Google:** the calendar connect flow already asks for `https://www.googleapis.com/auth/calendar`
  (+ `calendar.events`), which covers creating Meet links (`conferenceData`) and inviting guests. No
  scope change, no reconnect. The Google Workspace admin must allow Meet for the account
  (otherwise only "No video call" is offered).
- **Microsoft:** `Calendars.ReadWrite` (already requested) covers `isOnlineMeeting` events; no
  `OnlineMeetings.*` permission is needed. Teams links only for work / school accounts with Teams
  (personal Outlook.com accounts usually report no `teamsForBusiness` → "Teams isn't available").

## Doc notes (for ARCHITECTURE / KNOWLEDGE when the release is merged)
- ARCHITECTURE, calendar section: "Online meetings (BRAI-147): `meetings` table / `Meeting` /
  `MeetingPolicy` / `MeetingController` (`routes/meetings.php`), `Support/Meetings/MeetingCalendars`,
  UI `components/meetings/*` (mounted in ItemDetailSidebar, Pages/Show, Calendar quick create)."
- KNOWLEDGE, calendar providers: "Conferencing is optional on the contract:
  `conferenceProviders()` + `createEvent(conference, attendees)`; normalized events carry
  `conference_url` / `conference_provider` only when there is a call (https only). Google needs
  `conferenceDataVersion=1` or it ignores `conferenceData`; Meet creation can be `pending`. Graph's
  Teams needs `teamsForBusiness` in the calendar's `allowedOnlineMeetingProviders`. Meeting rows
  aren't soft-deleted; cancel goes through the organiser's own calendar only. Google deletes send
  `sendUpdates=all`."

## Review
php-reviewer, security-reviewer and frontend-reviewer ran on the first commit; all findings were
applied (throttle, guest addresses in notes, moved issues, per-list rights instead of N+1 policy
checks, FK indexes, logged conferencing lookup failures, project authorize before validation,
no duplicate notes doc, stable order, quick-create guests cleared with the hidden fields, load
errors shown, guest field label, the dialog no longer resets on a live title change).

## Test plan / results
- PHPUnit (`TEST_DB_SUFFIX=meetings`): `tests/Feature/Meetings` (2 files, 19 tests, 179 assertions)
  + `tests/Feature/Calendar` + CalendarDeletedBoard / CalendarViewPreference + Security + Pages +
  Boards + MeetingNotes + References + Mcp + Unit/CalendarFormatter → 393 passed (3366 assertions).
- e2e (`E2E_PORT=8045`): `meetings.spec.ts` (4) + calendar-providers, calendar-outlook, calendar-feed,
  meeting-notes, boards, content, document-review, document-width → 51 passed (incl. the 2 auth setups).
- `pint --test`, `npx tsc --noEmit`, `npm run build`: green.
