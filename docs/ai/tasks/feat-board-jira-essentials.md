# Boards: Jira essentials (phase 1)

**Branch:** feat/board-jira-essentials
**Status:** done
**Started:** 2026-10-18
**Board card:** —

## Problem / goal
The board has views, filters, types, links and subtasks, but the parts that make Jira feel
like Jira are missing or only half-built:
- `ActivityLog` + `Item::activities()` exist but nothing ever writes a log — no item history.
- `board_columns.wip_limit` is editable but never shown or checked on the board.
- `original_estimate` / `time_spent` (minutes) exist on `items` without any UI.
- Filters live in React state only — not shareable, lost on reload; no quick filters.
- `BoardController::show()` / `showItem()` eager-load every item's comments (+ replies),
  attachments, links and watchers → huge payload on big boards. The card's
  `comments_count` badge is never filled (no `withCount`).

## Approach
1. **History** — `ItemObserver` logs `created` and field-level `updated`/`moved` diffs with
   display values resolved at write time (column, assignee, priority, type, parent, board).
   Controller logs what isn't a model update: labels, comments, links, attachments, work
   logged. Covers MCP tools for free.
2. **Lazy details** — `GET boards/{board}/items/{item}/details` returns comments,
   attachments and history. Board/full-page payloads drop comments + attachments and get
   `comments_count` / `attachments_count`. `useItemDetail` fetches on open and after
   mutations.
3. **WIP limits** — column header shows `n / limit`, highlighted when over (soft limit, like
   Jira; moving is still allowed).
4. **Time tracking** — estimate + time spent in the item sidebar, "Log work" (e.g. `1h 30m`)
   adds to `time_spent` and writes a history entry; progress bar.
5. **Filters in the URL** + quick filters: *Only my issues*, *Recently updated* (7 days),
   *Due soon / overdue*, *Blocked*.

## Changes
- Observer: `app/Observers/ItemObserver.php` (`#[ObservedBy]` on `Item`).
- Controller / routes: `BoardController` (details, logWork, log calls, slimmer loads).
- Frontend: `Boards/Show.tsx` (columns, filters), `components/item/ItemDetail.tsx`
  (lazy details, history tab, time tracking), `types/index.d.ts`.

## Test plan
Feature tests in `tests/Feature/Boards/`: history entries for create / update / move /
assign / labels / comment / link / work log, details endpoint (+ authz), board payload
without comments, comments_count, log-work validation and parsing, WIP data.

Result: 16 tests in `tests/Feature/Boards/` (history per action, durations, work-log
validation + authz, details endpoint + authz, slim payload, full item page, member-profile
scoping); full suite green. Board + item popup checked in the Vite harness (WIP over-limit,
quick filter `?quick=blocked`, History tab, time tracking).

Also fixed: `memberProfile` would have shown activity from other workspaces once logs were
written; column menu entries ("Set WIP limit", "Edit column", "Delete column") had no
handlers — now "WIP limit…" dialog + "Edit columns…" link to board settings.

## Docs to update
ARCHITECTURE (Kanban module), CONVENTIONS (activity logging), KNOWLEDGE.

## Open questions
- Phase 2: sprints + backlog + burndown. Phase 3: epics, swimlanes, bulk edit, shortcuts.
