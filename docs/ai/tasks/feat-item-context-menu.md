# Right-click menu on issues (GitHub #143)

**Branch:** feat/item-context-menu
**Status:** done
**Started:** 2026-11-06
**Board card:** — (GitHub issue armendzekjiri/brainitive#143)

## Problem / goal
The bulk actions of the board's list view (Status, Assignee, Priority, Type, Sprint, Due date,
Labels, Delete — `BulkEditBar`) only show after ticking checkboxes. Users expect them on a
right-click, like in a file manager, in the List view and on kanban cards.

## Approach
- `@radix-ui/react-context-menu` (2.4.1 — same `react-menu 2.2.1` as the installed dropdown
  menu, so one tiny package) + a shadcn-style `components/ui/context-menu.tsx`.
- **One menu per view, not per row:** `components/board/ItemContextMenu.tsx` wraps the list's
  `<tbody>` / the kanban board. On `contextmenu` (capture phase) it finds the row/card under the
  pointer (`data-context-item`); anything else (headers, links, inputs, portaled popovers) gets
  the browser's own menu (`stopPropagation` before Radix' bubble handler runs).
- **Targets like a file manager:** right-click on a selected row → all selected issues
  ("3 issues" header); on an unselected row → only that row, the selection stays as it is.
  Targets are kept as ids and resolved from the current props on every render, so after an edit
  the menu shows fresh values (label toggles keep the menu open).
- **Same writes as the bulk bar:** the request/toast logic moves out of `BulkEditBar` into
  `components/board/bulkItemEdit.tsx` (`useBulkItemEdit`, `BulkDeleteDialog`,
  `BulkEditOptions`); both the bar and the menu use it → `PATCH|DELETE boards/{board}/items/bulk`,
  per-item policies on the server, same toasts. (The bar has no optimistic updates; neither has the menu.)
- **Content:** Open, Copy link, Copy key · Status, Assignee, Priority, Type, Sprint (scrum),
  Due date (Today, Tomorrow, Next week = next Monday, Pick date… dialog, Clear), Labels (toggle)
  · Delete (same confirmation dialog as the bar; disabled when one of them was created by someone
  else and the user isn't a project admin). Viewers (`canEditItems` false): Open + copy only.
- **Touch:** disabled on coarse pointers (`(pointer: coarse)`): Radix opens on a 700 ms
  long-press, which fights the cards' native drag and would also start on column headers. The
  phone's own long-press menu stays.
- **Backlog page:** not included — its rows (`BacklogRow` in `Pages/Boards/Backlog.tsx`) have no
  users / priorities / types / labels props; follow-up if wanted.

## Changes
- package.json / lock: `@radix-ui/react-context-menu`.
- `components/ui/context-menu.tsx` (new), `components/board/bulkItemEdit.tsx` (new),
  `components/board/ItemContextMenu.tsx` (new), `components/board/BulkEditBar.tsx` (uses the
  shared hook), `Pages/Boards/Show.tsx` (list rows + cards get `data-context-item`, wrapping).
- `HelpModal.tsx`: v1.51.0 changelog bullet; `docs-site/guide/boards-and-issues.md`: "Right-click menu".
- e2e: `boardWithIssues` / `listRow` moved from `board-bulk-edit.spec.ts` into `e2e/helpers/boards.ts`.

## Test plan
e2e `e2e/tests/board-context-menu.spec.ts`: list row → status + priority; 2 selected → right-click
one → assignee for both (and an unselected row → only itself, selection kept); board card →
move status; viewer → only Open/Copy. Re-run `board-bulk-edit.spec.ts` + `boards.spec.ts`.

## Docs to update
ARCHITECTURE (Kanban section: the menu), KNOWLEDGE (bulk-edit section: one menu per view, touch).
