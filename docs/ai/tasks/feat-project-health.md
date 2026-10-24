# Project health report (BRAI-64)

**Branch:** feat/project-health (from release/v1.37.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-64 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-166

## Problem / goal
A project member wants to see what is late, blocked or coming up, and to write a dated status
update backed by evidence. Today that means scanning boards and plans by hand. The health view
computes deterministic work metrics live, lists the issues behind every number, and lets an
editor write and save a status update (optionally drafted by the workspace's AI) with a
health status they choose themselves.

## Approach
- **Live metrics, no cache.** `App\Support\ProjectHealth\HealthMetrics` computes everything per
  request from a fixed number of queries (one aggregate query for the counts, one query per
  list with a `LIMIT`, one for blocking links, one for plans). Nothing is cached, so there is
  no cache to scope per permission.
- **Metric definitions** (documented in the UI under "How these numbers are counted"):
  - *Issue* = every non-deleted issue or subtask on a non-deleted board of the project
    (subtasks count as their own work item).
  - *Completed* = `completed_at` set **or** in a done column. *Open* = not completed.
  - *Today* = the date in the chosen time zone (default: the viewer's `localTimezone()`).
  - *Overdue* = open, `due_date` < today (due today is **not** overdue).
  - *Due in the next 7 days* = open, today ≤ `due_date` ≤ today + 6.
  - *No due date* = open without a due date (never counted as on time or late).
  - *Unassigned* = open without assignee.
  - *Blocked* = open issues with a `blocks` / `is_blocked_by` link whose blocker is open, not
    deleted, and in a project the viewer can open. A pair stored both ways counts once.
    `relates_to`, labels or text never count as blocked.
  - *Completed in period* = `completed_at` inside the chosen period (local dates, converted to
    UTC bounds). Issues in a done column without `completed_at` count as completed but have no
    completion date → not in any period.
  - *Plans:* milestones (upcoming in the next 30 days / missed: date before today and not done)
    are shown separately. Plan rows linked to an issue (`itemId` of a live issue of this
    project) take the issue's state and are **not** counted again; unlinked rows with bars are
    "plan rows not on a board".
  - **Unknown:** no boards → all issue metrics are Unknown; open issues but none with a due date
    → overdue / due soon are Unknown; no plans → milestones Not available. There is no
    computed health score at all; the status is chosen by a person.
- **AI narrative (optional, on demand).** `HealthSnapshot` builds a bounded snapshot (counts +
  at most 10 issues per list, titles cut to 140 chars, only this project's issues and plans;
  blockers in other projects appear without id/title). `HealthNarrator` sends it to the
  workspace's BYOK OpenAI-compatible endpoint (same settings + `OutboundUrl` SSRF guard as
  `AiController`, but its own class — `AiController` stays untouched for BRAI-62). The project
  data goes in a delimited JSON block and the system prompt says it is data, never
  instructions. The answer must be JSON `{progress, risks, next_steps}` of claims with
  `sources`; ids are validated against the snapshot's ids, invalid ids are removed, and claims
  without a valid source are dropped (count returned).
- **Saved reports.** `project_health_reports` stores the chosen status, the edited sections
  (claims + source refs), an optional summary, the metric numbers, the period, time zone and
  `as_of`, and *only ids* for listed issues (never titles). On save, every source ref is
  checked against the live project (`item:<id>` of a live issue of this project, `plan:<id>`
  of a live plan, `metric:<key>` of a known metric) — an unknown ref is a 422. On read, refs
  are resolved for the *reader*: deleted / moved / inaccessible sources show as
  "Unavailable source" without title or link.
- **Permissions** (`ProjectHealthReportPolicy`, registered explicitly): view health + reports =
  project access; draft with AI + save = `createBoards` on the project (editors, admins);
  delete = author or project/workspace admin.

## Changes
- Migration `2026_10_26_110000_create_project_health_reports_table` (additive).
- Model `ProjectHealthReport` (+ factory), `Project::healthReports()`.
- Policy `ProjectHealthReportPolicy` + `Gate::policy` in `AppServiceProvider`.
- Support: `app/Support/ProjectHealth/{HealthMetrics,HealthSnapshot,HealthNarrator,ReportSources}.php`.
- Controller `ProjectHealthController` (`projects.health`, `projects.health.narrative`,
  `projects.health.reports.store`, `projects.health.reports.destroy`); routes in the
  scope-bound `projects/{project:slug}` group; throttles with route-name prefixes.
- Frontend: `Pages/Projects/Health.tsx`, `components/health/*`, types in `types/index.d.ts`,
  nav entry "Health" next to "Releases" in `AppLayout`.

## Test plan
- PHPUnit `tests/Feature/ProjectHealth/ProjectHealthTest.php`: fixture totals; due today not
  overdue; timezone boundary (UTC vs Pacific/Kiritimati); completed blockers don't count;
  both-direction link counts once; cross-project inaccessible blocker excluded; linked Gantt
  rows count once; missing dates → Unknown; no boards → Unknown; deleted board/items
  excluded; AI unavailable → metrics still render, narrative 422; AI mocked: snapshot excludes
  other projects, prompt marks data as data, false ids removed; save rejects false source
  ids; reader without source access sees "Unavailable"; policies (viewer can view, can't save;
  outsider 403); query count on a large seeded project is bounded and constant.
- e2e `e2e/tests/project-health.spec.ts`: open Health from the project nav, metrics match
  created issues, "AI not configured" state, save a report with a manual status, see it in
  the history, member (editor) can read it.

## Docs to update
ARCHITECTURE (module row), KNOWLEDGE (metric definitions / no score / redaction decision).

## Open questions
- None blocking. Scheduled emails, forecasts and cross-workspace views are out of scope (v1).

## Review outcome (php / frontend / security reviewers)
- Saved reports keep blockers in other projects as `external_blockers` (a count), never their
  ids; `present()` drops list entries the reader can't open (claim sources stay as
  "Unavailable source").
- Provider errors are logged and shown generically (the exception text can carry the endpoint).
- `<project_data>` tag variants are neutralised with a regex; a non-string AI `content` is a 422.
- Authors delete their own report only while they still have write rights.
- Empty points after `strip_tags` are rejected; client ids for points; arrow keys in the status
  radio group; editor source chips open in a new tab so an unsaved draft isn't lost.
