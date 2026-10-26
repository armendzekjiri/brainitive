# Online meetings (Google Meet, Microsoft Teams) from cards, the calendar and documents

**Branch:** feat/meetings (from `release/v1.42.0`)
**Status:** in progress
**Started:** 2026-10-26
**Board card:** BRAI-147 (board item 321)

## Goal
"what about creating meeting directly from cards, calendar? maybe document? we will start with
google meet and microsoft teams?" — schedule a calendar event with a Google Meet / Teams link from
an issue, a document or the calendar's quick create, through the user's connected (writable)
Google / Outlook calendar. The calendar invite notifies the attendees (no in-app notification).

## Approach
- **Provider layer (additive):** `CalendarProvider::conferenceProviders($connection)` (base: `[]`)
  + optional `capabilities()['conferencing']`; `createEvent` accepts optional `conference`
  (`google_meet` | `teams`) and `attendees` (`[{email, name?}]`). `NormalizedEvent` gains the
  **optional** keys `conference_url` (https only) + `conference_provider`, present only when the
  event has an online meeting (consumers read `?? null`; `KEYS` unchanged so exact-shape tests of
  other providers stay green).
  - Google: `conferenceData.createRequest` (uuid requestId, `hangoutsMeet`),
    `?conferenceDataVersion=1`, `sendUpdates=all` with attendees; a `pending` create is re-read
    once. Normalized from `hangoutLink` / video entry point. Allowed when the calendar's
    `conferenceProperties.allowedConferenceSolutionTypes` has `hangoutsMeet`. Scope unchanged
    (`auth/calendar` covers conference creation).
  - Outlook: `isOnlineMeeting: true, onlineMeetingProvider: teamsForBusiness`, attendees
    (`required`); allowed when the calendar's `allowedOnlineMeetingProviders` has
    `teamsForBusiness` (personal accounts usually don't → "Teams isn't available for this
    account"). Normalized from `onlineMeeting.joinUrl`. Scope unchanged (`Calendars.ReadWrite`).
  - E2E provider: a link containing `meet` is writable + conferencing (fake join URL), events kept
    in the connection's `credentials` (APP_ENV=e2e only).
- **`meetings` table** + `Meeting` model + `MeetingPolicy`; `MeetingController` (options, list,
  store, destroy = cancel in calendar or unlink, notes page) under the project routes;
  `MeetingCalendars` (writable calendars + cached conference kinds) also serves the calendar quick
  create (`calendar.meetings.calendars`).
- **UI:** shared `ScheduleMeetingDialog` + `SubjectMeetings` (list / strip) in
  `components/meetings/`; mounted in `ItemDetailSidebar` (button + section) and `Pages/Show`
  (menu entry + strip under the title). Calendar quick create: "Add Google Meet / Teams meeting"
  toggle + attendees; event chips show a video icon, the event dialog a Join button.
- **History:** `meeting_scheduled` / `meeting_cancelled` / `meeting_removed` in the item history
  (display values only); the same entries are logged on pages (no page history UI yet).

## Test plan
- PHPUnit `tests/Feature/Meetings/*` (Http::fake for Google / Graph) + `tests/Feature/Calendar`.
- e2e `e2e/tests/meetings.spec.ts` (+ existing calendar specs).
