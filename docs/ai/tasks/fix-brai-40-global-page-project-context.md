# Keep Project Context On Global Pages

**Branch:** fix/brai-40-global-page-project-context
**Status:** done
**Started:** 2026-05-31
**Board card:** BRAI-40 — https://www.brainitive.app/workspaces/personal/projects/brainitive/boards/main-board#item-125

## Problem / goal
Global pages such as Calendar, My Work, Home, and Inbox lose the selected workspace/project
section in the sidebar because those routes have no workspace/project route parameters.

## Approach
Persist the last route-derived workspace/project context in the session from project-scoped
or workspace-scoped pages. When rendering global app pages, use that session context for
the shared sidebar props while keeping the global route itself active.

## Changes
- Migrations: none
- Models / relationships: none
- Policy (+ register in AppServiceProvider): none
- Controller / routes: none
- Frontend (Pages, components, types/index.d.ts): pass remembered project id into the app
  sidebar and use it as a persisted fallback
- MCP tool(s) (if exposing to AI): none

## Test plan
- `./vendor/bin/pint --test app/Http/Middleware/HandleInertiaRequests.php` — passed
- `npx tsc --noEmit` — passed
- `npm run build` — passed
- `php artisan test` — blocked locally: PHP is missing the `pdo_sqlite` driver used by the
  `phpunit.xml` in-memory SQLite configuration
- Targeted manual check: not run locally because the test/runtime PHP environment has no
  working SQLite driver

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
No architecture or convention docs expected; mark this plan done after verification.

## Open questions
None.
