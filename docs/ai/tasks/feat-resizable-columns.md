# Resizable columns in the board Timeline and List views

**Branch:** feat/resizable-columns
**Status:** done
**Started:** 2026-11-06
**Board card:** —

## Problem / goal
User feedback: on the board **Timeline** the "Items" column is fixed at 288px, so long titles are
cut off ("just like we have in plans" — `PlanChart` has a draggable splitter). On the **List**
view, Title takes all spare space while Parent / Status / Priority / Assignee / Due date / Story
points / custom field columns have no horizontal padding and get squeezed: headers run together
("Parent Status"), "Medium" collides with the assignee avatar. A tiny vertical scrollbar with
▲▼ arrows also shows at the right of the board's view tabs.

## Approach
- New shared hook `resources/js/hooks/useResizableColumns.ts`: per-column widths
  (`min` / `max` / `default`, where `default: null` = "auto, fills the rest"), pointer drag
  (rAF-throttled, pointer capture), ←/→ keyboard (Shift = bigger step), double-click reset, and
  `handleProps(key, label)` for a `role="separator"` handle (`aria-orientation="vertical"`,
  `aria-valuenow/min/max`, `cursor-col-resize`). Widths persist in `localStorage` (try/catch)
  on release / key / reset, never on every move.
- `PlanChart` keeps its own splitter: its width is part of the **plan document**
  (`settings.gridWidth`, shared with everyone, goes through the editor/undo pipeline). The board
  widths are a personal convenience, so the hook is the localStorage-backed variant of the same
  idea (drag the right edge, clamp to min/max).
- **Persistence: localStorage, per user per board** — keys
  `board-<id>-u<userId>-timeline-widths` and `board-<id>-u<userId>-list-col-widths`.
  Saved views are URL params (`?hide=…&sort=…`) compared on normalized params; putting pixel
  widths there would make every drag a "modified view". Widths therefore apply to the board in
  every saved view.
- **Timeline:** sidebar width from the hook (min 160, max 60% of the window, default 288); every
  hard-coded `288` becomes the live width. Handle on the right edge of the sticky "Items" header.
  Phones: no handle, width = min(288, 60% of the window).
- **List:** `table-layout: fixed` + `<colgroup>`. Title defaults to *auto* (fills the spare
  space like before) with a 200px floor; other columns get fixed defaults with minimums so nothing
  overlaps. Once Title is dragged it becomes fixed and a trailing filler column takes the spare
  space. Table `min-width` = sum of columns → horizontal scroll inside the view instead of
  squeezing. Every cell gets `px-3` and truncates (assignee name ellipsis next to the avatar);
  titles still wrap. Checkbox column isn't resizable. Phones: no handles.
- **Stray scrollbar:** `BoardHeader` tab strip is `overflow-x-auto` (which makes overflow-y auto
  too) and the tabs use `-mb-px` → 1px vertical overflow → tiny ▲▼ scrollbar. Fix:
  `overflow-y-hidden`, drop `-mb-px`.

## Changes
- Frontend: `hooks/useResizableColumns.ts` (new), `Pages/Boards/Show.tsx` (ListView, GanttView),
  `components/board/BoardHeader.tsx` (tab strip), `components/HelpModal.tsx` (v1.51.0 bullet).
- No backend changes.

## Test plan
- `e2e/tests/board-resizable-columns.spec.ts`: Timeline handle drag → wider, reload → kept,
  double-click → reset; List Title handle narrower → Story Points header moves left, reload → kept;
  ←/→ on a handle changes `aria-valuenow`.
- Re-run `board-bulk-edit.spec.ts`, `boards.spec.ts`; `npx tsc --noEmit`.

## Docs to update
- KNOWLEDGE: board column widths (localStorage, per user/board, not in saved views; why not the
  PlanChart pipeline; the overflow-x-auto + -mb-px scrollbar trap).
- ARCHITECTURE: hook pointer.
