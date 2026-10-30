# Health, Workload and Releases as their own sidebar entries

**Branch:** fix/sidebar-project-tools (from release/v1.48.0)
**Status:** done
**Started:** 2026-10-30
**Board card:** BRAI-286 (bug)

## Problem / goal
In the project sidebar (`Layouts/AppLayout.tsx` → `ProjectContent`) the Releases, Health and
Workload links were rendered as the last rows of the **Boards** group: collapsing Boards or hiding
the Boards group in "Customize sidebar" also hid them, and they could not be hidden on their own.
They are project-wide views, so they should sit at the level of Boards / Lists / Plans … and each
get its own show/hide switch.

## Approach
- **Three first-level rows, no group header.** A header "HEALTH" over one "Health" row would be
  noise, so the three views are one compact block of top-level rows (icon + name, no indent, no
  collapse), `role="group"` named "Project tools" — the same name and order (Health · Workload ·
  Releases) as the project page's header quick links. The block comes first in the project tree
  (after Pinned), separated from Boards by the usual group divider.
- **Each one is a sidebar group key** (`health`, `workload`, `releases`) in
  `prefs.groups`, so it reuses everything groups already have: validation, normalisation
  (missing key = visible, so saved preferences keep working), `shows()` (respects
  "Show only pinned" / pins exactly like the other groups), "Show all in project" when one is
  hidden, and Reset.
- `SIDEBAR_GROUPS` entries get a `section: 'tools' | 'content'`; the Customize sheet renders the
  tools under their own "Project tools" sub-list above "Groups".
- **Gate unchanged:** today the links only render when the project has at least one board (they
  are issue views; the controllers authorise with the project's view policy). Keep that.
- Not pinnable (they never were). The project page already shows them as header quick links,
  untouched.

## Changes
- Migrations: none (JSON column, new keys default to `true`)
- `app/Support/Sidebar/SidebarPreferences.php`: `GROUPS` + `health`, `workload`, `releases`
- Controller: validation derives from `GROUPS` (no change needed)
- Frontend: `types/index.d.ts` (`SidebarGroupKey`), `lib/sidebarPreferences.ts`
  (`SIDEBAR_GROUPS` + `section`), `components/sidebar/CustomizeSidebarSheet.tsx`,
  `Layouts/AppLayout.tsx` (`ProjectContent`)
- Docs: ARCHITECTURE (sidebar BRAI-106 + workload entry), KNOWLEDGE (sidebar section)

## Test plan
- `TEST_DB_SUFFIX=tools php artisan test --filter=SidebarPreferences`
- `E2E_PORT=8112 npx playwright test -c e2e --project=chromium tests/custom-sidebar.spec.ts`
  (+ `workload.spec.ts`, `project-health.spec.ts` which click the sidebar links)
- `./vendor/bin/pint --test` on changed PHP, `npx tsc --noEmit`
