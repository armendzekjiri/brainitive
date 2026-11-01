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

## Phase 03 — workspaces, projects, members, navigation, search, notifications, connected apps

Desktop-app board cards BRAI-388 … BRAI-394 (server side, App API v1). One ticket branch each,
`feat/app-api-<name>` from `release/v1.49.0`, merged back with `--no-ff`. Every endpoint follows
`docs/ai/API.md` + `skills/add-app-api-endpoint.md`; the `## API (ready)` texts for the Mobile app
cards are kept in API.md (the cards are updated by the lead).

**Shared pieces (first ticket):** `App\Support\AppApi\CursorPage` (`?cursor=&limit=` → `{data,
next_cursor}`, default 50, max 100, Laravel cursor pagination on a unique order) and
`AppApi\Concerns\ResolvesVisible` (workspace by slug / project by id through the user's
memberships → 404 for anything the user can't open, then the web policy).

**Decisions:** projects are addressed by numeric **id** (`/projects/{id}`): slugs are unique only per
workspace and change on rename; responses carry slug + web `url` too. Role changes, removals and
settings stay web only.

### BRAI-388 workspaces (`feat/app-api-workspaces`)
- `GET /workspaces` (the user's workspaces, cursor-paginated, `updated_since`) and
  `GET /workspaces/{slug}` (role, is_owner, timezone, counts, plan limits + usage, `can` flags).
- Reuse: `WorkspacePolicy::view/update/createProjects`, `Entitlements::limits/billingUrl` +
  `EntitlementsPayload` usage (as `MeResource`), `WorkspaceResource` grows a full variant.
- Tests: list/show, non-member → 404, Passport → 401, pagination, limits.

### BRAI-389 projects (`feat/app-api-projects`)
- `GET /workspaces/{slug}/projects` (admins all, others their projects — `ProjectController@index`
  rule), `GET /projects/{id}` (home: boards, docs tree (visible pages), task lists, plans, helpdesks,
  lead forms, recently updated), `GET /workspaces/{slug}/project-templates` (`TemplateRuns::catalog`),
  `POST /workspaces/{slug}/projects` (name, key, description, template?, start_date, include, request_id).
- Extract: the inline create of `ProjectController@store` → `App\Support\Projects\ProjectCreator`
  (validation rules + limits + template run + default board) used by web and API.
- Reuse: `ProjectPolicy::view`, `WorkspacePolicy::createProjects`, `Page::visibleTo`, `RecentContent`,
  `LimitGate` (402).
- Tests: visibility (non-member project → 404), create blank + from template, 422, 402, Passport 401;
  web `ProjectTemplatesTest` + project create tests.

### BRAI-390 members (`feat/app-api-members`)
- `GET /workspaces/{slug}/members`, `GET /projects/{id}/members` (`?q=` name filter, cursor),
  `POST /workspaces/{slug}/invitations` `{email, role}` → existing user added, else invitation mailed.
- Extract: `WorkspaceController@inviteMember` → `App\Support\Workspaces\WorkspaceInvites::invite()`.
- Reuse: `WorkspacePolicy::update` (admins invite, as the web), `LimitGate::ensureCanAddMembers` (402).
- Tests: lists, non-admin invite → 403, non-member → 404, already member → 422, limit → 402;
  web `LimitChecksTest` invite cases.

### BRAI-391 navigation (`feat/app-api-navigation`)
- `GET /me/navigation?workspace=<slug>&recent[]=<type>:<id>` → the sidebar of that workspace
  (visible nav entries, accessible projects with groups / pins / content), the app's recent
  destinations resolved, `PUT /me/pins` `{project_id, pins: [{type, id}]}`.
- Reuse: `SidebarPreferences` (normalize / apply / visiblePins); extract the resolver of
  `CommandPaletteController@recent` → `App\Support\Navigation\RecentDestinations` (the web keeps
  recents in the browser, the apps keep theirs locally — the server only resolves them).
- Tests: hidden nav, pins visible-only, other user's project → 404, recent drops forbidden entries.

### BRAI-392 search (`feat/app-api-search`)
- `GET /search?q=&type=&workspace=&project[]=&status=&assignee=&limit=&cursor=` — `SearchService`
  (same ranking + visibility as the web, MCP `search` and `ext/v1/search`), plus `places` on the
  first page. Cursor = the page number.
- Extend the shared service (no fork): `SearchQuery::$workspaceIds` + `queryFrom(['workspace' => …])`.
- Tests: visibility (other workspace's content never found), workspace filter, type filter,
  paging, Passport 401; web `Tests\Feature\Search`.

### BRAI-393 notifications (`feat/app-api-notifications`)
- `GET /notifications?filter=all|unread|mentions|assigned&archived=`, `GET /notifications/unread-count`,
  `POST /notifications/{id}/read`, `POST /notifications/read-all`, `POST /notifications/{id}/archive`,
  `GET|PUT /me/notification-preferences`.
- Extract: `NotificationController` query / apply / feed → `App\Support\Notifications\NotificationInbox`,
  preferences update → `NotificationCatalog::update()`; web controller becomes thin.
- Tests: own rows only (other user's id → 404), filters, cursor, read-all, archive marks read,
  preferences partial update + 422; web `NotificationInboxTest`, `NotificationPreferencesTest`.

### BRAI-394 connected apps + data export (`feat/app-api-connected-apps`)
- `GET /me/connected-apps` (Passport clients — MCP connectors, CLI, extension — grouped per client,
  plus desktop / mobile devices), `DELETE /me/connected-apps/{oauth:<client>|device:<id>}` → 204;
  `POST /me/data-export` (202), `GET /me/data-export` (status + download link that opens in the web session).
- Extract: `ProfileController::connectedApps/disconnectApp` → `App\Support\Profile\ConnectedApps`;
  `DataExportController::store/state` → `App\Support\Profile\DataExports`.
- Tests: list both kinds, revoke each kind, other user's → 404, export cooldown → 422; web
  `ProfileTest` connected apps, `DataExportTest`.

### e2e
`e2e/tests/app-api-workspaces.spec.ts` (one journey through the API with a seeded app token:
workspaces → projects → create project → it appears on the web; pin via API → pin in the web sidebar;
notification read via API → web bell). Uses `POST /profile/app-devices/e2e-create` for the token.

### Phase 03 — result
All seven tickets merged into `release/v1.49.0` (`feat/app-api-workspaces`, `-projects`, `-members`,
`-navigation`, `-search`, `-notifications`, `-connected-apps`, plus `feat/app-api-e2e` with
`e2e/tests/app-api-workspaces.spec.ts`). No migrations. Deviations from the plan: the workspace
content routes need a verified address (as the web); `POST /workspaces` moved to the Workspaces tag;
`GET /workspaces/{slug}/project-templates` added for the template picker; the navigation lists no
docs (`GET /projects/{id}` has the tree); search pages are numbered (`next_cursor` = page). The
`## API (ready)` texts for BRAI-179, 180, 181, 182, 183, 184 (+219), 185 (+256) and 262 are in
`docs/ai/API.md` → "Mobile card texts", for the lead to paste onto the Mobile app cards.

## Phase 04a — TV priority (device code, My Work, calendar read, today)

Desktop-app board cards BRAI-438, BRAI-396, BRAI-418 (read part only) plus the optional `GET /me/today`
from BRAI-396's comment. Driven by the Brainitive TV app (`brainitive-tv/`, own folder): a wallboard
that signs in without a browser and refreshes every few minutes. Same branch flow as phase 03
(`feat/app-api-<name>` from `release/v1.49.0`, merged back `--no-ff`), same definition of done.

### BRAI-438 TV device-code sign-in (`feat/app-api-device-code`)
- RFC 8628 for the new client `tv` (platform `android_tv`): `POST /auth/device` `{client, device: {name,
  platform, app_version, os_version?}}` → `{device_code, user_code, verification_uri,
  verification_uri_complete, expires_in, interval}` (unwrapped, as the OAuth token responses);
  `POST /auth/device/token` `{device_code}` → the TokenResource of `/auth/exchange`, or 400
  `authorization_pending` / `slow_down` / `expired_token` / `access_denied` / `invalid_grant`.
- `App\Support\AppApi\AppDeviceCodes` (cache, like `AppAuthCodes`): device code = 64 random chars, only
  its sha256 is a cache key; user code = 8 letters of `BCDFGHJKLMNPQRSTVWXZ` (no vowels, no look-alikes),
  shown `XXXX-XXXX`, 10 minutes, single use. Approve / deny / poll under a cache lock; the poll enforces
  `interval` (too early → `slow_down`, interval + 5 s, RFC 8628 §3.5).
- Web page `/app/device` (`auth` + `verified`, Inertia `AppAuth/Device`, the consent look of
  `AppAuth/Authorize`): enter the code, or open with `?code=` (the QR = `verification_uri_complete`);
  either way it shows the code, device name, client and platform with Approve / Deny. Only the POST
  (a click) decides; GET never approves. Code entry throttled per user and per IP + a failure counter.
- Shared: the device creation + `max_devices` of `/auth/exchange` → `AppDeviceSignIn::create()`;
  clients' platforms move to `config/app-api.php` (`clients.*.platforms`, `clients.*.grant`). `tv` has no
  redirect, so `/app/authorize` refuses it; desktop / mobile can't use the device grant.
- Tests: happy path, each error, code used twice, expired, denied, a decided code can't be decided again
  by another user, `max_devices`, tv tokens → 401 on /mcp and /api/cli; e2e for the confirm page.

### BRAI-396 My Work and My Day (`feat/app-api-my-work`)
- `GET /me/work?due=overdue|today|upcoming&updated_since=&cursor=&limit=`: the user's open assigned
  issues and list tasks (`ext/v1/my-work`'s query, extracted to `App\Support\MyWork\AssignedWork` and
  used by both), soonest due first, cursor-paginated over both tables; "today" is the user's local date.
  Entry: `{id: "issue:12"|"task:5", type, key, title, project, status, priority, due_date, due_time,
  done, url, updated_at}`.
- `PATCH /me/work/{id}` `{done: bool}`: the web's complete logic — issues move to the board's first done
  column (or back to the first open one) with watcher notifications, list tasks toggle their status
  (a recurring one rolls on). Extract `BoardController::moveItem`'s move + notify and
  `TaskListController::updateItem`'s recurrence handling → `App\Support\Work\WorkCompletion`.
- My Day: `GET /me/day`, `POST /me/day/items {id}`, `DELETE /me/day/items/{id}` (`MyDayPlan`, the
  user's local today); done / undone through `PATCH /me/work/{id}`.
- Tests: visibility (other project's work never listed, 404 on PATCH / POST), due filters, cursor,
  updated_since, done/undone both kinds, My Day add/remove/idempotent, Passport 401; web My Work / board
  move / task list tests for the refactor.

### BRAI-418 calendar, read part only (`feat/app-api-calendar`)
- `GET /calendar/events?from=&to=` (Y-m-d, inclusive, ≤ 62 days, default today + 7): connected
  calendars + the user's Brainitive dated issues / tasks (+ bookings), one shape: `{id, type, title,
  start, end, all_day, location, conference_url, conference_provider, color, calendar, project, url,
  external_url, done, read_only}`. `GET /calendar/connections` (status only, never secrets).
- Extract the event building of `CalendarController@index` → `App\Support\Calendar\CalendarEvents`
  (web and API share it; the web page keeps its props).
- **Later (phase 08, unchanged):** `POST|PATCH|DELETE` events, meetings, the ICS feed link. Connecting
  providers stays web only.
- Tests: own connections only, failing provider → `errors` not 500, dated items of visible projects
  only, range validation, Passport 401; web calendar tests for the refactor.

### `GET /me/today` (`feat/app-api-today`)
- One call for the TV wallboard: My Work `overdue` + `today` (≤ 100) and today's events (user's local
  day), `ETag` + `If-None-Match` → 304. Fixed number of queries (no N+1).

### Web fix (`fix/web-projects-index`)
- `GET /workspaces/{slug}/projects` rendered the missing `Projects/Index` page → redirect to the
  workspace home (`workspaces.show`); the unused query goes. Feature test.

### Phase 04a — result
Merged into `release/v1.49.0`: `feat/app-api-device-code` (BRAI-438), `feat/app-api-my-work` (BRAI-396),
`feat/app-api-calendar` (BRAI-418 read part), `feat/app-api-today` (`GET /me/today`),
`fix/web-projects-index`. No migrations (`app_devices.client` / `platform` are strings: `tv` /
`android_tv` fit). New spec tags: My Work 130, Calendar 140.
Deviations from the plan / card:
- `POST /auth/device` takes the same `device` object as `/auth/exchange` (`{client, device: {name,
  platform, app_version, os_version?}}`), not a bare `device_name`: the token step creates the device
  row, which needs platform and version. The error codes are 400 (RFC 8628 §3.5); `slow_down` also
  returns the new `interval`. An unknown or already used device code answers `invalid_grant`.
- My Day lives under `/me/my-day` (decided by the lead, as the web's `/my-day`), with done / undone at
  `PATCH /me/my-day/items/{id}` and also `PATCH /me/work/{id}`; `POST /me/my-day/carry-over` is the web's
  "Bring over" (the plan is never carried over by itself; `carry_over_count` says how many would come).
  Reordering My Day stays web only for now. `DELETE /me/my-day/items/{id}` answers 200 + the plan (a part
  of the plan, not a resource).
- `/me/work` takes `status=open|done|all` (default open) so `updated_since` can report completed work.
- `GET /me/today` also carries My Day (the TV "Today" panel), and its `events` are calendar events +
  bookings only (dated work is in `work`).
- **Left for phase 08 (BRAI-418):** `POST|PATCH|DELETE` calendar events, meetings (Meet / Teams),
  the private ICS feed link. Connecting providers stays web only.
- `## API (ready)` texts for BRAI-215, BRAI-222 and BRAI-229 are in `docs/ai/API.md` → "Mobile card texts".


## Phase 04b — task lists, captures, snippets, bookmarks, time tracking

Desktop-app board cards 677–680 (epic BRAI-395, card 675): BRAI-397, BRAI-398, BRAI-399, BRAI-400. Card
681 (BRAI-401, quick switcher) is a desktop UI card, not built here. Same flow as phase 03 / 04a
(`feat/app-api-<name>` from `release/v1.49.0`, merged back `--no-ff`), same definition of done (tests incl.
Passport → 401 and other users' data → 404, e2e spec, `API.md` rows + mobile card texts, OpenAPI).
New spec tags: Task lists 150, Captures 160, Snippets 170, Bookmarks 180, Time tracking 190.
Creates take an optional `request_id` (uuid): a retry answers 200 with what the first call created
(`IdempotentRequest`, the MCP's store, keyed per user + endpoint).

### BRAI-397 task lists + recurring to-dos (`feat/app-api-task-lists`)
- `GET|POST /projects/{id}/task-lists`, `GET /task-lists/{id}` (counts + `can`), `GET /task-lists/{id}/items`
  (`status=todo|done|all`, cursor), `POST /task-lists/{id}/items`, `PATCH|DELETE /task-lists/{id}/items/{item}`,
  `POST /task-lists/{id}/items/reorder` `{ids}`. Tasks carry `notes` as Markdown (stored as the editor's HTML),
  recurrence + reminder fields; completing a repeating task rolls it on (`WorkCompletion::updateTask`).
- Extract: list create (slug incl. trashed, position, creator watches) and task create / rules / reorder of
  `TaskListController` → `App\Support\TaskLists\TaskLists`; used by the web, the CLI (`/api/cli/lists*`, whose
  list query becomes the shared "lists of projects the user can open"), the MCP tool and `ContentCapture`.
- Reuse: `TaskListPolicy` (view / create / manageItems), `ResolvesVisible`.

### BRAI-398 captures inbox (`feat/app-api-captures`)
- `GET /captures?state=inbox|processed|archived&q=` (cursor, newest first), `POST /captures`, `PATCH /captures/{id}`,
  `POST /captures/{id}/archive|restore`, `DELETE /captures/{id}` (archived only, as the web), `GET
  /captures/{id}/preview?type=` (the convert dialog's fields), `POST /captures/{id}/convert` (`CaptureConversion`,
  idempotent), `GET /capture-destinations` (`CaptureDestinations`). Owner only; others' captures → 404.
- Files on a capture: the web has none (captures are text + url) → deferred, no migration.

### BRAI-399 snippets + bookmark collections (`feat/app-api-snippets-bookmarks`)
- Snippets: `GET|POST /workspaces/{slug}/snippet-collections`, `GET|PATCH|DELETE /snippet-collections/{id}`,
  `GET|POST /snippet-collections/{id}/snippets`, `GET|PATCH|DELETE /snippets/{id}`.
- Bookmarks: `GET|POST /workspaces/{slug}/bookmarks` (groups visible to the user), `GET|PATCH|DELETE
  /bookmarks/{id}`, `GET|POST /bookmarks/{id}/items` (link / note / todo / file), `PATCH|DELETE
  /bookmarks/{id}/items/{item}`. Collaborators and icons upload: web only.
- Extract the inline create / rules of `SnippetController` and `BookmarkController` → `App\Support\Snippets\Snippets`,
  `App\Support\Bookmarks\Bookmarks` (web + API; the MCP tools keep theirs).

### BRAI-400 time tracking (`feat/app-api-time-tracking`)
- `GET /me/timer`, `POST /me/timer/start {id: "issue:<id>"}`, `POST /me/timer/stop {timer_id, time?, note?,
  activity_id?, spent_on?}`, `POST /me/timer/discard {timer_id}`; `GET /work-logs?workspace=&from=&to=`,
  `POST /work-logs`, `GET|PATCH|DELETE /work-logs/{id}` (own entries; billed = read-only), `GET
  /me/timesheet?workspace=&week=` (+ activities, loggable projects). Rates, clients, VAT, invoices: web only.
- Extract `WorkTimerController` start / stop / discard → `App\Support\Billing\WorkTimers` and
  `TimesheetController` store / update / destroy → `App\Support\Billing\TimeEntries` (web + API).

### Phase 04b — result
Merged into `release/v1.49.0`: `feat/app-api-task-lists` (BRAI-397), `feat/app-api-captures` (BRAI-398),
`feat/app-api-snippets-bookmarks` (BRAI-399), `feat/app-api-time-tracking` (BRAI-400). No migrations (request_id
retries reuse `mcp_requests`). New spec tags: Task lists 150, Captures 160, Snippets 170, Bookmarks 180, Time
tracking 190. e2e: `app-api-task-lists`, `-captures`, `-snippets-bookmarks`, `-time-tracking` (+ `helpers/app-api.ts`).
Deviations from the plan / cards:
- Task lists: tasks are their own cursor list (`GET /task-lists/{id}/items`), not inside `GET /task-lists/{id}`;
  notes are Markdown; reorder takes `{ids}` in order. Renaming / deleting lists: web only for now.
- Captures: also `GET /captures/counts`, `GET /captures/{id}`, `PATCH`, archive / restore and `preview`; files on a
  capture are deferred (the web's captures have none).
- Snippets / bookmarks: collections and groups by id, plus `PATCH|DELETE` of collections / groups and cursor item
  lists; bookmark icons are emoji only (uploads web only), collaborators web only.
- Time: the timer start takes `{id: "issue:<id>"}` and no activity — the activity is chosen when stopping, as on the
  web (`GET /me/timer` carries the options); stop needs the `timer_id`; `GET /me/timesheet` needs `workspace`.
  Work logs are own entries only; billed → 422 `entry`. Rates, clients, VAT, invoices: web only (BRAI-242/244/245).
- `## API (ready)` texts for BRAI-216, 217, 218, 241, 258 and the web-only text for BRAI-242 / 244 / 245 are in
  `docs/ai/API.md` → "Mobile card texts".

