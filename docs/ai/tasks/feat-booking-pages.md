# Booking pages: "Book a time with me" from your real calendar availability

**Branch:** feat/booking-pages (into release/v1.45.0)
**Status:** done (built; waiting for the v1.45.0 release)
**Started:** 2026-10-27
**Board card:** BRAI-158 (https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-398)

## Problem / goal
People outside Brainitive (clients, leads, candidates, support customers) can't pick a time with
someone without email back-and-forth. A user publishes a **booking page**: `/book/{slug}` shows free
slots computed from their working hours **minus busy times in their connected calendars** (Google,
Outlook, iCloud / CalDAV, ICS links). The visitor picks a slot, leaves name + email, and the event
lands in the user's calendar with an invitation and, optionally, a Meet / Teams link.

What we already have and reuse:
- **Busy times:** `CalendarProvider::listEvents()` through `CalendarProviders::for()`, which covers
  every provider, including read-only ICS (good for "check this calendar for conflicts").
- **Writing the booking:** `createEvent()` with `attendees` + `conference` (BRAI-147, used by
  `Meeting`), so the provider sends the invite.
- **Public page rules:** lead forms (`PublicLeadFormController`: honeypot, throttle, consent
  off/optional/required, `PublicOperator` notice, embed with `frame-ancestors`), and v1.44.0 legal
  (customer pages never load analytics).
- **Emails:** the branded layout (BRAI-155), `.ics` writer `app/Support/Calendar/Ics/`.
- **Guests:** token links like the ticket portal (no account needed).

## Scope (v1.45.0)

### Host side (signed-in)
- **Several booking pages per user** (e.g. "Clients", "Emergency", "Hiring"), each with its own
  address, inside a workspace (the workspace is the operator for the legal notice and decides where
  leads/cards go). Each page can have several **meeting types**, e.g. "Intro call 15 min" and
  "Workshop 60 min".
- **Busy sources per page:** every page picks which sources block its time. The choices are each
  connected calendar (Google / Outlook / CalDAV / ICS), **Brainitive issues** with a due time
  (assigned to me), and **Brainitive task-list tasks** with a time. A new page starts with all of
  them on. Example: "Emergency" turns off the personal calendar and the tasks, so only real
  appointments block it. All-day / date-only entries don't block (per-page switch "all-day events
  block the day"). Confirmed bookings from **all** the user's pages always block, so two pages
  can't book the same hour.
- **Meeting types are the user's own:** any number per page, each with its own name and length
  (5 min – 12 h, free input, presets 15 / 30 / 45 / 60 / 90). E.g. "Quick question, 15 min" next
  to "Workshop, 45 min". Add, rename, reorder (drag), switch off, duplicate, delete.
- **Templates** (`App\Support\Booking\BookingTemplates`, like `LeadFormTemplates`): a new page
  starts blank or from a template that **pre-fills everything**: title, intro, meeting types,
  hours, rules, location kind and questions. All of it stays editable afterwards; a template is only
  a starting point (no link back). Templates: Blank · Sales / intro call (15 + 30 min) · Consulting
  (30 + 60 min, buffers) · Support / help session (15 + 30 min) · Job interview (30 + 45 min,
  questions: role, CV link) · Emergency (15 min, short notice 30 min, evenings / weekends, few busy
  sources) · Office hours (15-min slots, one weekday afternoon) · Coaching / tutoring (45 + 60 min,
  weekly limits). A meeting type can also be added from a template inside an existing page.
- Each meeting type has:
  - **Duration** (15/30/45/60/90/custom).
  - **Location:** Google Meet / Teams (only if the target calendar allows it,
    `MeetingCalendars`), phone, in person (address), or custom link.
  - **Availability:** weekly hours per weekday in the **host's timezone** (several ranges a day),
    plus date overrides (day off / other hours).
  - **Rules:** buffer before/after, minimum notice (e.g. 4 h), how far ahead (e.g. 60 days), max
    bookings per day, slot step (15/30/60 min).
  - **Target calendar:** a writable connection where bookings are created.
  - **Questions:** name + email are fixed; up to N extra fields (reuse the lead-form field types
    text / textarea / choice / phone), plus consent like lead forms.
- **Bookings list** (upcoming / past / cancelled) with cancel + "reschedule link" actions.
- **Notifications:** new / cancelled / rescheduled booking → in-app bell + push (existing
  notification categories, own preference key).
- **Where bookings show up** (per page, the user decides):
  - always: the page's **Bookings** tab, and the **Brainitive calendar** as its own source
    "Bookings" (next to Board / Lists / Calendars in the calendar filter, so it can be hidden),
    with the guest, the answers, the call link and cancel there too;
  - always, when the page has a target calendar: the event in that external calendar;
  - optional **"Also create a card"**: off, or a chosen board + column (any board the user can
    edit, including a lead form's leads board through `LeadIntake`). The card gets the guest's
    name / email / answers + the time as its due date / time, and links back to the booking.
    Cancelling the booking comments on the card (it never deletes it).

### Visitor side (public, no login)
- `/book/{slug}`: host name + avatar, meeting types → month view with available days → slots in the
  **visitor's timezone** (detected, switchable) → form → confirmation.
- The confirmation email has an `.ics` attachment plus **cancel / reschedule** links (signed token,
  no account). There's a reminder email 24 h before (scheduler; dev has none, so it's covered in
  PHPUnit).
- `/book/{slug}/embed` for websites (iframe, like lead forms).
- Operator notice + privacy link (`PublicOperator`), no analytics, honeypot + throttle per IP / email.

### Out of scope (later)
- Team pages: round-robin / "everyone must be free" across several hosts.
- Paid bookings (Stripe), group events with seats, SMS reminders.
- Routing forms ("answer → which host / type") — could build on lead-form conditions.
- MCP tools beyond "list my bookings" / "share my booking link".

## Design

### Data (PostgreSQL; migrations also run on SQLite in tests)
- `booking_pages`: id, uuid, user_id, workspace_id, `public_slug` (unique), title, intro, timezone,
  enabled, `busy_sources` json (`{connections: [ids], issues: bool, tasks: bool, all_day_blocks:
  bool}`; `connections` is `"all"` (default: also calendars connected later) or a list of ids, checked to be the host's own), `card_board_id` + `card_column_id`
  (nullable: "also create a card"; checked against the user's edit rights at save and at booking),
  settings json (branding, consent),
  timestamps, soft deletes. Several per user.
- `booking_types`: id, booking_page_id, slug (unique per page), name, description, duration_min,
  location json, `availability` json (weekly ranges + overrides), rules json (buffers, notice,
  horizon, daily_max, step), `target_connection_id` (nullable FK →
  calendar_connections, null on delete), questions json, position, enabled.
- `bookings`: id, uuid, booking_type_id, host user_id, `starts_at` / `ends_at` (UTC), guest_name,
  guest_email, guest_timezone, answers json, consent_text + consent_at, status (confirmed /
  cancelled / rescheduled), `cancel_token_hash` (sha256, like calendar feeds), external event
  id + connection id, conference_url, `unchecked_connection_ids` json (calendars that couldn't be
  read when it was booked), item_id (nullable card), cancelled_by / reason, timestamps.
  **Unique partial index** on (user_id, starts_at) where status = confirmed. This is the last line
  against double booking.

### Slot engine — `App\Support\Booking\Availability`
1. Expand the weekly rules + overrides for the date range in the **host's zone**
   (`CarbonImmutable`, DST-safe: build wall-clock times in the zone, then convert to UTC).
2. Subtract busy intervals: confirmed `bookings` of the host (all their pages, always) + the
   page's `busy_sources`: events of the chosen calendars (`listEvents` per connection), the host's
   timed issues (due date + time, assigned to them, not done) and timed task-list tasks (not done),
   read the same way `CalendarController` builds its sources. Timed only, unless
   `all_day_blocks`. Busy entries get the type's buffers added around them.
3. Cut into slots by step + duration, then drop slots under minimum notice or beyond the horizon,
   and days over the daily max.
4. **Cache** the provider busy list per connection + month for 2–5 min (the public page must not
   hammer Google / Graph / CalDAV, and ICS already caches 15 min). **If a calendar can't be read,
   it's ignored** (decided 2026-10-27): slots are computed without it, and the host is warned that
   double bookings are possible:
   - a notification (bell + push + email) "Couldn't check <calendar> for <page>: bookings may
     overlap with its events. Reconnect it". At most once per connection per day (cache flag), and
     `recordSyncError` so the calendar settings show it too;
   - a warning banner on the booking page settings while the error lasts;
   - each booking made while a calendar was skipped stores `unchecked_connection_ids`, and the
     host's "new booking" notification + bookings list say "⚠ <calendar> wasn't checked: look for
     an overlap".
- Pure function core (rules + busy → slots), so most of it is unit-tested without HTTP.
- Known limit (v1.45): recurring task-list tasks block only their first occurrence (the calendar's
  recurrence projection is private to `CalendarController`); fine for a first version.

### Booking (POST, the critical path)
In one request:
1. Validate the slot against a **fresh** busy read (skip the cache for this host + day).
2. `DB::transaction` + insert (the unique index catches a race → 409 "Just taken, pick another").
3. `createEvent` on the target calendar with attendee + conference.
4. Emails + notification + optional card.

If step 3 fails, the booking stays confirmed, the guest still gets the `.ics` email, and the host
is warned. Saying "booked" without a calendar event beats losing the booking, but tell me if you
disagree.

### Routes
- `routes/booking.php`: authed `booking-pages.*`, `booking-types.*`, `bookings.*`; public
  `/book/{slug}`, `/book/{slug}/{type}`, `GET …/slots?month=&tz=` (JSON, throttled),
  `POST …/book` (throttled + honeypot), `/book/manage/{token}` (cancel / reschedule).
- Add `/book` to analytics `EXCLUDED_PREFIXES` (customer page) and the Legal data inventory.

### UI
- Host: `Pages/Booking/Index.tsx` (pages + types + bookings tabs), type editor with a weekly-hours
  grid, a live "next free slots" preview, and a "Copy link" button. There's an entry in the
  Calendar toolbar ("Booking page") and in Profile.
- Public: `Pages/Booking/Public.tsx` (no app layout), mobile first: calendar → slots → form →
  done. Dark mode, phone width, keyboard.

### Security / privacy (security-reviewer before PR)
- The public slot API returns **only free slots**, never event titles or busy details.
- Tokens are hashed at rest. Manage links expire after the meeting ends.
- Throttle slots + book per IP and per guest email, plus the honeypot. Guest emails get no marketing.
- Owner-only policies (`BookingPagePolicy`); the target / conflict connection must belong to the host.
- Deleting a calendar connection → types fall back to "no target calendar" + warning; their
  bookings keep working.

## Tests
- **Unit:** `Availability` covers weekly rules, overrides, buffers, notice, horizon, daily max,
  a DST switch (Europe/Berlin end of March / October), visitor zone ≠ host zone, per-page busy
  sources (a deselected calendar / tasks don't block; another page's booking always does), and a
  failing calendar (ignored, recorded as unchecked).
- **Feature:** pages / types CRUD + policies; public slots hide busy details; booking happy path
  with `CalendarProviders::fake()`; race (two posts, one 409); provider failure paths; cancel /
  reschedule by token; reminders command; consent required.
- **e2e** `booking-pages.spec.ts`: the host sets hours + uses the e2e calendar provider (busy event
  hides a slot) → a guest books at phone width → the host sees it in the list + bell → the guest
  cancels by the email link. `test.skip(REMOTE, …)` only for parts needing the local fake calendar.

## Docs to update after
ARCHITECTURE (new "Booking pages" section + routes map), KNOWLEDGE (slot cache + double-booking
index), legal data inventory, HelpModal CHANGELOG v1.45.0, homepage feature cards.

## Decisions (2026-10-27)
1. Name: **Booking pages**. Several per user, each with its own busy sources.
2. A calendar that can't be read is **ignored**, and the host is warned that double bookings are
   possible (see the slot engine).
3. **The user decides where bookings go**, per page: always in the page's Bookings tab + the
   Brainitive calendar ("Bookings" source), plus optionally a card on a board + column they pick.
4. **v1.45.0 = booking pages only.** Semantic search (BRAI-150) moves to a later release.

## Built (2026-10-27)
Everything in "Scope (v1.45.0)" except these, which were left for later:
- Card per booking goes to a normal board; a lead form's leads board (own `leads` table) is not offered yet.
- MCP tools for bookings (list / share link).
- Drag-and-drop reordering of meeting types: up / down buttons instead (keyboard friendly).
