# Boards: Jira-style design

**Branch:** feat/board-jira-design (stacked on feat/board-jira-essentials)
**Status:** done
**Started:** 2026-10-18
**Board card:** —

## Problem / goal
The board and issue view used a custom "dense kanban" look (accent bars, bold titles,
bordered columns, segmented view switch). Goal: the familiar Jira layout and rhythm, in
Brainitive's colors and without any Atlassian branding.

## Approach (frontend only)
- **Board header:** breadcrumb (Projects / project), 24px title, settings + **Create**
  (now opens the create dialog for the first column — it had no handler), underline view
  tabs (Board / List / Timeline / Calendar).
- **Toolbar:** "Search board", avatar row = assignee filter (click to toggle), text quick
  filters, Filter popover. Removed the dead "Sort" button (no handler, no sort logic).
- **Columns:** borderless grey lanes, `TO DO 2` / `IN PROGRESS 3 / MAX 2` header, done ✓,
  working **+** (it had no handler).
- **Cards:** summary first (regular weight), parent + label lozenges, due-date lozenge
  (amber soon / red overdue), footer = type icon + key (struck through when done) · subtasks
  · comments · blocked | story points · priority · 24px assignee.
- **Issue view:** breadcrumb header (project / parent / type + key), 24px summary, quick
  actions (Attach · Create subtask · Link issue), status button coloured by category
  (`statusCategory()`: first column = To Do grey, done column = green, rest = blue),
  collapsible **Details** box, "Assign to me", created/updated footer, activity tabs
  **All · Comments · History · Work log**.

## Changes
`Boards/Show.tsx`, `Items/Show.tsx`, `components/item/ItemDetail.tsx`,
`components/board/ItemPrimitives.tsx` (`statusCategory`, `STATUS_CATEGORY_CLASSES`,
`StatusLozenge`).

## Test plan
`tsc`, build, full PHPUnit suite (no backend change); harness screenshots light/dark:
board, WIP over limit, issue view (Comments / All tabs), status button.

## Open questions
- Sorting (priority / updated / due) as a real feature, if wanted.
