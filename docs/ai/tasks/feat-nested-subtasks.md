# Nested subtasks (BRAI-25)

**Branch:** feat/nested-subtasks
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-25 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-31

## Decision
Up to 3 levels (issue → subtask → sub-subtask), agreed with the user. Unlimited nesting would make boards and Gantt rows unreadable.

## Changes
- `Item::MAX_DEPTH`, `depth()`, `subtreeHeight()`, `descendantIds()`. Store/update validation: a parent's depth plus the moved subtree's height must stay ≤ 2; loops are refused (existing walk).
- Whole subtree on: delete (soft deletes don't cascade, fixed), `moveToSprint`, move to another board (also leaves the sprint and detaches from a parent on the old board), duplicate (already recursive).
- `moveItem` `with_subtasks`: moving into a done column can complete all open descendants.
- UI: `lib/itemTree.ts`; issue panel parent path, subtasks section up to depth 1, valid parents only (indented); `CompleteSubtasksDialog` in the panel and on board drag; create dialogs and `boards.root-items` offer first-level subtasks as parents.

## Tests
`tests/Feature/Boards/NestedSubtasksTest.php`. Browser: sub-subtask created, parent path, no subtasks at level 3, drag a parent to Done → "Complete all" moves the tree.
