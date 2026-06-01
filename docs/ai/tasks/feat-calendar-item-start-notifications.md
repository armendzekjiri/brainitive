# Calendar item start-time reminders + fix browser push delivery

**Branch:** feat/calendar-item-start-notifications
**Status:** done
**Started:** 2026-06-01

## Problem / goal
Two issues reported:
1. **Browser notifications never arrive** — not via web push *and* the bell shows nothing
   for calendar reminders.
2. **Feature gap:** when a board Item (or task-list item) shown on the calendar reaches its
   **start time**, the assignee should get a notification.

## Root cause of (1)
`config('webpush.vapid.*')` was **empty** — no `VAPID_*` keys in `.env`, and `0` rows in
`push_subscriptions`. Without VAPID keys the frontend prop `push.vapidPublicKey` is empty, so
no browser can ever subscribe, and the server cannot sign push payloads. Fixed by generating
keys (`php artisan webpush:vapid`) and adding `VAPID_SUBJECT` to `.env`.

The bell never showed calendar reminders because `CalendarReminderNotification` is **push-only**
(no `toDatabase`) — by design for the Google reminders. The new item reminder delivers to
**both** the bell (database) and web push, so it shows up even before a browser subscribes.

## Approach
- **VAPID:** generate keys + set `VAPID_SUBJECT` (local `.env`). Document the gotcha in KNOWLEDGE.
- **New notification** `ItemReminderNotification` — `database` + `WebPush`. Title = item title,
  body = "Starts in N minutes" / "Starts now", URL = deep link to the board item (or task list).
  DB payload type `item_reminder` so the bell can render it.
- **New command** `app:send-item-reminders` (mirrors `SendCalendarReminders`): scans board
  `Item`s and `TaskListItem`s with a `start_date` **and** `start_time` falling inside the lead
  window (`--lead`, default 15 min), notifies the **assignee** (`assigned_to` / `assignee_id`),
  skips completed/done items, dedupes via the `sent_reminders` table.
- **Dedup table:** new `sent_item_reminders` (`user_id`, `reminder_key`, unique together).
  Chose a dedicated table over generalizing `sent_reminders` because making its
  `calendar_connection_id` FK nullable needs `->change()` on a constrained column — exactly the
  SQLite-fragile pattern KNOWLEDGE.md warns about (CI runs `RefreshDatabase` on `:memory:`).
  Key = `board_item:<id>:<startEpoch>` / `task_list_item:<id>:<startEpoch>` so rescheduling an
  item fires a fresh reminder.
- **Schedule:** `Schedule::command('app:send-item-reminders')->everyFiveMinutes()->withoutOverlapping()`.
- **Frontend:** render the `item_reminder` type in `NotificationsDropdown` (and stop it falling
  into the "moved/assigned" fallback branch).

## Changes
- Migrations: `*_create_sent_item_reminders_table` (dedup, user-scoped).
- Models: none (use existing `assigned_to` / `assignee_id`, `start_date`, `start_time`).
- Notifications: `app/Notifications/ItemReminderNotification.php` (new).
- Console: `app/Console/Commands/SendItemReminders.php` (new) + schedule in `routes/console.php`.
- Frontend: `resources/js/components/NotificationsDropdown.tsx` (+ type in `types/index.d.ts` if needed).
- Config/env: `VAPID_*` keys in `.env` (not committed).

## Test plan
- `npx tsc --noEmit`, `npm run build`, `php artisan test`, `./vendor/bin/pint` (changed files).
- Manual: create a board item assigned to me with start_date = today, start_time = now+5min;
  run `php artisan app:send-item-reminders`; expect one bell notification + one browser push;
  re-run → no duplicate (dedup).

## Docs to update
- ARCHITECTURE: Calendar + Notifications rows (item reminders, new command).
- KNOWLEDGE: VAPID keys must be set or push silently never delivers.
