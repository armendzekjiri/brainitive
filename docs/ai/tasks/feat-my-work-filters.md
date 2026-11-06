# My Work: real filters (search, project, source, priority, labels, status, sort)

**Branch:** feat/my-work-filters
**Status:** done
**Started:** 2026-11-06
**Board card:** —

## Problem / goal
User feedback: "on my work / my day there are no filters". My Work only has the scope switch
(Assigned to me / Created by me) and the due chips (All / Today / Overdue / This week). With
~285 assigned tasks across projects nobody can narrow the list down.

## Approach
**Client-side filtering.** `MyWorkController::index` returns *every* row of the scope + due chip
(no pagination, no limit), so search / project / source / priority / labels / status / sort run in
the browser — instant, no round trip. Server only adds what the client needs to filter on:
`labels` (id, name, color) and `updated_at` on board rows (`updated_at` on list rows too).

- Filters live in the URL query (`q`, `projects`, `source`, `priority`, `labels`, `status`, `sort`),
  mirrored with `history.replaceState(history.state, …)` — the same pattern as the board's filter
  bar (`writeBoardFilters` in `Pages/Boards/Show.tsx`), so reload/back keep them. Scope and due
  chips still do an Inertia `router.get` (server params `scope` / `filter`) and carry the client
  params along.
- Project / priority / labels are matched by *slug/name* across projects (priorities and labels are
  per project; "High" in two projects is one option). Task-list tasks have no priority/labels: a
  priority/label filter hides them; "No priority" is an option.
- **Status:** Open (default) / Done / All. Today done rows are listed (dimmed, at the bottom). The new
  default hides them; a task completed with the circle while on "Open" stays visible (struck through)
  until the filter changes or the page is reloaded, so it doesn't vanish under the cursor.
- **"Done here"** counts done rows matching the other filters (ignoring status) — same meaning as
  before ("done among what you're looking at").
- **Sort:** Due date (default = current server order), Priority (order asc, none last), Recently
  updated, Project (groups by project name; other sorts keep project groups and sort within).
- **Chip counts** (All/Today/Overdue/This week, scope counts, header stats) stay server totals of the
  scope — they describe the scope, not the narrowed view; the narrowed view shows "N of M tasks"
  next to "Clear filters".
- **My Day** section is a hand-picked plan of a few tasks: filters don't apply to it.
- Phone: search stays inline; project/source/priority/labels/status/sort live in a "Filters" popover
  on small screens and inline dropdowns from `md` up.

## Changes
- Controller: `MyWorkController` eager-loads `labels:id,name,color`, adds `labels` + `updated_at` to
  the row payload (board + list, My Day rows too).
- Frontend: `Pages/MyWork/Index.tsx` filter bar + filtering/sorting; new
  `components/my-work/MyWorkFilters.tsx` (+ `lib/myWorkFilters.ts` pure helpers);
  `types/index.d.ts` `MyWorkItem.labels`, `updated_at`.
- Changelog: one bullet in v1.51.0 (`HelpModal.tsx`).

## Test plan
- PHPUnit: My Work payload carries labels + updated_at; existing scope/filter params unchanged.
- e2e `e2e/tests/my-work-filters.spec.ts`: search, project/source/priority, status Done/All, sort,
  URL persistence across reload, clear filters, empty state; plus existing `my-day.spec.ts`.

## Docs to update
ARCHITECTURE (MyWork entry), KNOWLEDGE (client-side filter decision).
