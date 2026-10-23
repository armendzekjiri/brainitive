# My Day + focus timer in My Work (BRAI-74, phase 1)

**Branch:** feat/my-day-focus-timer
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-74 "My Day and focus mode" — phase 1 agreed with the owner: My Day lives
inside My Work (no separate page), plus a focus timer that logs work. Calendar time blocks /
Google Calendar writes are **out of scope** (later phase on the card).

## Problem / goal
My Work lists everything assigned to / created by the user by due date. There is no way to
say "these are the 4 things I'm doing today" independent of due dates, and no timer: work is
logged by typing "1h 30m" into an issue afterwards.

## Approach
**My Day**
- `my_day_entries(user_id, work_type 'board'|'list', work_id, plan_date, position)`, unique per
  user + work + date. `plan_date` is the user's local date (`User::localTimezone()`).
- ☀ toggle on every My Work row adds/removes it from today; the My Day section sits on top of
  My Work, reorderable (drag). Adding never touches status or due date.
- Unfinished entries of earlier days carry over **only** via a "Bring over N unfinished"
  button. Entries whose item is gone or no longer accessible are hidden (not deleted).
- Authorization: board item → `ItemPolicy::view`; list task → `TaskListPolicy::view`.

**Focus timer** (board items only — list tasks have no work log; documented, not faked)
- `work_timers(user_id unique, item_id, started_at)`: one running timer per user, enforced by
  the unique index (two tabs → second start gets a clear error).
- Start needs `ItemPolicy::update` (same as logging work). Stop opens a review (duration
  prefilled from the timestamps, editable "1h 25m", note) → `POST work-timer/stop`: in one
  transaction delete the timer row, then `Item::logWork()`. A retry finds no timer and logs
  nothing. "Discard" deletes without logging.
- Running timer is a shared Inertia prop (`activeTimer`) → a pill in the top bar on every page
  (elapsed time ticks client-side from `started_at`).
- `Item::logWork($minutes, $note)` extracted from `BoardController::logWork` so both paths log
  the same way (time_spent + `work_logged` activity).

## Changes
- Migrations: `my_day_entries`, `work_timers`.
- Models: `MyDayEntry`, `WorkTimer`; `Item::logWork()`.
- Controllers: `MyDayController` (store / destroy / reorder / carryOver), `WorkTimerController`
  (start / stop / discard); `MyWorkController::index` adds `myDay` + `carryOver`;
  `HandleInertiaRequests` shares `activeTimer`.
- Frontend: `Pages/MyWork/Index.tsx` (My Day section, ☀ toggles), `components/WorkTimerPill.tsx`
  (top bar + review dialog), types.
- CHANGELOG v1.29.0.

## Test plan
Feature tests: add/remove/reorder My Day without changing status/due; local-date entries;
duplicate add is idempotent; no access → 403 / hidden; carry-over only on request and only
unfinished; timer start/stop logs once, retry logs nothing, discard logs nothing, second start
refused, no update right → 403, elapsed across midnight; shared prop. Gate + review agents +
running app (dark mode).

## Docs to update
ARCHITECTURE (My Work: My Day, timer), KNOWLEDGE (one timer per user via unique index; list
tasks can't log work).

## Result
Built as planned and checked in the running app (dark + light): ☀ toggles, My Day order, ▶ timer,
pill on other pages, review dialog → 25m + note logged once, timer gone. Review agents found and
this branch fixed: stop/discard acting on whatever timer runs now (now `timer_id`, stale tabs
can't touch a newer timer), the pill showing an issue the user lost access to and costing 5
queries per response (now one scoped join, `item: null` when hidden), missing `work_timers.item_id`
index, a ☀ button nested inside the row link (row is now a div with a stretched title link),
client-clock skew in the suggested time (server `elapsed_seconds`), one-click discard (now only
in the review dialog, confirmed), invisible remove button on touch, effect-synced optimistic
rows, a11y labels, and test gaps (list outsider, other users' entries, unknown item, stale tab).

Not in this phase: calendar time blocks / Google Calendar, keyboard reordering of My Day.
