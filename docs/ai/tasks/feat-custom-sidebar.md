# Custom sidebar + better project page (BRAI-106)

**Branch:** feat/custom-sidebar (from release/v1.39.0)
**Status:** done (gate green apart from two release-branch failures, see report)
**Started:** 2026-10-24
**Board card:** BRAI-106 · contract: [release-v1.39.0.md](./release-v1.39.0.md) § 5

## Problem / goal
The project sidebar is full. Users decide what they see: hide top-level entries (Calendar, Snippets, …),
hide project groups (Lists, Boards, Pages, …), and pin the items they use. The project page shows
everything, in a clearer order.

## Approach
- **Preferences** live in `users.sidebar_preferences` (foundation column), normalised by
  `App\Support\Sidebar\SidebarPreferences`:
  `{hidden_nav: string[], projects: {"<id>": {groups: {boards…folders: bool}, pins: [{type, id}], pinned_only: bool}}}`.
  Null / missing keys = defaults (everything visible, nothing pinned).
- **Endpoint:** dedicated `PATCH /sidebar/preferences` (`sidebar.preferences`,
  `SidebarPreferencesController@update`), not `profile.preferences` (ticket 1 extends that one —
  fewer merge conflicts). Partial updates: `hidden_nav`, or `project: {id, groups?, pins?, pinned_only?}`,
  or `reset: true`. Pins are checked against the project (type, id exists there, page visible to the
  user); unknown pins are dropped silently. Returns the normalised preferences as JSON.
- **Shared prop** `sidebarPreferences` (normalised). The frontend resolves pins against the
  permission-filtered sidebar payload / project page props, so pins the user can no longer see
  never render.
- **Frontend store** `resources/js/lib/sidebarPreferences.ts`: a module-level store
  (`useSyncExternalStore`) hydrated from the prop, optimistic writes via axios, rollback + toast on
  failure. No provider, so pages wrapped in `AppLayout` (Projects/Show) can use it too.
- **Nav as data:** `components/sidebar/navItems.tsx` (`useSidebarNavItems`) — key, label, icon, href,
  active, availability. Home + Inbox are always shown. The command palette's "Go to" entries are
  generated from the same list, so hidden entries stay reachable.
- **Customize panel:** `components/sidebar/CustomizeSidebarSheet.tsx` (sheet from the left; switches
  per entry/group, "Show only pinned", pinned list, "Reset to default"). Opened from the account menu
  and a pencil button at the sidebar bottom.
- **Pins in the sidebar:** a "Pinned" group first. When the project has pins or "pinned only", the
  other groups collapse into "Show all in project →". Hover pin buttons on rows
  (`Pin <name>` / `Unpin <name>`).
- **Project page:** header (name, key, description, member avatars, quick links Health · Workload ·
  Releases), Pinned, Recently updated (5 across types, `ProjectController@show`), then Work (Boards,
  Lists, Plans), Docs (Pages tree, Whiteboards), Customer-facing (Helpdesks, Lead forms), Tools.
  Empty subsections are one compact row with the same "Add" button. Pin toggle on every card.

## Changes
- Support: `app/Support/Sidebar/SidebarPreferences.php`, `app/Support/Projects/RecentContent.php`.
- Controller / routes: `SidebarPreferencesController`, `PATCH sidebar/preferences` in web.php;
  `ProjectController@show` adds `members`, `recentlyUpdated`.
- Middleware: `sidebarPreferences` shared prop.
- Frontend: AppLayout (nav config, pins, customize entry points, palette), new files above,
  `Pages/Projects/Show.tsx` rewrite, types.

## Test plan
- PHPUnit `tests/Feature/Sidebar/SidebarPreferencesTest.php`: validation, partial merges, pins of
  inaccessible items dropped, other users' projects rejected, reset, shared prop shape, project page
  payload (recently updated, members).
- e2e `e2e/tests/custom-sidebar.spec.ts`: hide Calendar survives reload (member session, reset in
  finally), hide the Lists group, pin a page → Pinned in sidebar + project page, project page order
  and sections, reset. Fresh projects so parallel specs keep the default sidebar.

## Docs to update
ARCHITECTURE (sidebar / project page), KNOWLEDGE (preferences shape, e2e isolation, accessible names).
