# Duplicate issues and tasks (BRAI-24)

**Branch:** feat/duplicate-issues-and-tasks
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-24 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-30

## State before
Pages could already be duplicated (with or without sub-pages). Issues had a server route only, and it always failed: `clone $item->parent_id` cloned an int, which throws. Task-list items had nothing.

## Changes
- `BoardController::duplicateItem`: fixed and wrapped in a transaction. It copies content and planning fields (type, priority, assignee, dates, estimate, labels, sprint), resets logged time, and never copies comments, attachments, watchers, work logs or history. Optional recursive subtasks. The flash says "Copied as KEY".
- `TaskListController::duplicateItem` + `task-lists.items.duplicate`: copy right below the original, as an open task.
- UI: `components/item/DuplicateItemDialog.tsx` (choose with or without subtasks) from the board card menu and the issue panel sidebar; "Duplicate" in the task row menu.

## Tests
`tests/Feature/Boards/DuplicateItemTest.php`. Browser: card menu → dialog → copy on the board; task row menu → copy below.
