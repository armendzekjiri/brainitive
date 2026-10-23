# Task lists redesign + due reminders (BRAI-89)

**Branch:** feat/task-lists-redesign
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-89 "list redesign" (+ owner: "better notifications like reminder for
upcoming, today or due")

## Problem / goal
`Pages/Lists/Show.tsx` is the original March version: bordered boxes per task, All/To Do/Done
tabs, a "more" dropdown with Selects inside it for assignee/date/repeat, notes behind a tiny
icon with an explicit Save, no reordering in the UI although `task-lists.items.reorder`
exists. The due-date input shows empty for existing dates because `due_date` serializes as
ISO (`2026-03-09T00:00:00.000000Z`). Reminders only cover "starts in 15 min" for assigned
items with start date+time, computed in UTC (wrong for anyone not on UTC). No reminder for
due dates, nothing for "due today"/overdue, nothing for unassigned (personal) tasks.

## Approach
**UI (Todoist / Things style)**, one page, components under `components/lists/`:
- Composer at the top: title + chips (due date with Today/Tomorrow/Next week, assignee,
  repeat, reminder); Enter adds and keeps the composer open; Esc closes.
- Rows: round checkbox, title, meta line (due chip: overdue red / today amber / tomorrow /
  weekday / date, time, repeat, notes, reminder bell), assignee avatar right; hover: drag
  handle, quick date, more menu (duplicate, delete). HTML5 drag-and-drop like the board.
- Open tasks in manual order; done tasks in a collapsible "Completed (n)" section.
- Click a row → side sheet with all fields and notes (autosave on blur, no Save button).
- Header: name, description, progress (x of y done), watch, rename/delete.
- Completing a recurring task: toast with the next due date.

**Reminders**
- `users.timezone` (IANA, nullable), set from the browser (`Intl`) on app load via
  `PATCH profile.timezone` when it differs; `users.task_digest` (bool, default true) toggle on
  the profile page.
- `task_list_items.reminder_minutes` (nullable int: 0 / 10 / 60 / 1440 before due). Base =
  due_date + due_time, or 09:00 when no time, in the recipient's time zone.
- Recipient = assignee, else creator (`TaskListItem::reminderRecipient()`), also for the
  existing start reminders.
- `SendItemReminders`: start reminders and the new due reminders use the recipient's time
  zone; dedup in `sent_item_reminders` with the absolute timestamp in the key (recurring
  tasks get a new key per occurrence).
- New `app:send-task-digest` (every 5 min): once per user per local day, after 08:00, if they
  have open list tasks due today or overdue → one `TaskDigestNotification` (bell + push)
  "3 due today · 2 overdue", linking to My Work.

## Changes
- Migrations: `users.timezone`, `users.task_digest`; `task_list_items.reminder_minutes`.
- Models: `TaskListItem` (cast `date:Y-m-d`, fillable, `reminderRecipient()`, `remindAt()`),
  `User` (fillable/casts, `localTimezone()`).
- Controllers: `TaskListController` (validate `reminder_minutes`; assignee must be a workspace
  member; show() loads creator), `ProfileController` (timezone + digest).
- Command/notification: `SendItemReminders`, `SendTaskDigest`, `TaskDigestNotification`;
  `NotificationMessage.tsx` case for `task_digest`.
- Frontend: `Pages/Lists/Show.tsx` rewrite + `components/lists/*`; profile toggle; timezone
  sync in `AppLayout`; types.
- CHANGELOG (minor: v1.28.0).

## Test plan
Feature tests (new `tests/Feature/TaskLists/`): item CRUD + reorder + authz (viewer/outsider),
assignee outside workspace rejected, dates serialize Y-m-d, recurring completion; reminders:
due reminder in the user's time zone, fallback to creator, no duplicate, done items skipped;
digest: once per local day after 08:00, respects opt-out, counts today/overdue. Gate + review
agents; check the page in the running app.

## Docs to update
ARCHITECTURE (Task Lists: components, reminders), CONVENTIONS (user time zone for
reminders), KNOWLEDGE (app runs in UTC; reminder times are local to the recipient).

## Result
Built as planned; checked in the running app with a throwaway user (quick add, chips, recurring
completion toast, side panel + notes, digest in the bell). Review agents found, and this branch
fixed: reminders reaching people who can't open the list (now `hasProjectAccess` at send time,
memoised), old browser zone names rejected (`timezone:all_with_bc`), digest scanning every open
task each run (now narrowed to recipients who still need today's summary), missing tie-breaker
in the digest order, "1 day before" across DST (`subDay()`), `HH:MM:SS` due times bounced by
validation on Postgres, notes lost when the panel closed with Esc (flush on close), missing
error toasts, drag to the last slot, month-end repeats (server now clamps like the client),
repeating tasks without a date, half-typed years saved from date inputs, and the new
`components/lists/` files tracked as `Components/` (fixed with `git mv`).
