# Plans ↔ board issues

**Branch:** feat/plan-issue-links
**Status:** done
**Started:** 2026-10-21
**Board card:** —

## Problem / goal
Plans (v1.12.0) are standalone: a workshop plan has to be retyped as issues, and the plan
goes stale as work moves on the board. Goal: rows can be linked to board issues, so a plan
can start loose and become the overview of real work.

## Approach
- A row stores `itemId` (one row per issue). The **issue is the source of truth**: when a
  plan opens, linked rows take title, dates, status, assignee and progress from their issue.
- **Write-through:** renaming a linked row or moving / resizing its bars updates the issue
  (title, `start_date`, `due_date`) on save. The server only writes a field that changed
  compared with the *stored* plan, so a stale tab never reverts a newer board change.
  Needs item update rights; otherwise the plan keeps its change and the next open resyncs.
- **Actions:** create issues from selected rows (pick a board; dates and a matching "Who"
  become start / due / assignee), link a row to an existing issue, add existing issues as
  new rows, open the issue, unlink.
- Linked rows show the issue key as a chip; Status and Who are read-only there (change them
  on the issue). Ids of other projects are dropped on save.
- `App\Support\Boards\ProjectIssues` is shared with the whiteboard issue cards (query,
  summary, progress, bulk create).

## Changes
- `ProjectIssues` (extracted from `WhiteboardController`), `PlanIssueSync`.
- `PlanDocument` (`itemId`), `PlanController` (show payload, save write-through, issues
  endpoints), routes `plans.issues(.store)`.
- Frontend: `components/plan/*` (issue map, chip, read-only cells, menu entries),
  `IssueDialogs.tsx`, `Pages/Plans/Show.tsx`.

## Test plan
Feature tests: create issues from rows (dates, assignee by name, backlog, authz), search /
refresh scoped to project, show payload, write-through of changed name / dates only, no
revert from stale documents, foreign ids dropped, viewer can't write through.

Result: 9 tests in `tests/Feature/Plans/PlanIssueLinkTest.php` (25 plan tests), whiteboard
tests unchanged after extracting `ProjectIssues`; full suite green. Checked in Chromium:
sync on open, read-only Who on assigned rows, create issues from rows, add issues as rows
(a due-only issue becomes a milestone), link a row, refresh from board, row menu.

Decision: when the issue has no assignee the plan keeps its own "Who" (e.g. "Team").

## Docs to update
ARCHITECTURE (Plans), CONVENTIONS (plan ↔ issue rules), tasks index, changelog v1.13.0.
