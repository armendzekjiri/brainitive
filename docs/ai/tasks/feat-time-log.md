# Time log: activity on logging, timer, legacy backfill, My timesheet (BRAI-99, part 2 of 2)

**Branch:** feat/time-log (into `release/v1.38.0`)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-99 (the activities admin UI is part 1, built in parallel)

## Problem / goal
Every piece of logged work becomes a `TimeEntry` (the ledger from the v1.38.0 foundation,
`docs/ai/tasks/release-v1.38.0-billing.md`) with an activity, so the admin can bill it. Members
log on issues (Log work, focus timer) and in a weekly **My timesheet**; work logged before the
ledger existed is backfilled as `legacy` entries; nobody but workspace admins ever sees a price.

## Approach
- **One choke point:** `Item::logWork($minutes, $note, $activity, $spentOn, $source)` creates the
  `TimeEntry` (workspace / project from the item, signed-in user, `spent_on` = today in the
  user's zone unless given, snapshot `item_key` / `item_title`, `billable` from the activity) and
  keeps the `work_logged` history entry (now with the activity name). The model events do the
  `time_spent` increment — no direct increment any more.
- **Activities for logging** (`App\Support\Billing\TimeLogActivities`): active activities of the
  workspace (position, name), **required when the workspace has at least one** (nullable when it
  has none), validated against the workspace and `archived_at` (an entry may keep the archived
  activity it already has). Preselect: ticket type → `billing_activity_defaults`, then the user's
  last-used active activity in the workspace.
- **Member-facing options** `GET timesheet.activities` (`ReadOnlySession`): id, name, colour,
  pricing type + label, billable — never `rate_cents` — plus `required` and `default_activity_id`
  for `?item_id=`. Not a shared Inertia prop.
- **Timer:** the stop dialog asks for the activity + date. Validation failures inside the stop
  transaction throw, so the timer row survives and the user can fix the form.
- **Legacy backfill:** migration `2026_10_27_120000` adds `time_entries.legacy_log_id` (unique,
  nullable) and runs `time-entries:backfill-legacy`: one `legacy` entry per `work_logged` activity
  log on a live item (minutes from `metadata.minutes`, note, `spent_on` = the log's local date in
  the author's zone, project / workspace from the item, no activity, billable). Inserted with the
  query builder + `insertOrIgnore` on `legacy_log_id`: re-runs add nothing and `time_spent` never
  moves.
- **My timesheet** (`timesheet.index`, every workspace member): ISO week (Mon–Sun) of *your*
  entries, grouped by day with day / week totals; add (project you can log on + optional issue),
  edit, delete. Locked (`billing_report_id`) entries show a lock; the server refuses (422 on
  `entry`). Legacy entries: only date, activity, note and billable can change (their minutes are
  already in the issue total), and they can't be deleted. Owners edit their own unlocked entries;
  workspace admins (`manageBilling`) may edit anyone's.
- Item history: edits / deletes from the timesheet on an issue add `work_log_updated` /
  `work_log_removed` entries, shown in History + Work log.

## Changes
- Migration `2026_10_27_120000_backfill_legacy_time_entries` (+ column `legacy_log_id`).
- `Item::logWork()`, `TimeEntry` (`legacy_log_id`, `scopeForUser`), `WorkTimer::payload()` (workspace slug).
- `App\Support\Billing\TimeLogActivities`, `App\Support\Billing\LegacyTimeBackfill`,
  `App\Console\Commands\BackfillLegacyTimeEntries`.
- `BoardController::logWork`, `WorkTimerController::stop` (activity + date).
- `App\Http\Controllers\Billing\TimesheetController` + routes in `routes/billing.php`
  (section *log-time*): `timesheet.index`, `timesheet.activities`, `timesheet.items`,
  `timesheet.entries.store|update|destroy`.
- Frontend: `components/billing/ActivityPicker.tsx` (+ `useLogActivities`),
  `ItemDetail.tsx` log-work popover, `WorkTimerPill.tsx` stop dialog, `Pages/Timesheet/Index.tsx`,
  types in `types/index.d.ts`.
- `E2eSeeder`: block "v1.38.0 billing fixtures" (Programming hourly 12000, Testing hourly 4500).

## Test plan
- PHPUnit `tests/Feature/Billing/TimeLogTest.php`, `LegacyTimeBackfillTest.php`, `TimesheetTest.php`:
  logWork creates an entry with activity; activity required only when activities exist;
  archived / foreign activities rejected; default preselect; timer stop with activity; backfill
  idempotent with totals unchanged (and on an empty DB); timesheet CRUD; locked + legacy rules;
  other users' entries refused, admins allowed; `time_spent` right after edit / move / delete;
  no `rate_cents` in member responses.
- E2E `e2e/tests/time-log.spec.ts`: member logs 1h 30m with an activity (issue time updates);
  stopping the timer asks for the activity; the timesheet shows the entry, edit, delete; 375 px.

## Docs to update
ARCHITECTURE (billing / time log module), KNOWLEDGE (time ledger rules).
