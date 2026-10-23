# Playwright e2e suite (BRAI-96)

**Branch:** test/e2e-playwright
**Status:** done (crawler runs nightly + on demand)
**Started:** 2026-10-23
**Board card:** BRAI-96 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-205

## Problem / goal
PHPUnit covers the backend, but nothing clicks through the real app. Goal: core user journeys
in a real browser on every push, and cross-browser every night.

## Approach
- `e2e/` (Playwright): `env.ts` (targets + app environment), `playwright.config.ts`,
  `global-setup.ts` (reset), `fixtures.ts` (`uid()`, `urls`, `memberPage`, page-error guard),
  `tests/auth.setup.ts` (saved sessions per port), one spec per area.
- **Database: PostgreSQL only.** Each local run gets its own app (`php artisan serve --no-reload`,
  `E2E_PORT`) on `brainitive_e2e_<port>` (local Postgres, created by global-setup); CI uses its
  Postgres service. PHPUnit also moved to Postgres (`brainitive_test`, forced in phpunit.xml),
  and the SQLite CI job is gone.
  `php artisan e2e:reset` (`App\Console\Commands\E2eReset`) runs `migrate:fresh` + `E2eSeeder`,
  and refuses unless `APP_ENV=e2e` and the database is clearly an e2e one
  (`tests/Unit/E2eResetGuardTest`).
- **Seed** (`E2eSeeder`, no factories): owner + member, workspace `e2e-workspace`, project
  `website` (WEB), `main-board` with 3 issues. Onboarding dialogs are marked as seen.
- **CI:**
  - `tests.yml` gets an `e2e` job (Chromium, every push, report artifact on failure);
  - `e2e-nightly.yml` runs Firefox + WebKit against `pre-release`.
- **Remote mode** (`E2E_BASE_URL` + basic auth) for running against dev.brainitive.app.
- Analytics script only on brainitive.app hosts (it reported dev / e2e / local / self-hosted
  traffic).

## Areas (one spec each)
- auth + account
- workspaces + projects + members
- boards + issues + saved views
- docs / lists / My Work / calendar / bookmarks / snippets
- helpdesk (public form, widget, portal)
- lead forms / plans / releases / sprints / search + command palette

## Result
- **79 journeys** in 7 specs (plus 2 login setups): auth/account, workspaces/projects, boards, content,
  helpdesk, lead forms/plans/search, collaboration. The collaboration spec covers invite → sign
  up → verify → join → project access, @mention → inbox, and status change → assignee. The whole
  Chromium suite takes about 1.2 min.
- **Button crawler** for every page plus the command palette.
- **App bugs found and fixed**, each with a PHPUnit or e2e regression test:
  - shared throttle counters (lead form refused after reloads)
  - ticket keys unique per instance (500 for a second workspace with the same project key)
  - issue and ticket key race (500 on simultaneous creates)
  - labels and issue types created via Inertia got JSON (dialog stuck, Inertia error modal)
  - ticket sidebar custom fields didn't load or save
  - dead helpdesk column menu and attachment buttons
  - settings links shown to non-admins
  - @mentions impossible in issue comments
  - validation errors lost to a background-fetch session write
  - analytics reporting dev / e2e / self-hosted traffic
  - SQLite invite role CHECK (500 inviting a "member" on self-hosted SQLite)
  - a11y: unlabeled forgot-password email field, duplicate `id="password"`

## Test plan
Each spec passes 3× in a row locally plus `--repeat-each=2`. The whole suite passes in parallel,
and CI is green.
