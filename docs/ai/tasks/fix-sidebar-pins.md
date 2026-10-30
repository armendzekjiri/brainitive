# Sidebar pins: "Show only pinned" off restores the groups, pins can be reordered

**Branch:** fix/sidebar-pins (from release/v1.48.0)
**Tickets:** BRAI-285, BRAI-284
**Status:** done (merged release/v1.48.0 incl. BRAI-286 into the branch; focused tests green)
**Started:** 2026-10-30

## Problem / goal
- **BRAI-285:** after "Show only pinned" was turned off, the project sidebar still showed only the
  Pinned group until every pin was removed. `ProjectContent` (AppLayout) hid the normal groups
  whenever *any* pin existed (`showPinned = pinned.length > 0 || pinned_only`, `shows = !showPinned && …`),
  so the switch only mattered for a project without pins.
- **BRAI-284:** pins keep the order they were pinned in; there is no way to reorder them.

## Approach and changes
- **BRAI-285 (AppLayout `ProjectContent`):** split the two meanings. The Pinned group shows when there
  are pins or `pinned_only` is on; the groups are hidden only when `pinned_only` is on (or the group
  itself is hidden). "Show all in project" shows when `pinned_only` is on or a group is hidden.
  Customize sheet hint text stays accurate ("Collapse the groups into a link to the project page").
  The project page never hid groups, nothing to change there.
- **BRAI-284:**
  - `lib/sidebarPreferences.ts`: `movePin(projectId, pin, toIndex)` / `reorderPins(projectId, pins)`
    — saves the full `pins` array through the existing `PATCH sidebar.preferences` (backend already
    keeps the order, dedups, caps at 50, drops invisible pins; no backend change).
  - Sidebar Pinned group: native HTML5 drag & drop with a grip handle, the same pattern as the
    page tree (`DraggableItem`; the repo has no dnd library). Order is applied optimistically while
    dragging and saved once on drop.
  - Keyboard / accessible alternative: "Move up" / "Move down" buttons per pin in the Customize
    sidebar sheet (`Move <name> up/down`), disabled at the ends.
  - Project page Pinned list reads the same store → same order, nothing extra.
  - Pins that no longer resolve (deleted / no access) keep their place after the visible ones;
    the server drops them on save anyway.

## Test plan
- PHPUnit: extend `tests/Feature/Sidebar/SidebarPreferencesTest.php` with a reorder test (same pins,
  new order → stored in the new order).
- e2e `e2e/tests/custom-sidebar.spec.ts`: update the pin journey (groups stay with pins), new journeys
  "pin two items, Show only pinned on → off: groups back, pins stay on top" and "reorder pins with
  Move up/down and drag & drop → order persists after reload, project page matches".
- `./vendor/bin/pint --test` on changed PHP files, `npx tsc --noEmit`.

## Docs
ARCHITECTURE (customisable sidebar bullet: groups only collapse with "pinned only", pin reorder),
KNOWLEDGE (BRAI-106 section: pins no longer collapse the groups).
