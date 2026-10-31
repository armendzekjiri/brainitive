# Move pages, whiteboards and folders (BRAI-431, GitHub #130)

**Branch:** feat/move-pages (from release/v1.48.0)
**Status:** in-progress
**Started:** 2026-10-31

## Problem / goal
Dropping a doc onto a folder in the sidebar doesn't put it inside. The old drag only reorders a
type group (`DraggableSidebarGroup` splices on `dragenter`, saves the group through
`pages.reorder`), so the parent never changes. `pages.reorder` also takes any `parent_id`, with no
cycle check and no slug de-duplication. The unique key `(project_id, parent_id, slug)` means a move
into a folder that already has the same slug fails with a 500.

We want two Jira-style ways to move an item:
1. a **Move…** command (sidebar row ⋯ menu + page header ⋯ menu) that opens a searchable folder
   picker with the full destination path;
2. **drag & drop** in the sidebar with clear zones (before / after / inside), a precise indicator,
   auto-expand after 600 ms, and a confirmation when the parent changes (Cancel = nothing moved).

## Approach

### Backend: one move path
- `App\Support\Pages\PageMover::move(Page $page, ?Page $parent, ?int $beforeId, ?int $afterId)`:
  - locks the project row (one tree change at a time per project), re-reads the page;
  - rejects a parent in another project, the page itself or one of its descendants (cycle) — 422;
  - `before_id` / `after_id` must be a sibling in the target parent; neither = append;
  - renumbers the target parent's children 0..n (ordered `type desc, position, id`) and, if the parent
    changed, the old parent's children too (query builder, no events);
  - de-duplicates the slug in the new parent;
  - saves the page through the model (search / observers see it) without bumping `updated_at`.
- `POST pages/{page}/move` → `PageController::move` (`pages.move`), JSON:
  `{ parent_id: int|null, before_id?: int, after_id?: int }`. Authorize `update` on the page; the
  parent must be visible to the user (`view`). Returns `{ id, parent_id, position, path }`.
- `PageController::update` with `parent_id` goes through `PageMover` too.
- `pages.reorder` keeps same-parent bulk reorders only; a changed `parent_id` is a 422 now.
- MCP `update_page`: optional `parent_uuid` (null / "" = top level), same mover + checks; a move
  alone works for whiteboards and review-required pages (no content change).
- Shared prop: each sidebar project gets `can_edit_pages` (PagePolicy `create`) so the sidebar
  shows grips and Move… only to editors.

### Frontend
- `lib/pageTree.ts`: pure helpers — `findPageNode`, `pagePath`, `isDescendantOrSelf`,
  `moveTreeNode` (optimistic tree), `folderDestinations`.
- `components/page/MovePageDialog.tsx`: dialog with search, flat folder tree + "Top level",
  disabled rows (this item / inside this item / current location), destination path, Move button;
  combobox-style keyboard (↑ ↓ Enter, Esc).
- `components/sidebar/PageTreeDnd.tsx`: context per project — dragged node, drop target
  (row + zone), confirmation `AlertDialog`, optimistic tree, save via `pages.move`.
  Zones from the pointer over the row header: top 25% before / bottom 25% after / middle inside on
  folders; halves on other rows. Before/after only next to rows of the same kind (doc / whiteboard
  / folder — the sidebar groups them), inside only on folders, never self / descendants. Root group
  headers ("Pages", "Whiteboards", "Folders") = top level. Indicator: insertion line, or a ring
  plus a path label for "inside" (and a path label when before/after changes the parent).
  Collapsed rows with children expand after 600 ms hover.
- Sidebar rows get a ⋯ menu with Move… (editors only). Page header ⋯ menu gets Move… (`can.update`).
- After a move: `router.reload({ only: ['currentWorkspace', 'breadcrumbs'] })`; the sidebar has
  no live channel for the page tree (other users see it on their next navigation).

## Test plan
- PHPUnit `tests/Feature/Pages/PageMoveTest.php`: into a folder, to top level, before/after
  positions in old and new parent, cycle rejected (self + descendant), other project rejected,
  viewer / outsider forbidden, slug collision de-duplicated, reorder endpoint rejects parent change,
  `update` with parent goes through the mover; MCP `parent_uuid` in `PageMcpToolsTest`.
- e2e `e2e/tests/page-move.spec.ts`: reporter's case via drag (confirm → inside; Cancel → stays),
  Move… from sidebar menu and from page header, folder can't go into its own subfolder (disabled in
  dialog), reorder within a folder without confirmation.

## Docs
ARCHITECTURE (Pages module: mover, route, dialog, DnD), KNOWLEDGE (drop zones / e2e drag notes,
reorder endpoint restricted, slug unique per parent).
