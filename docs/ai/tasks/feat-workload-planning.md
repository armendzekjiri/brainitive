# Workload planning: weekly capacity, explicit allocations, overload (BRAI-75)

**Branch:** feat/workload-planning (from release/v1.37.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-75 (main-board #item-177)

## Problem / goal
Project leads want to see who has room in the coming weeks: declared availability per person
and week against the hours they explicitly planned from issues, with overload shown in hours.
Items already carry `original_estimate` / `time_spent` (minutes) and an assignee; nothing planned
work into weeks.

Non-goals: automatic assignment, spreading work over dates, payroll, ranking people,
cross-project / cross-workspace totals, a leave-management system, story points → hours.

## Decisions (the rules, also shown in the UI under "How it's calculated")
- **Project only.** Capacity, overrides and allocations belong to one project
  (`project_id`). The page says "This project only": people may have work elsewhere.
- **Weeks** run Monday–Sunday (ISO 8601) and are stored as the Monday's calendar date
  (`date`, no time, no zone), so DST never moves a week. "This week" is computed in the
  viewer's `User::localTimezone()`. Labels use the ISO week and ISO year (`2026-12-28` = W53 2026,
  `2027-01-04` = W1 2027).
- **Capacity** = a person's default hours per week for this project (`workload_capacities`,
  no row = *not set*) plus per-week overrides for leave / part weeks
  (`workload_capacity_overrides`, 0 = unavailable). Override wins over the default.
- **Allocation** = explicit minutes of one issue for one person in one week
  (`workload_allocations`, unique item + person + week). Nothing is ever spread
  automatically. The add dialog offers the **remaining estimate** `max(original − logged, 0)` as a
  suggestion the planner must take and can change.
- **Load** = allocated ÷ available, computed from integer minutes (no rounding drift);
  percentages round half up for display only. Cell states:
  `unset` (no capacity declared, no load), `unavailable` (capacity 0, nothing planned),
  `ok`, `over` (planned > available; with capacity 0 any planned time is over and the load is
  not a percentage). `over_minutes = planned − available`.
- **Estimated vs unestimated:** allocations on issues without an estimate are counted in the
  planned hours (the planner chose them) but shown separately ("no estimate"); a person's
  open assigned work is shown as *estimated remaining* + *N issues without estimate*, never as 0h.
- **Work logs subtract once.** Logged time lowers the remaining estimate; allocations are never
  reduced by logged time. Remaining is compared only with *open* allocations (this week and later);
  past weeks are history.
- **Parent / subtask rule (no double counting):** an issue and its ancestors / descendants can't
  both carry allocations from the current week on (or from the target week, when planning the
  past). Plan the parent *or* its subtasks; the server refuses the other (422). The check runs in
  the write transaction after locking the tree's root issue. Logged time counts on the issue it
  was logged on (no roll-up).
- **Source changes never overwrite allocations; they are flagged** in "Needs review"
  (open allocations only): planned more than remaining; estimate changed since it was planned
  (`estimate_snapshot`); issue done but planned in later weeks (excluded from load after the
  completion week); planned for someone while the issue is now assigned to someone else
  (unassigned issues may be planned for anyone); planned for someone no longer in the project
  (hidden from the grid, removable). Saving an allocation again (e.g.
  "Keep") refreshes its snapshot.
- **Deleted issues:** soft-deleted issues (or trashed boards) drop out of the grid and the load;
  restoring brings their allocations back. Hard deletes cascade. Issues moved to another project
  leave inert rows that no grid shows; the first plan in the new project deletes them, and every
  lookup is scoped to `project_id`.
- **Privacy:** override notes (absence reasons) and former members' names in the review are sent
  to planners only.
- **Permissions:** everyone who can open the project views the workload. **Planners** = project
  admins and workspace admins (the people who manage project settings and members,
  `ProjectPolicy::update`); only they change capacity, overrides and allocations. Allocations
  only go to people who can open the project (`ProjectMember`).
- **Concurrency:** writes carry `expected_minutes` (what the planner saw); a mismatch is a
  validation error ("changed by someone else"), and the unique key + `createOrFirst` keeps
  parallel inserts to one row.

## Changes
- Migration `2026_10_26_100000_create_workload_tables` (3 tables, additive).
- Models `WorkloadCapacity`, `WorkloadCapacityOverride`, `WorkloadAllocation`.
- Policy `WorkloadPolicy` (registered for `WorkloadAllocation`): `viewAny(project)`, `plan(project)`.
- Support `app/Support/Workload/`: `WorkloadWeeks` (week math), `WorkloadPlanner` (writes +
  rules), `WorkloadGrid` (page payload: cells, member summaries, review flags).
- `WorkloadController` + routes under `projects/{project}`: `workload.index`,
  `workload.items` (GET JSON, ReadOnlySession), `workload.capacity.update`,
  `workload.overrides.update`, `workload.allocations.update`, `workload.allocations.destroy`
  (throttled with prefixes).
- Frontend: `Pages/Workload/Index.tsx`, `components/workload/*`, `lib/workload.ts`, types;
  sidebar entry "Workload" under the project's boards (next to Releases).

## Test plan
- PHPUnit `tests/Feature/Workload/`: 20h/25h → 5h over; zero vs unset capacity vs unknown
  estimate; parent/subtask refusal; work logs once; year + DST week boundaries; permission
  isolation (viewer/editor/outsider/foreign project/foreign item/foreign user); member removal;
  deleted + completed issues; rounding; concurrent writes; "this project only" label prop.
- e2e `e2e/tests/workload.spec.ts`: planner sets capacity, plans an issue using the remaining
  suggestion, sees overload; member sees the grid read-only.

## Docs to update
ARCHITECTURE (module row), KNOWLEDGE (rules + traps).
