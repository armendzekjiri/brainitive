# Swimlanes on boards (BRAI-87)

**Branch:** feat/swimlanes
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-87 (#item-189) — "split inside kanban": the user meant swimlanes

## What the user asked
Rows on the board decided by the user: "first high priority, then bugs, then the rest" —
as many lanes as they want, with their own conditions.

## Decisions
- **Custom lanes = ordered rules** in `board.settings.swimlanes` (`Support/Boards/Swimlanes`):
  conditions priority / type / label / assignee (0 = unassigned) / fix version (0 = none) /
  overdue. Any selected value within a condition, all conditions together; a card goes into the
  **first** matching lane, the rest into a renamable last lane. Max 20 lanes. Ids that don't
  belong to the project are dropped on save.
- **Board-level setup** (editors/admins, board settings → Swimlanes) with a default mode for
  everyone; **each person can switch** in the board toolbar (localStorage per board), incl.
  grouped modes by assignee / priority / type / fix version. Collapsed lanes are per person.
- **Dragging:** grouped lanes set the field on drop (then the column); custom lanes follow the
  card's fields — a drop into another lane only changes the column and explains why.
- Subtasks shown under their parent follow the parent's lane.
- Rules are evaluated in the browser (`resources/js/lib/swimlanes.ts`) on the already-loaded cards.

## Also fixed
A dragged card stayed half-transparent when the drop didn't reload the board (same column).

## Tests
`tests/Feature/Boards/SwimlaneTest.php`; browser check of builder, lanes, grouped drag, dark/mobile.
