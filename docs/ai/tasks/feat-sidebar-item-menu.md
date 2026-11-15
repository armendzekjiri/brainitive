# A real menu on docs, whiteboards and folders in the sidebar (GitHub #155)

**Branch:** feat/sidebar-item-menu
**Status:** done
**Started:** 2026-11-15
**Board card:** — (worked from the GitHub issue; the parent session tracks the card)

## Problem / goal
The ⋯ on a sidebar page-tree row (docs, whiteboards, folders) only offered "Move…", and only to
people who may edit pages. To delete, rename or duplicate a doc you had to open it and use the
page's own ⋯ menu. Give the sidebar rows a real menu — on the ⋯ and on right-click — with the
same actions, confirmations, permissions and endpoints as the page's own menu.

## Approach
- One action list per row, rendered twice: as the ⋯ dropdown (`DropdownMenu`) and as a right-click
  menu (`ContextMenu`, `components/ui/context-menu.tsx`) around the row. New file
  `components/sidebar/PageItemMenu.tsx`: `usePageItemMenuState(workspace, project, tree)` (per project,
  like `usePageTreeDndState`: context value + dialogs mounted once), `PageRowMenu` (⋯ button) and
  `PageRowContextMenu` (wraps the row).
- Actions (hidden when the user may not do them):
  - **Open**, **Open in new tab**, **Copy link** — everyone who sees the row.
  - **Rename…** — `can_edit_pages` (= `PagePolicy::update` for a page in the sidebar). Dialog → `PUT pages.update`
    `{name}` (JSON). Same rule as MCP `update_page`'s rename: an open draft follows the new title (so publishing
    it doesn't bring the old name back), a published page records a version, a "review required" page keeps the
    new title in its draft (server already does that).
  - **Duplicate** — `can_edit_pages` (= `PagePolicy::create`); `POST pages.duplicate` and, when the item has
    children, the same "Duplicate page only / with subpages" choice as the page menu; opens the copy (same as
    the page menu).
  - **Move…** — unchanged (`MovePageDialog`).
  - **Delete** — new per-node `can_delete` flag in the sidebar payload, computed in
    `HandleInertiaRequests::markEditablePages` with `PagePolicy::delete`'s rule (admins, or the author who
    may still edit) — two queries per request, no policy call per page; a PHPUnit test pins it to the policy.
    Same confirmation text as the page menu (subpage count). `DELETE pages.destroy` as JSON (new `wantsJson`
    branch → `{deleted: true}`) so the user stays where they are; deleting the open page (or one of its
    ancestors) uses the normal redirect to the parent / project.
- Right-click is off on touch screens (`pointer: coarse`), like the board's issue menu: a long press would fight
  scrolling / the native link menu; the ⋯ stays visible there.
- Right-click replaces the browser's link menu on the row, so the menu has "Open in new tab" and "Copy link".

### Other sidebar kinds (boards, lists, plans, helpdesks, lead forms)
They have no ⋯ today, only the pin button; their links keep the browser's native menu (open in new tab, copy
link address). A real menu for them needs per-kind permission flags and endpoints (board / list / plan /
helpdesk / lead-form policies, each delete with its own confirmation) — not a small consistent change.
**Follow-up**, see below.

## Changes
- Migrations: none.
- Middleware: `HandleInertiaRequests::markEditablePages` → also `can_delete` on every page node.
- Controller: `PageController::update` (rename keeps the draft title in step + records a version on published
  pages), `PageController::destroy` (`wantsJson` → JSON).
- Frontend: `components/sidebar/PageItemMenu.tsx` (new), `Layouts/AppLayout.tsx` (old `PageRowMenu` replaced,
  rows wrapped in the context menu, provider in `ProjectContent`), `types/index.d.ts` (`Page.can_delete`).

## Test plan
- PHPUnit `tests/Feature/Pages/SidebarPageMenuTest.php`: `can_delete` matches `PagePolicy::delete` for author /
  editor / viewer / admin (nested pages too); rename via `pages.update` JSON (draft title follows, version
  recorded, review-required keeps it in the draft, viewer 403); delete JSON (204/200 without redirect, viewer
  and non-author editor 403).
- e2e `e2e/tests/sidebar-item-menu.spec.ts`: rename from the ⋯; duplicate + delete from right-click with the
  confirmation; delete of the open page lands on the project; a viewer (member) sees Open / Copy link only.

## Docs to update
- ARCHITECTURE → Pages (sidebar row menu), KNOWLEDGE → "Sidebar page menu" note under "Moving pages".
- docs-site guide page for pages / sidebar.

## Follow-ups
- **Multi-select (shift-click) + bulk delete in the sidebar tree** — out of scope for #155.
- **⋯ / right-click menu for boards, lists, plans, helpdesks and lead forms** (rename / delete need per-kind
  permission flags in the sidebar payload).
- Rename of the page that is open in another tab doesn't update that tab's title until it reloads (the page tree
  has no live channel, same as moves).
