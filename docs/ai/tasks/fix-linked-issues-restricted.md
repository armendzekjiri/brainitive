# fix/linked-issues-restricted — linked issues the viewer can't open show as "Restricted" (BRAI-435)

Release v1.48.0. Brainitive card: BRAI-435. Found while building BRAI-432 (MCP link tools).

## Problem
BoardController loaded `outgoingLinks.targetItem` / `incomingLinks.sourceItem` (key, title, board)
for every card and the issue page without checking whether the viewer can open the other issue. A
link from an issue in a project the viewer isn't a member of showed its key and title.

## Change
- `App\Support\Boards\LinkedIssueVisibility::mask($items, $user)`: for links whose other issue is in
  a project the user has no access to (`User::hasProjectAccess`, memoised per project), drop the
  relation, hide the foreign key and set `restricted: true`. The link type stays, so "blocked"
  badges keep working.
- Called in `BoardController::show` (board), `cards` (live refetch), `showItem` (issue page, the item
  and its board's columns). Eager loads add `project_id` to the other issue's board.
- `ItemDetail.tsx`: restricted links render "Restricted" without a link; `ItemLink.restricted` type.
- KNOWLEDGE: MCP links section updated.

## Tests
`tests/Feature/Boards/LinkedIssueVisibilityTest.php`: board props, issue page HTML, cards JSON, and
a workspace admin still sees the title. No e2e: the visible change is one label for data a normal
journey can't create (the link picker only offers the project's issues).
