# App API v1 docs tooling + web ↔ desktop bridge

**Branch:** feat/app-api-docs (from release/v1.49.0)
**Status:** done
**Started:** 2026-11-01
**Board cards:** desktop-app board — BRAI-381 (OpenAPI / Scramble), BRAI-382 (registry + recipe),
BRAI-352 (web ↔ desktop bridge). Contract: [release-v1.49.0.md](./release-v1.49.0.md).

## Problem / goal
Every `/api/v1` endpoint must be documented well enough that the Expo mobile app can be built from
the docs alone ("every endpoint should be documented well so next time when we do mobile app, to be
easy with endpoints"). That needs a machine-readable spec that can't go stale, one recipe every agent
follows for a new endpoint, and the web app needs a small bridge to the desktop shell.

## Approach
- **Scramble** (`dedoc/scramble`, already installed) documents **only** `api/v1` (`config/scramble.php`
  `api_path`), never `/api/cli`, `/api/ext`, `/mcp` or webhooks. Server `https://{instance}/api/v1`
  (a server variable, so the spec doesn't depend on `APP_URL`), `info.version` = the API version
  `1.0.0` (not the app release), fixed title, description pointing to `docs/ai/API.md`.
- `App\Providers\AppApiDocsServiceProvider` (own provider so the foundation's provider stays the auth
  agents'): Bearer security scheme always present, public routes (no `auth:app`) marked
  `security: []`; the `viewApiDocs` gate = instance admins (Scramble's `RestrictedDocsAccess` lets
  `local` through and asks the gate everywhere else).
- **Error shape documented once:** subclasses of Scramble's five exception-to-response extensions in
  `app/Support/AppApi/OpenApi/` render `{message, code, errors?}` with the `code` enum from
  `AppApiErrors::CODES`; an operation extension adds the 429 `rate_limited` response to every
  operation (all of `/api/v1` is throttled).
- **Committed spec** `docs/api/openapi.json`, regenerated with **`composer openapi`**
  (= `OPENAPI_WRITE=1 php artisan test --filter=OpenApiSpecTest`). Decision: Scramble reads model
  column types from the database, so `scramble:export` against a dev DB that misses a migration
  (here: devdb without `app_devices`) gives a different file than CI. The drift test therefore runs
  the export in PHPUnit on the migrated test DB (RefreshDatabase) and writes the file in write mode.
  `tests/Feature/AppApi/OpenApiSpecTest.php` fails with the regenerate command when the file differs; it also asserts the documented operations are exactly the
  `api/v1` routes (nothing else leaks in, nothing is missing). No GitHub workflow (token lacks the
  workflow scope) — CI runs PHPUnit anyway.
- **Tags** set explicitly with `#[Group('<API.md module>', weight)]` on every AppApi controller:
  Instance 10, Auth 20, Account 30, Devices 40, Invitations 50; the test rejects other tags. **402
  `limit_reached`** documented by `AppApiLimitReachedErrors` for methods declaring `@throws LimitReached`.
- **Recipe** `docs/ai/skills/add-app-api-endpoint.md` + links from README, skills index,
  CONVENTIONS ("App API v1"), ARCHITECTURE ("App API v1" module), AGENTS.md. `docs/ai/API.md`:
  structure only (the endpoint tables are filled by the endpoint agents).
- **docs-site:** `self-hosting/desktop-and-mobile-apps.md` (what talks to `/api/v1`, env vars, proxy
  paths, link to the OpenAPI file) + the two env vars in `configuration.md`.
- **Bridge** `resources/js/lib/desktop.ts`: feature-detected Tauri IPC
  (`window.__TAURI_INTERNALS__.invoke`), no npm dependency; commands `brainitive_notify`,
  `brainitive_set_badge`, `brainitive_open_external`; `isDesktop()` also reads the
  ` BrainitiveDesktop/<version>` user-agent suffix. Shared prop `desktopMinVersion`. Adoption: the
  unread-notifications count → `desktop.setBadge` in `useUnreadCount` (the title-badge owner only).

## Changes
- Config: `config/scramble.php` (published + configured).
- Providers: `app/Providers/AppApiDocsServiceProvider.php` (+ `bootstrap/providers.php`).
- Support: `app/Support/AppApi/OpenApi/*` (error responses, rate-limit operation extension).
- Middleware: `HandleInertiaRequests` → `desktopMinVersion`; type in `resources/js/types/index.d.ts`.
- Frontend: `resources/js/lib/desktop.ts`; `components/notifications/useNotifications.ts` (badge).
- Docs: `docs/api/openapi.json`, `docs/ai/API.md`, `docs/ai/skills/add-app-api-endpoint.md`,
  `docs/ai/skills/README.md`, `docs/ai/README.md`, `CONVENTIONS.md`, `ARCHITECTURE.md`, `AGENTS.md`,
  `docs-site/self-hosting/*`.

## Test plan
- `tests/Feature/AppApi/OpenApiSpecTest.php`: committed spec up to date; only `api/v1` routes; version
  `1.0.0`; bearer scheme; error responses carry `code`.
- `tests/Feature/AppApi/ApiDocsAccessTest.php`: `/docs/api` + `/docs/api.json` → 403 for guests and
  normal users outside `local`, 200 for instance admins.
- `desktopMinVersion` shared prop test.
- e2e `e2e/tests/app-api-docs.spec.ts`: a signed-in normal user can't open `/docs/api`.
- `npx tsc --noEmit`, `npm run build`, `cd docs-site && npm run build`, Pint.

## Docs to update
API.md (structure), the new skill, README / skills README / CONVENTIONS / ARCHITECTURE / AGENTS,
docs-site. KNOWLEDGE: none beyond the release's own entries (the lead adds those).

## Open questions
- None. Endpoint tables in API.md and the regenerated spec come from the endpoint branches; the lead
  regenerates `openapi.json` after merging them.
