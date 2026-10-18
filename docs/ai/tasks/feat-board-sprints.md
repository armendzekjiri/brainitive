# Boards: Scrum — sprints, backlog, reports (phase 2)

**Branch:** feat/board-sprints (stacked on feat/board-jira-design)
**Status:** done
**Started:** 2026-10-18
**Board card:** —

## Problem / goal
`boards.type` already allows `scrum`, but nothing uses it: no sprints, no backlog, no
burndown/velocity. Goal: Jira's Scrum workflow on top of the existing board.

## Approach
- **Opt-in per board:** board settings → "Use sprints" (`type = scrum`). Kanban boards are
  unchanged. Turning sprints off is blocked while a sprint is running.
- **Data:** `sprints` (planned → active → completed, one active per board; committed /
  completed points + issues snapshotted; final burndown stored on completion).
  `items.sprint_id` (null = backlog) + `items.backlog_position` (rank in backlog/sprint
  lists, independent of the column `position`). Subtasks always follow their parent.
- **Backlog page** (`boards.backlog`): active + planned sprints and the backlog, drag & drop
  ranking / moving (`boards.backlog.rank`), create / edit / delete / start sprint.
- **Board** (Scrum): shows only the active sprint; sprint bar with goal, days left and
  **Complete sprint** (open issues → next planned sprint or backlog). New cards join the
  active sprint (Jira behaviour); the backlog can create straight into the backlog/a sprint.
- **Reports page** (`boards.reports`): burndown (ideal vs. remaining, points or issue count
  when nothing is estimated) and velocity (committed vs. completed, last 7 sprints).
- History: `sprint_id` is tracked by `ItemObserver` ("Sprint: None → BRAI Sprint 3");
  pure re-ranking is written with the query builder (no history noise).

## Changes
- Migrations: `create_sprints_table`, `add_sprint_columns_to_items_table`.
- Models: `Sprint` (+ factory), `Board::sprints()/activeSprint()/isScrum()`,
  `Item::sprint()/moveToSprint()`; `App\Support\Boards\SprintReport`.
- Controllers: `SprintController` (new); `BoardController` (type setting, sprint filter,
  sprint for new cards).
- Frontend: `Boards/Backlog.tsx`, `Boards/Reports.tsx`, shared board header/tabs,
  sprint bar in `Boards/Show.tsx`, toggle in `Boards/Edit.tsx`, types.

## Test plan
`tests/Feature/Boards/SprintTest.php`: lifecycle rules (one active, start needs issues,
only planned deletable, complete moves open issues + subtasks), ranking/moving + history,
board filtering on Scrum vs. Kanban, new-card sprint assignment, authz (viewer), burndown
numbers (points + issue fallback), velocity, stored burndown after completion.

Result: 14 tests in `SprintTest` (full suite 94 green). Backlog drag & drop, sprint bar,
burndown crosshair/tooltip and velocity checked in the Vite harness, light + dark. Chart
colours validated with the dataviz validator (blue vs. grey: CVD ΔE 15.6–15.9, contrast
≥ 3:1 on both surfaces; grey intentionally fails the categorical chroma floor — it is the
de-emphasis role, not a series hue).

## Docs to update
ARCHITECTURE (Kanban module), CONVENTIONS, KNOWLEDGE.

## Open questions
- Phase 3: epics, swimlanes, bulk edit, keyboard shortcuts.
