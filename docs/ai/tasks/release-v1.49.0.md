# Release v1.49.0: App API v1 foundation + native sign-in (desktop phase 01–02, server side)

**Branch:** release/v1.49.0 (ticket branches below, merged locally)
**Status:** in-progress
**Started:** 2026-11-01
**Board cards:** desktop-app board — BRAI-348 (phase 01), BRAI-353 (phase 02); tickets BRAI-380, 381, 382,
383, 384, 385, 386, 428, 352, 354/355/357 (server side). Desktop client side: BRAI-349, 350, 351, 356.

## Problem / goal
The desktop app (Tauri 2) and later the Expo mobile app need a JSON API of their own and a sign-in
that works for Google / Microsoft / GitHub accounts (Google blocks OAuth inside webviews). This
release builds the **server side**: `/api/v1` conventions, the app token system, native sign-in,
instance info, account, devices, invitations, and the API docs every later endpoint follows.

The desktop app itself lives in **its own repo** (`armendzekjiri/brainitive-desktop`, BSL 1.1),
checked out locally at `desktop/` (gitignored here) and versioned on its own (0.x → Desktop
v1.0.0). This release only ships what the server needs.

## Approach

### App tokens: our own, not Passport (decision)
BRAI-428 asked for Passport underneath. We use **our own opaque, hashed tokens** instead:
- Passport TTLs are global (`tokensExpireIn` 30 days for MCP/CLI); the app needs 15-min access
  tokens and 60-day refresh tokens without touching MCP/CLI.
- Passport has no refresh **reuse detection** (a replayed refresh token just fails) and no
  device binding; both are core to BRAI-428.
- Isolation becomes structural: an app token (`bra_…`) is not a JWT, so Passport (`/mcp`,
  `/api/cli`, `/api/ext`) rejects it; a Passport JWT is not in `app_tokens`, so `/api/v1`
  rejects it. Tests assert both directions.
- The flow is still OAuth 2.1 authorization code + PKCE (S256), first-party only: no client
  secrets, no password grant, no implicit, no dynamic client registration.

### Native sign-in flow (contract — agents build against this)
1. App generates `code_verifier` (43–128 chars) + `state`, opens the **system browser** at
   `GET {instance}/app/authorize?client=desktop&code_challenge=<S256>&code_challenge_method=S256&state=<state>&device_name=<urlencoded>`.
2. `/app/authorize` (web middleware): guest → login page (any method: password, Google,
   Microsoft, GitHub) with `url.intended` back here. Signed in + verified → a consent page
   "Sign in to Brainitive Desktop as <email>?" with **Continue** / **Cancel**.
3. Continue (`POST /app/authorize`) creates an `app_auth_codes` row (`purpose=exchange`, 60 s,
   single use, bound to the challenge) and redirects to
   `brainitive://auth/callback?code=<code>&state=<state>` (mobile later: its own scheme, see
   `config/app-api.php` `clients.*.redirect`). Cancel → `…/callback?error=access_denied&state=…`.
4. App → `POST /api/v1/auth/exchange` `{code, code_verifier, device: {name, platform, app_version, os_version?}}`
   → creates the `app_devices` row and the first token pair.
5. `POST /api/v1/auth/refresh` `{refresh_token}` → new pair; the old pair is dead. A refresh
   token presented **again** after rotation = reuse → the whole device is revoked, an
   `app_audit_events` row `refresh_reuse` is written and the user gets an email.
6. `POST /api/v1/auth/logout` (Bearer) → revokes the current device.
7. Webview session: `POST /api/v1/auth/web-session` (Bearer) → `{url}` = `{instance}/app/session/<code>`
   (`purpose=web_session`, 60 s, single use). The webview opens it; the server logs that user
   into the **web** guard and redirects to `/dashboard` (or a safe relative `?to=`).

Token response (exchange + refresh):
```json
{ "token_type": "Bearer", "access_token": "bra_…", "expires_in": 900,
  "refresh_token": "brr_…", "refresh_expires_in": 5184000,
  "device": { "id": 12, "name": "…", "platform": "macos" } }
```

### Conventions (BRAI-380), enforced by the foundation
- Routes in `routes/app-api.php`, prefix `/api/v1`, names `app.v1.*`, middleware group `app-api`
  (JSON responses, `throttle:app-api`, no session, no CSRF, **no CORS**). Authenticated routes add
  `auth:app`.
- Guard `app` (driver `app-token`, resolver `AppTokens::userFromRequest`); the request carries the
  device/token (`AppTokens::currentDevice($request)`). Suspended users → 403, revoked
  device/token → 401.
- One error shape `{message, code, errors?}` for everything under `api/v1/*`
  (`App\Support\AppApi\AppApiErrors`): 401 `unauthenticated`, 403 `forbidden`, 404 `not_found`,
  405 `method_not_allowed`, 409 `conflict`, 422 `validation_failed`, 429 `rate_limited`, 500
  `server_error`.
- Thin controllers in `App\Http\Controllers\AppApi\*` → existing Support services / policies.
  Form Requests in, API Resources out (explicit fields), ISO-8601 UTC dates.
- Lists: cursor pagination `?cursor=&limit=` (max 100) + `updated_since` (from phase 03).
- Every endpoint: tests incl. **401 with an MCP/CLI/extension (Passport) token**, OpenAPI
  regenerated, a row in `docs/ai/API.md`, and the `## API` section on its mobile card.

## Changes

### Foundation (lead, committed on the release branch before the agents start)
- **One table, `app_devices`** (decided with the user 2026-11-01: "why we need a api table?").
  A device holds its one active pair as columns (`access_token_hash`, `refresh_token_hash`,
  expiries, `previous_refresh_hash` for reuse detection); revoke = `revoked_at` + `revoked_reason`,
  which together with `created_at` / `last_used_at` is the audit trail. No token / code / audit
  tables: sign-in codes live in the cache (`AppAuthCodes`, atomic `Cache::add` marker = single use).
- Model `AppDevice`; `User::appDevices()`.
- `config/app-api.php` (TTLs, clients + redirects, max devices, min app versions).
- `App\Support\AppApi\AppTokens` (issue / resolve / revoke / revokeAllFor, guard resolver),
  `AppAuthCodes` (issue / consume / PKCE S256), `AppApiErrors`; guard `app` (`Auth::viaRequest`
  in `AppApiServiceProvider`, also throttles `app-api` / `app-auth`); `app-api` group
  (`AppApiJson`, bindings, throttle) + routes + error rendering in `bootstrap/app.php`.
- `routes/app-api.php` with one empty section per agent. The web routes `/app/authorize` and
  `/app/session/{code}` are the auth agent's (`routes/app-auth.php`, required from `web.php`).
- `dedoc/scramble` installed (agents regenerate `docs/api/openapi.json`).
- `docs/ai/API.md` skeleton (contract above, one section per module).
- Test helper `Tests\Feature\AppApi\Concerns\ActsAsAppClient` (`appToken($user)`, `passportToken($user)`).
- Foundation tests: guard accepts app token, rejects Passport token / expired / revoked;
  app token rejected on `/mcp` and `/api/cli/me`; error shape.

### Ticket branches (parallel worktree agents)
| Branch | Cards | Scope |
|---|---|---|
| `feat/app-api-auth` | BRAI-383, 355, 357, 428 | `/app/authorize` consent page + POST, exchange, refresh (rotation + reuse detection + email), logout, web-session + `/app/session/{code}`, audit events, suspension / password change revoke app devices, max devices |
| `feat/app-api-account` | BRAI-354 (server), 384 | `GET /api/v1/instance` (public), `GET/PATCH /api/v1/me`, avatar POST/DELETE |
| `feat/app-api-devices` | BRAI-385, 386 | devices register/update/list/revoke, Profile → "Desktop & mobile apps" list with revoke, invitations show/accept, `POST /api/v1/workspaces` |
| `feat/app-api-docs` | BRAI-381, 382, 352 | Scramble config + `docs/api/openapi.json` export + PHPUnit drift test, `/docs/api` admin/local only, `docs/ai/API.md` + skill `add-app-api-endpoint.md` + links, docs-site API reference, `resources/js/lib/desktop.ts` bridge |
| desktop repo `desktop/` | BRAI-349, 350, 351, 356, 354/355 client | Tauri 2 scaffold, security model (remote IPC only for the instance origin, navigation guard), first-run connect, system-browser sign-in + deep links, keychain, spike matrix (macOS now; Windows/Linux in CI later) |

## Test plan
- PHPUnit per endpoint (DoD above), Postgres, `TEST_DB_SUFFIX` per worktree.
- e2e: consent page (Continue → redirect to `brainitive://…` captured), Profile devices list +
  revoke, `/app/session/<code>` signs the browser in, `/api/v1/instance` via `request`.
- Desktop: `cargo test` + a manual run against `composer run dev` on macOS.

## Docs to update
ARCHITECTURE (App API module), CONVENTIONS (App API rules, links to API.md + skill), KNOWLEDGE
(own tokens vs Passport, isolation, reuse detection), `docs/ai/README.md`, docs-site (API
reference + self-hosting env; no desktop guide until the app is public), HelpModal changelog,
homepage (only what's user-facing: the devices list).

## Open questions
- Remote repo `armendzekjiri/brainitive-desktop`: create + push only after the user's go.
