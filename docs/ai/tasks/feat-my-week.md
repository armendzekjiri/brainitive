# My Week: a free-form week planner on My Work (with linked tasks)

**Branch:** feat/my-week (merged locally into `release/v1.53.0`; nothing pushed until the owner says so)
**Status:** done
**Started:** 2026-11-15
**Board card:** none yet (Brainitive MCP not connected in this session)

## Problem / goal
My Day plans one day, and only with existing tasks. People want to plan the **week** on paper-like
day columns (WeekToDo style): type a free line on any day ("Call the bank", "Prep demo") and, where it
helps, link a real issue / list task instead of a plain line. Ticking the line should tick the task.

## Approach
My Day already stores `my_day_entries(user, work_type, work_id, plan_date, position)` — a week is the
same data over 7 dates. So:

- **Free-form lines = a third entry kind `note`** in `my_day_entries` (`work_id` null, own `title`,
  `color`, `completed_at`). No new table; the plan of a date stays one ordered list of entries.
- **Linked lines** stay `board` / `list` entries; their circle is `WorkDoneToggle` (completes the real
  task). Planning never touches due date, status or assignee (same rule as My Day).
- **Week view** = Day | Week toggle in the My Day section of My Work. URL `?plan=week&week=YYYY-MM-DD`
  (any date → its Monday). Week starts Monday, 7 columns (stacked on phones). The server sends `myWeek`
  only when `plan=week`, so the default page costs nothing extra.
- **Writes by entry id** (new `my-day.entries.*` routes): add (note or work) on any date, update (title,
  color, done, move to date + position), delete, reorder a date. Entry ids belong to the user (404
  otherwise). Moving a linked task onto a date that already has it merges (drops the moved row).
- **Linking:** in the add row, `#` or `/` + text searches issues + list tasks (new JSON endpoint
  `my-day.search` on the search index, same visibility); or drag a My Work row onto a day.
- **Day view** shows notes too (merged by position) and gets an "Add a line" input.
- **Carry-over** ("Bring over N unfinished") also *moves* unfinished notes of the last 30 days onto today
  (tasks are copied as before; moving notes avoids offering the same note again tomorrow).
- **Everything else ignores notes on purpose:** App API / mobile (`forDate`, `carryOverCandidates`),
  offline snapshot, `ChangeFeed`, calendar feed and Today screen all go through `MyDayPlan::resolve()`
  which drops entries without work → unchanged payloads. Notes in the apps = later phase.

Date bounds: `plan_date` within ±1 year of the user's today.

**Layout (owner's call, 2026-11-15):** Monday–Friday as five columns on top; Saturday, Sunday and a wide
**Someday** (lines without a day, `plan_date` null) below. Day names run vertically up each column's left
edge (paper-planner style) with a thin progress bar beside them. Phones stack the days.

Defaults chosen (owner didn't pick): 7 days, Monday start, dropping a linked task only plans it.

## Changes
- Migration `2026_11_15_100000_add_notes_to_my_day_entries`: `work_id` nullable, `title` (string 500),
  `color` (string 20), `completed_at` (timestamp) — all nullable. Unique index unchanged (Postgres / SQLite
  treat NULL work ids as distinct).
- Model `MyDayEntry`: `NOTE`, `KINDS`, `COLORS`, fillable + casts, `isNote()`.
- `Support/MyDay/MyDayPlan`: `add(…, ?date)`, `addNote()`, `forRange()` (entries incl. notes, resolved),
  `weekStart()`, `moveTo()`, `reorderDate()`, carry-over of notes.
- `MyDayController`: `storeEntry`, `updateEntry`, `destroyEntry`, `reorderEntries`, `search`.
  Routes `my-day.entries.store|update|destroy|reorder`, `my-day.search`.
- `MyWorkController`: `myDay.rows[*].entry_id/position`, `myDay.notes`, `myDay.carryOverCount` incl. notes,
  `myWeek` (when `plan=week`), `plan` prop.
- Frontend: types `MyDayNote`, `MyWeekPlan`, `MyWeekEntry`; `components/my-day/MyWeekBoard.tsx`
  (columns, drag, inline add / edit, link picker), `components/my-day/PlanLineInput.tsx`,
  `MyDaySection` (Day | Week toggle, notes, add line), My Work rows draggable onto days.

## Test plan
- PHPUnit `tests/Feature/MyDay/MyWeekTest.php`: note CRUD on any date; link work on a date (auth: can't
  link work you can't open → 403/404); move between days + merge duplicate; reorder; other user's entry
  → 404; date bounds 422; week payload (Monday start, user zone, notes + linked, lost access hidden);
  app API `me/my-day` + `forDate` callers don't see notes; carry-over moves notes; search endpoint only
  returns visible issues / tasks.
- e2e `e2e/tests/my-week.spec.ts`: switch to Week, add a free line on a day, tick it, link an issue via
  `#search`, tick the linked line → issue done, move a line to another day, reload persists.

## Docs to update
ARCHITECTURE (MyWork → My Day / My Week), KNOWLEDGE (notes ignored by apps / resolve()), HelpModal
CHANGELOG v1.53.0, Welcome feature card, docs-site guide page for My Work.

## Later phases (not this branch)
- Pin personal task lists / capture inbox next to Someday.
- Convert a note into an issue / task (reuse capture conversion).
- Notes in the App API / mobile + offline; calendar events faintly in day headers; MCP "plan my week".

## Open questions
- 5 vs 7 days, week start, and "also set due date on drop" — defaults above, revisit after testing.
