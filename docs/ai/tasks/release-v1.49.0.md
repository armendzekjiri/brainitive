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


## Phase 06 — boards, issues, comments, scrum, views

Desktop-app board cards 683–689 (epic BRAI-402, card 682): BRAI-403 … BRAI-409, all "API ·" cards, built in card
order. Same flow and definition of done as phase 04b (`feat/app-api-<name>` from `release/v1.49.0`, merged back
`--no-ff`; tests incl. Passport → 401, other workspaces / projects → 404, viewer vs editor vs admin, 422,
pagination, 402 where the web has it; e2e spec; `API.md` rows + mobile card texts; OpenAPI via
`TEST_DB_SUFFIX=p06 composer openapi`). New spec tags from 200 on (Boards 200, Issues 210, …).
Everything goes through the web's code, so live boards (`BoardLive`, model events) and watcher notifications
fire for app writes too. Issues are addressed by id (`/items/{id}`), boards by id (`/boards/{id}`); issue
descriptions are Markdown in / out (stored as the editor's HTML), like task notes.

### BRAI-403 boards and columns, read + move cards (`feat/app-api-boards`)
- `GET /projects/{id}/boards` (cursor, `updated_since`), `GET /boards/{id}` (columns with WIP limit, done flag and
  card counts, swimlane setup, done retention, running sprint, `can`), `GET /boards/{id}/items?column=&include_old_done=`
  (the board's cards: Scrum = the running sprint, Kanban = done retention applied, as the board page).
- `POST /items/{id}/move {column_id, position?, with_subtasks?}` → the card. Extract the web's `moveItem` (incl.
  "complete the open subtasks") into `WorkCompletion::moveIssue(..., $withSubtasks)`; web uses it.
- Column create / edit / reorder: web only (BoardPolicy::update holders see `can.update`).

### BRAI-404 issues: create, view, edit, move, assign (`feat/app-api-issues`)
- `POST /boards/{id}/items` (402 at the storage limit, `request_id`), `GET|PATCH|DELETE /items/{id}`,
  `GET /items/by-key/{KEY}`. PATCH takes the sent fields (title, description Markdown, type, priority, assignee, dates,
  labels, story points, estimate, fix version, parent, custom field values) + optional `updated_at` → 409 when the
  issue changed since. GitHub development (PRs / branches / commits) read-only on the detail.
- Extract the inline rules / create / update of `BoardController@storeItem|updateItem|updateItemLabels` into
  `App\Support\Boards\Issues`; web uses it.

### BRAI-405 comments, mentions, watchers, attachments, history (`feat/app-api-issue-activity`)
- `GET|POST /items/{id}/comments` (Markdown, replies), `PATCH|DELETE /items/{id}/comments/{comment}` (own, as the
  web), `POST|DELETE /items/{id}/watch`, `GET|POST /items/{id}/attachments`, `DELETE …/{attachment}`,
  `GET /items/{id}/activity` (cursor). Shared: `ItemComments`, attachment create / delete extracted from the web.

### BRAI-406 subtasks and issue links (`feat/app-api-issue-links`)
- `GET /items/{id}/children` (+ create with `parent_id` on `POST /boards/{id}/items`), `GET|POST|DELETE
  /items/{id}/links` (`ItemLinks`, Restricted for issues the user can't open).

### BRAI-407 custom fields, filters, saved views (`feat/app-api-board-views`)
- `GET /projects/{id}/config` (types, priorities, labels, fields + options, members, releases), `GET /boards/{id}/views`
  (`BoardViewConfig`), filters on `GET /boards/{id}/items` (`assignee`, `label[]`, `type[]`, `priority[]`, `q`, `field[id]`).

### BRAI-408 scrum (`feat/app-api-sprints`)
- `GET /boards/{id}/sprints`, `GET /sprints/{id}` (`0` = backlog with `?board=`), `POST /sprints/{id}/items` (move
  issues, `SprintLifecycle` / `InteractsWithSprints` rules), `GET /sprints/{id}/burndown`, `GET /boards/{id}/velocity`
  (`SprintReport`). Create / start / complete: web only.

### BRAI-409 timeline and calendar issue views (`feat/app-api-dated-items`)
- `GET /projects/{id}/items?from=&to=` (issues whose start…due touches the range), reschedule through
  `PATCH /items/{id}`. Recurring issues and templates: web only.

### Phase 06 — result
Merged into `release/v1.49.0`: `feat/app-api-boards` (BRAI-403), `feat/app-api-issues` (BRAI-404), `feat/app-api-issue-activity`
(BRAI-405), `feat/app-api-issue-links` (BRAI-406), `feat/app-api-board-views` (BRAI-407), `feat/app-api-sprints` (BRAI-408),
`feat/app-api-dated-items` (BRAI-409). No migrations. New spec tags: Boards 200, Issues 210, Sprints 220. e2e: `app-api-boards`,
`-issues`, `-issue-activity`, `-issue-links`, `-board-views`, `-sprints`, `-dated-items`.
Deviations from the plan / cards:
- Move cards (`POST /items/{id}/move`) is in BRAI-403 (the card's "read, move cards"), not 404.
- Comments: no `PATCH|DELETE` (the web has no comment edit / delete). Watching is the user's own (`POST|DELETE /items/{id}/watch`
  + `GET …/watchers`); managing other watchers: web only. Attachments upload straight to the issue (no shared uploads API yet).
- Filters use the board URL / saved-view params (`assignee=me|unassigned|user-<id>`, `labels=1,2`, `f<id>=…`) instead of the
  card's `label` / `field[x]`, so views work as they are.
- Scrum: the backlog is `GET /boards/{id}/backlog` + `POST /boards/{id}/backlog/items`, not sprint id `0`; sprint issues are
  `GET /sprints/{id}/items`.
- Dated issues: `GET /projects/{id}/items?from=&to=` (start … due touching the range), not `due_from` / `due_to`.
- `## API (ready)` texts for BRAI-187, 188, 189, 190, 191, 192, 193, 194, 252 and the web-only text for BRAI-195 are in
  `docs/ai/API.md` → "Mobile card texts".


## Phase 07 — docs, whiteboards, plans, releases, files

Desktop-app board cards 691–696 (epic BRAI-410, card 690): BRAI-411 … BRAI-416, all "API ·" cards, built in card
order, same flow and definition of done as phase 06 (`feat/app-api-<name>` from `release/v1.49.0`, merged back
`--no-ff`; PHPUnit incl. Passport → 401, other workspaces / projects → 404, private drafts → 404, viewer vs editor vs
admin, 422, pagination, 402 on uploads; e2e spec; `API.md` rows + mobile card texts; OpenAPI via
`TEST_DB_SUFFIX=p07 composer openapi`). New spec tags from 230 on (Docs 230, then Whiteboards, Plans, Releases,
Files). Pages are addressed by uuid (`/pages/{uuid}`), as on the web and in the MCP.
Before: `fix/mcp-move-notifies-watchers` — MCP `move_board_item` through `WorkCompletion::moveIssue` (watchers,
optional `with_subtasks`).

**Docs are live documents (Yjs).** An API write never fights a live session: content writes go through the web
editor's draft path (`Page::saveDraft`, so a published page's readers see nothing until "publish"), they need the
`revision` (`edit_revision`) the app last read (409 otherwise — live saves bump it every few seconds), and the write
starts a new live epoch (`Page::saved` → `PageCollab::reset`), so open browsers reload on the new draft — the web's
own single-user / fallback save rule. The MCP's `update-page` has no guard (it discards a published page's draft and
writes `content`); the API follows the web editor instead.

### BRAI-411 docs: tree, read, create, edit, publish, move (`feat/app-api-pages`; `feat/app-api-docs` is BRAI-381's)
- `GET /projects/{id}/pages?parent=` (flat tree: folders, docs, whiteboards the user may see — drafts only their
  author's / admins', `Page::visibleTo`), `GET /pages/{uuid}` (Markdown with `@[Name](user:<id>)` mentions, sanitized
  HTML, `revision`, draft vs published for editors, `can`), `POST /projects/{id}/pages` (doc / folder / whiteboard,
  Markdown, `publish?`, `request_id`, 402 storage), `PATCH /pages/{uuid}` (name, icon, Markdown + `revision` → 409),
  `POST /pages/{uuid}/publish` (`ReviewGate`, watchers notified), `POST /pages/{uuid}/move` (`PageMover`).
- Extract the web's inline create / publish into `Support\Pages\PageWrites`; `PageController` uses it.

### BRAI-412 collab token (`feat/app-api-collab-token`)
- `POST /pages/{uuid}/collab-token` → `PageCollab::session` (same access rule as `internal/collab/access`), plus
  bootstrap + draft save through the API, so a native editor is a full peer (otherwise its edits would live only in
  the Y state, never in the draft).

### BRAI-413 versions, inline comments, reviews, backlinks (`feat/app-api-doc-reviews`)
- `GET /pages/{uuid}/versions` (+ one version), review threads / replies / resolve, approve / request changes,
  `GET /pages/{uuid}/backlinks`, `GET /links/resolve?url=` (smart link chips) — reusing `PageReviews`,
  `ContentReferences`, `SmartLinks`.

### BRAI-414 whiteboards (`feat/app-api-whiteboards`)
- `GET /whiteboards/{uuid}` (scene JSON, read-only), the exported image. Drawing, templates, sticky → issue: web /
  desktop webview only.

### BRAI-415 Gantt plans and releases, read (`feat/app-api-plans-releases`)
- `GET /projects/{id}/plans`, `GET /plans/{id}` (rows, bars, dependencies, linked issues — the MCP's plan reader),
  `GET /projects/{id}/releases`, `GET /releases/{id}` (notes as Markdown). Editing: web only.

### BRAI-416 files (`feat/app-api-files`)
- `POST /uploads` (multipart, the web's upload checks: allowlisted extensions, size, storage → 402) and how the apps
  fetch files; a shared uploads service if issue attachments can use it cheaply.

### Phase 07 — result
Merged into `release/v1.49.0`: `fix/mcp-move-notifies-watchers`, `feat/app-api-pages` (BRAI-411), `feat/app-api-collab-token`
(BRAI-412), `feat/app-api-doc-reviews` (BRAI-413), `feat/app-api-whiteboards` (BRAI-414), `feat/app-api-plans-releases` (BRAI-415),
`feat/app-api-files` (BRAI-416). No migrations. New spec tags: Docs 230, Whiteboards 240, Plans 250, Releases 260, Files 270. e2e:
`app-api-pages`, `-collab-token`, `-doc-reviews`, `-whiteboards`, `-plans-releases`, `-uploads`.
Deviations from the plan / cards:
- Docs: `PATCH /pages/{uuid}` saves into the draft (the web editor's path) with `revision` required for `markdown`; publishing is its
  own call. Delete / duplicate / unpublish / archive / image icons: web only.
- Collab: besides the token, the API has the browser's bootstrap + live draft save (a native editor must save). Docs only.
- Reviews: one `POST …/reviews/{review}/decision {decision}` (the web's endpoint) instead of `approve` / `request-changes`; threads are
  the review's inline comments. Backlinks are `GET /pages/{uuid}/links` (`related` + `referenced_by`). Smart links:
  `GET /links/resolve?workspace=&refs[]=&urls[]=` (batch).
- Whiteboards: `GET /whiteboards/{uuid}` (+ ETag); no `export.png` — the last saved preview (`preview_image_url`).
- Releases are not paginated (the web's order, ≤ a project's releases); plans are.
- Files: `POST /uploads` returns the public URL + a Markdown snippet (the web editor's model), no upload id / `GET /files/{id}` /
  signed URLs / thumbnails. Attachments and bookmark files now check the storage limit (402).
- `## API (ready)` texts for BRAI-198, 199, 200, 201, 202, 203, 206, 209, 212, 213, 214, 267 and the web-only text for BRAI-207, 208,
  210 are in `docs/ai/API.md` → "Mobile card texts".


## Phase 08 — calendar writes, booking, helpdesk, leads, portals, insights

Desktop-app board cards 698–704 (epic BRAI-417, card 697): BRAI-418 (rest) … BRAI-423 are "API ·" cards, built in card
order, same flow and definition of done as phases 06 / 07 (`feat/app-api-<name>` from `release/v1.49.0`, merged back
`--no-ff`; PHPUnit incl. Passport → 401, other users / workspaces → 404, role rules, 422, pagination, 402 where the web
has a limit; e2e spec; `API.md` rows + mobile card texts; OpenAPI via `TEST_DB_SUFFIX=p08 composer openapi`). BRAI-424
is not an API card: only its `API.md` part (the "web only — open in browser" texts). New spec tags from 280 on.

**Public-facing modules** (helpdesk customers, lead forms, client portals, feedback portals, booking pages) have public
routes and guest users. The App API is for signed-in workspace members only: it exposes the member / agent side, never
the public or guest side, and never more customer data than the web's agent / member view shows. Builders and
settings (booking page builder, SLA rules, lead form builder, portal settings, share links): web only.
External providers: the API never connects them (OAuth stays web only); writes go through the same provider services
as the web (`CalendarProviders`), faked in tests.

### BRAI-418 calendar writes, meetings, ICS feed link (`feat/app-api-calendar-writes`)
- `POST /calendar/events` `{connection_id, title, date, time?, end_date?, end_time?, description?, location?, timezone?,
  conference?, attendees?, request_id?}` on the user's own writable calendar (the web's quick create +
  `CalendarProviders::writable`), `GET|PATCH|DELETE /calendar/events/{id}` with the list's `event:<connection>:<event>` id.
  Provider errors keep the web's 422 `errors.calendar`, in the API error shape.
- Meetings (Meet / Teams on an issue or doc): `GET /calendar/meeting-calendars`, `GET /meetings?subject_type=&subject_id=`,
  `POST /meetings`, `DELETE /meetings/{id}?cancel_event=` — extract `MeetingController`'s scheduling / removal / row into
  `Support\Meetings\MeetingScheduler` (+ presenter) and make the web use it.
- `GET|PUT|DELETE /me/calendar-feed` + `POST /me/calendar-feed/reset`: the private ICS link (the web's "Subscribe in other
  apps"); extract the options / payload of `CalendarFeedController` into `Support\CalendarFeed\CalendarFeeds`.

### BRAI-419 bookings (`feat/app-api-bookings`)
- `GET /bookings?status=upcoming|past|cancelled` (the host's own bookings, as the booking page's "Bookings" tab),
  `GET /bookings/{uuid}`, `POST /bookings/{uuid}/cancel` (host cancel, `BookingService::cancel`), `GET /booking-pages` (read).
  Reschedule is the guest's (public manage link) on the web: not offered to hosts unless the web has it.

### BRAI-420 helpdesk agent side (`feat/app-api-helpdesk`)
- `GET /projects/{id}/helpdesks`, `GET /helpdesks/{id}/tickets?status=&assignee=&sla=`, `GET /tickets/{id}` (thread, the
  contact as the agent view shows it, SLA state), `POST /tickets/{id}/replies` (reply / internal note), `PATCH /tickets/{id}`
  (status, assignee, priority) — the web's agent services, shared code extracted where the web holds it inline.

### BRAI-421 leads (`feat/app-api-leads`)
- `GET /projects/{id}/lead-forms`, `GET /lead-forms/{id}/leads` (pipeline stages + cards, answers, UTM as the web's lead view),
  `PATCH /leads/{id}` (stage, assignee), `POST /leads/{id}/notes` (if the web has notes).

### BRAI-422 feedback + client portals (`feat/app-api-portals`)
- `GET /feedback-portals/{id}/posts`, `PATCH /feedback-posts/{id}` (status), `GET /client-portals/{id}/deliverables` (read,
  approval state). Merging, settings, share links: web only.

### BRAI-423 insights, workload, project health, team time (`feat/app-api-insights`)
- Read-only summaries, role-checked like the web: `GET /workspaces/{slug}/insights?range=`, `GET /projects/{id}/health`,
  `GET /workspaces/{slug}/workload?week=`, `GET /workspaces/{slug}/time-report?…`. Exports: web only.

### BRAI-424 web-only mobile cards (docs only)
- `API.md` → "Mobile card texts": `## API: web only — open in browser` for BRAI-195, 207, 208, 210, 223, 224, 227, 234, 235,
  239, 242, 244, 245, 250, 251, 253, 254, 255, 257, 260, 261, and the matching rows. The board is not touched.

### Phase 08 — result
Merged into `release/v1.49.0`: `feat/app-api-calendar-writes` (BRAI-418 rest), `feat/app-api-bookings` (BRAI-419),
`feat/app-api-helpdesk` (BRAI-420), `feat/app-api-leads` (BRAI-421), `feat/app-api-portals` (BRAI-422),
`feat/app-api-insights` (BRAI-423), `docs/app-api-web-only` (BRAI-424, API.md only). No migrations. New spec tags: Bookings 280,
Helpdesk 290, Leads 300, Portals 310, Insights 320 (meetings and the feed link are in Calendar). e2e: `app-api-calendar-writes`,
`-bookings`, `-helpdesk`, `-leads`, `-portals`, `-insights`. API.md starts its Endpoints with the complete overview table.
Deviations from the plan / cards:
- Calendar writes are `POST /calendar/events` + `GET|PATCH|DELETE /calendar/events/{id}` with the list's `event:<connection>:<id>`
  id (not per connection path); meetings are `GET|POST /meetings` (`subject_type` + `subject_id`) + `DELETE /meetings/{id}` +
  `POST /meetings/{id}/notes` (the notes doc, BRAI-225 asks for notes); the ICS link is `GET|PUT|DELETE /me/calendar-feed` +
  `POST /me/calendar-feed/reset`. Default time zone = the user's.
- Bookings: no host reschedule (the web has none; rescheduling is the guest's manage link). Leads: no notes (the web has none);
  `GET /lead-forms/{id}/analytics` added for BRAI-236. Helpdesk: reply bodies are Markdown without mentions; ticket attachments
  from the app later (links from `POST /uploads` meanwhile). Portals: feedback = project admins (as the web), plus
  `POST /feedback-posts/{id}/moderate`; client portal read-only.
- Insights: one `GET /workspaces/{slug}/insights/{tab}` for the five tabs; workload is per project (as the web), not per
  workspace; "team time" = the Insights team tab — the billing time overview (amounts) and all exports stay web only.

## Fix: MCP `update-page` through `PageWrites` (`fix/mcp-update-page-safe`)
**Problem:** `update-page` wrote Markdown straight into a published page's `content` (`discardDraft` + `update`): it threw
away an unpublished draft and ignored live (Yjs) editors; no revision check. The App API (BRAI-411) already writes safely.
**Plan:**
- `get-page` returns `status`, `edit_revision`, `has_unpublished_changes`, `draft_markdown` (editors, when a draft exists)
  and `being_edited`. `markdown` stays the published text (unchanged for readers / today's agents).
- `update-page` takes optional `expected_revision`; stale → tool error with the current revision, nothing changed (checked
  before a move and again on the locked row). Without it the tool uses the revision it just read, so a save landing in
  between is never overwritten (re-read and retried, at most 3 times).
- Body writes only through `PageWrites`: never-published → `saveDraft` (as the web); published, no draft, nobody editing,
  no review switch → `publishChanges` (new version "Edited via MCP", watchers notified); published with a draft, live
  editors or "review required" → `saveDraft` into the draft, result says "publish it in the app". Replacing an existing
  draft needs `expected_revision` (else refused: the agent must have read `draft_markdown`); `append` adds to the draft.
- Live signal: `PageCollab` had none (its state only says a document existed). New `PageCollab::editingNow`: a presence
  heartbeat with `editing` (browsers, every 20 s, 45 s window) or a realtime state store in the last 45 s (typing,
  also native clients). Best effort; the revision check is the guarantee.
- Title-only: renames as before (published: version recorded) but keeps the draft (its title follows); "review
  required" waits in the draft like the web's rename.
- Tests `tests/Feature/Mcp/UpdatePageToolTest.php`; docs: KNOWLEDGE, CONVENTIONS, server instructions. No MCP e2e
  pattern exists (MCP is covered by PHPUnit only), so no e2e.

## Phase 09 — offline sync + realtime API

Desktop-app board cards 705 (BRAI-425) and 706 (BRAI-426), same flow and definition of done as phases 06–08
(`feat/app-api-<name>` from `release/v1.49.0`, merged back `--no-ff`; PHPUnit incl. Passport → 401 and other users' data
never showing up; e2e spec; `API.md` rows + mobile card texts; OpenAPI via `TEST_DB_SUFFIX=p09 composer openapi`). New
spec tags: Sync 330, Realtime 340. Plus a doc fix of the root `AGENTS.md` (`docs/agents-postgres-rules`).

### BRAI-425 offline sync (`feat/app-api-sync`)
The web's offline mode (BRAI-82) already has the write side: `App\Support\Offline\OfflineSync` (idempotent per
(user, uuid) in `offline_mutations`, field-level three-way merge, 409 conflicts, 410 deleted, 424 waiting). Native
clients get the same engine, batched, plus a server-side delta feed (the web builds its snapshots from page props).
- `POST /api/v1/sync` `{changes: [{request_id, type, target?, base?, data}]}` (≤ 50, applied in order) → 200
  `{data: [result]}`, one result per change in the same order: `applied` 200 / `conflict` 409 (nothing of the
  conflicting fields written, the server value comes back) / `deleted` 410 / `waiting` 424 / `forbidden` 403 /
  `invalid` 422 (+ `errors`) / `rate_limited` 429 / `error` 500. One bad change never fails the others.
  `request_id` = OfflineSync's mutation uuid (replay → the stored outcome + `replayed: true`; same id, other
  content → `invalid`). Same types as the web (`list_task.*`, `issue.*`, `capture.create`, `my_day.*`) + new
  `work.done` (the circle of My Work / My Day, `WorkCompletion::setDone`, base `done`). Budget 240 changes / min /
  user (the web's per-mutation throttle). `capture.create` gets the app's name as `source` (as `POST /captures`),
  and `CaptureMutations` now goes through `CaptureInbox::create` (no second copy).
- `GET /api/v1/sync/changes?cursor=&limit=&scopes=work,my_day,captures,task_lists&task_lists=1,2` → `{data:
  [change], next_cursor, has_more, reset, project_ids}`; change = `{type: work|task_list|task|capture|my_day, id,
  op: upsert|delete, project_id, updated_at, data}` with the existing resources (WorkItemResource, TaskListResource,
  TaskResource, CaptureResource, MyDayResource). One opaque cursor: it remembers the scope, the run's phase and
  keyset position, the time of the last complete run (−10 s overlap) and a hash of My Day (sent only when it
  changed). No / unknown / other user's / > 30 days old cursor or a changed scope → a full run (`reset: true`:
  afterwards drop what the run didn't send). `project_ids` = projects the user can open now (drop cached rows of
  others: lost access, trashed project / workspace).
- Deletions: issues / tasks / task lists are soft-deleted (`deleted_at`, also a trashed board / list); captures are
  deleted for good, and work that is reassigned leaves the user's scope without a trace → **additive migration
  `sync_tombstones`** (user, entity type, entity id, reason `deleted|unassigned`, created_at; pruned after 30 days):
  written by `Capture::deleted` and by the Item / TaskListItem observers when the assignee changes.
- Shared code: `App\Support\Sync\{ChangeFeed,SyncCursor}`, `App\Models\SyncTombstone`, `Offline\Appliers\WorkMutations`,
  `FieldMerge::BOOL`. Controller `AppApi\SyncController` (tag Sync 330).
- Tests `tests/Feature/AppApi/SyncTest.php` (+ the web's `OfflineSyncTest` for the shared appliers); e2e
  `app-api-sync.spec.ts`. Mobile cards: BRAI-263, BRAI-264, BRAI-204.

### BRAI-426 realtime for token clients (`feat/app-api-realtime`)
There is no Reverb / Laravel broadcasting: realtime is the app's own **live channels** (BRAI-151) on the realtime
server (`realtime/`, `/collab?live=1`), joined with a short-lived HMAC token listing the channels Brainitive allowed
(`LiveChannels`, re-checked every minute by the realtime server). The card's `POST /broadcasting/auth` becomes:
- `GET /api/v1/realtime` → `{data: {enabled, url (absolute ws/wss), token_endpoint, token_ttl, max_channels,
  channel_types, protocol}}` (connection info; `enabled: false` with nulls when live channels are off).
- `POST /api/v1/realtime/token` `{channels: [≤ 50]}` → `{data: {token, expires_at, url, channels, denied, user}}`
  — the web's `LiveTokenController` answer, through the same `LiveChannels::authorize` (one copy of the rules).
  404 when live channels are off. Passport tokens stay 401; a suspended user gets no channels.
- The event catalog (every channel, event, payload, when) in `API.md` → Realtime. `X-Live-Client` works for app
  writes too (echo suppression), since BoardLive reads the authenticated user of any guard.
- Tests `tests/Feature/AppApi/RealtimeTest.php`; e2e `app-api-realtime.spec.ts`. Mobile cards: BRAI-196, BRAI-265, BRAI-208.

### AGENTS.md doc fix (`docs/agents-postgres-rules`)
"Cursor Cloud specific instructions" still said SQLite: PostgreSQL only, `brainitive_test` / `TEST_DB_SUFFIX`, e2e
`brainitive_e2e_<port>`, never `migrate:fresh` / `db:wipe` / … against the `.env` database, commit identity, no push
without the owner, no broad `pkill`.

### Phase 09 — result
Merged into `release/v1.49.0`: `feat/app-api-sync` (BRAI-425), `feat/app-api-realtime` (BRAI-426), `docs/agents-postgres-rules`
(AGENTS.md). New spec tags Sync 330, Realtime 340. e2e: `app-api-sync`, `app-api-realtime`.
- **Migration (additive):** `2026_11_08_100000_create_sync_tombstones_table` (`sync_tombstones`, pruned after 30 days). Not run
  on the dev database — only in the test / e2e databases.
- Deviations from the cards: BRAI-425 asked for `POST /sync` with one mutation and `GET /sync/snapshot?scope=`; built as a batch
  (≤ 50, per-change results) and one cursor-based delta feed `GET /sync/changes` (full run = the snapshot, then deltas with
  deletions) — one endpoint for both, and it can report deletes and lost access, which snapshots per scope can't. BRAI-426
  asked for `POST /broadcasting/auth` (Reverb); the app has no Laravel broadcasting, so it is `POST /realtime/token` for the
  existing live channels + `GET /realtime`.
- Deferred: closing an open socket when its device is revoked (the realtime server re-checks per user, KNOWLEDGE → phase 09
  realtime); docs in the offline feed (BRAI-204 uses the docs endpoints); the web's outbox doesn't use `work.done` yet;
  `.env.example` still shows `DB_CONNECTION=sqlite`.

## Fix: revoked devices keep their live socket (`fix/live-revoked-devices`)
Found in phase 09 (BRAI-426): a revoked app device (`AppTokens::revoke`: sign-out, remote sign-out, refresh reuse,
password change, suspension, device limit) gets no new live token, but a socket it already opened kept its channels,
because the realtime server re-checks access per user, not per device.
- **Token:** `LiveToken::issue/grant` take an optional device id → claim `dev` (int) in tokens of `POST /api/v1/realtime/token`
  (`AppTokens::currentDevice`). The web's `live.token` stays without `dev`. `realtime/src/live/token.js` accepts an optional
  positive integer `dev`.
- **Hub:** a socket remembers its device (`client.device`, fixed for the socket's life like the user; a re-`auth` with another
  device / without one closes 4401). A revoked device is closed with **4410 "Device signed out"** and remembered for an hour,
  so a still-valid live token of that device can't reconnect.
- **Re-check (the guarantee, ≤ 60 s):** the minute re-check also sends each user's connected device ids
  (`users.*.devices`, also for users with no subscriptions) and `LiveInternalController` answers `revoked_devices` (ids that
  are gone, revoked or not that user's). Old realtime ↔ new Laravel and vice versa stay compatible (optional fields).
- **Push (seconds, best effort):** `AppTokens::revoke` → `Live::deviceRevoked($id)` → after commit, deferred, one loopback
  `POST /internal/live/devices/revoked {devices: [ids]}` with the publish key and the publish endpoint's guards. When it fails
  the re-check still closes the socket.
- Docs: API.md → Realtime (close code 4410 = signed out on this device: drop tokens, show sign-in), ARCHITECTURE /
  KNOWLEDGE (live channels). No migration, no API shape change (OpenAPI unchanged).
- Tests: `tests/Feature/AppApi/RealtimeTest.php` (dev claim; web token without), `tests/Feature/Live/LiveChannelsTest.php` or
  a new internal-access test (revoked_devices), `LivePublishTest` (push), `realtime/test/live.test.js` (revoked device
  closed by push and by re-check; web socket + the same user's other device untouched; reconnect refused).

### Fix: revoked devices — result
Built as planned: `dev` claim in App API live tokens, close code **4410 "Device signed out"**, push on revoke
(`POST /internal/live/devices/revoked`) + the minute re-check (`users.*.devices` → `revoked_devices`) as the guarantee. No
migration; API shapes unchanged (OpenAPI: only the token endpoint's description). Tests: `RealtimeTest` (dev claim, push on
remote sign-out / sign-out everywhere, re-check answer), `LiveChannelsTest` (web tokens without `dev`, validation),
`LivePublishTest` (push after commit), `realtime/test/live.test.js` (push + re-check close 4410, web socket and the user's other
devices untouched, reconnect refused, device fixed per socket), e2e `app-api-realtime` (logout closes the socket with 4410).
- **Deploy:** restart the realtime service on prod (`127.0.0.1:6201`) and dev (`:6202`) after this deploys — `realtime/` changed
  (KNOWLEDGE: restart after every deploy that touches it). Until then the old server ignores `dev` and answers 404 to the push
  (reported, events keep flowing — a 404 there doesn't pause publishing), i.e. today's per-user behaviour; nothing breaks.

## BRAI-441 sync feed v2 (`feat/app-api-sync-v2`)
Gaps the desktop offline mode found (desktop `docs/tasks/brai-366.md` → "API gaps"). App API only; the web's `POST /offline/sync`
answers stay as they are.
1. **Entity shape:** `POST /api/v1/sync` renders `entity` with the feed's resources, looked up fresh from the outcome's
   entity type + id (also on replays: `OfflineSync::replay` now carries both) — `issue.*` / `work.done` → WorkItem, `list_task.create|update`
   → Task, `list_task.reorder` → the task list (with `can`), `capture.create` → Capture, `my_day.*` → My Day, `issue.comment` → Comment.
   New `record_type` (`work|task|task_list|capture|my_day|comment`) says which feed record `entity` replaces. Gone / no longer
   visible → `entity: null`.
2. **`can` on `task_list` records:** `update`, `delete`, `manage_items` (shared `TaskListDetailResource::abilities`, computed once per
   project). Role changes don't touch a list's `updated_at`, so the cursor keeps a hash of the abilities: when it changes, the next
   delta run re-sends every kept list.
3. **My Day after `work.done`:** the My Day hash already covers each item's `done` + `updated_at` → the next run re-sends `my_day`.
   Test + doc.
4. **`task_lists=mine`** (alone or with ids): pinned lists (sidebar pins, every project), lists with my open assigned tasks, lists
   where I created or was assigned a task changed in the last 30 days, lists I created in the last 30 days — only in projects I can
   open, not trashed, explicit ids first, at most 50. Resolved at the start of every run; the response says which (`task_lists`).
5. **Additive list changes:** cursor v2 keeps the resolved list set; at each run start (and when `task_lists` changes) the diff
   against the previous set gives `fresh` lists (a full pass inside a delta run: the list + all its tasks) and `gone` lists (a
   `task_list` delete). Scopes / captures-filter changes, expired / foreign / unknown cursors still start a full run. **v1 cursors
   keep working** (decoded and mapped by phase index; their first run start re-sends the lists with `can`); malformed ones reset.
6. **`captures=inbox|all`** (default all): inbox = neither processed nor archived. In a delta run a capture that left the inbox
   comes as a `capture` delete. Changing the filter = a full run.
Tests in `SyncTest`; e2e step in `app-api-sync.spec.ts`; API.md Sync, BRAI-263 / 264 texts; OpenAPI. No migration.

### BRAI-441 — result
Built as planned. New: `Support\Sync\SyncRecords`, `SyncTaskListResource`, `TaskListDetailResource::abilities`, cursor v2
(phase by name, `kept` / `fresh` / `gone`, abilities hash, captures filter; v1 read), answer field `task_lists`, result field
`record_type`. No migration. Tests: 9 new in `SyncTest` (entities incl. replay / gone, `can` + role change, My Day after
`work.done`, `mine` across workspaces + hidden / other users' lists, cap, additive lists incl. a self-changing `mine` set, a
list change mid-run, v1 cursors end-of-run / mid-run / unreadable, inbox filter); e2e `app-api-sync` second test.

## Security review fixes (BRAI-377) (`fix/app-api-security-review`)
Findings of the App API security review; the design is the owner's (decided 2026-11-02).
- **M1 — TV clients get a narrow scope.** `config/app-api.php` → `clients.*.abilities` (route names without `app.v1.`):
  desktop / mobile `['*']`; `tv` = `me.show`, `today`, `work.index`, `work.update` (done), `my-day.show`,
  `my-day.items.update` (done / undone), `calendar.events`, `calendar.connections`, `notifications.index`,
  `notifications.unread-count`, `notifications.read`, `auth.logout`, `realtime.show`, `realtime.token` (+ the public
  `instance`, `auth.refresh`). Middleware `EnsureAppClientAbility` on the `auth:app` group: anything else → 403
  `client_not_allowed` (AppApiErrors shape, constant + CODES doc). `/auth/web-session` never for device-code clients.
  `/app/device`: the coarse IP (`203.0.113.x`) and "started N min ago" (stored with the device code in the cache: no
  migration), "Name reported by the device", copy that says what the TV may do.
- **M2 — no login CSRF / silent account swap at `/app/session/{code}`.** GET consumes the code and binds the pending
  sign-in to this browser session (5 min to confirm). Different user signed in → confirm page "You're signed in as A.
  Continue as B?"; nobody signed in and no ` BrainitiveDesktop/` UA → "Sign in as B on this browser?"; desktop webview
  with nobody signed in and the same user → one step as before. POST `/app/session` (CSRF) = Continue / Cancel. `?to=`
  rules unchanged.
- **M3 — revoking a device ends its web session.** `/app/session` stores `app_device_id` in the session; web middleware
  `EndRevokedAppDeviceSessions` checks the device (one indexed query, cached 60 s per device; `AppTokens::revoke` forgets
  the cache so the next request sees it) and logs the session out (remember token cycled). Refresh reuse revokes only
  that device, which now ends its webview session too (follow-up `fix/app-api-reuse-scope`).
- **L1 — collab sockets of revoked devices.** `CollabToken` gets `dev` for App API callers (`PageCollab::session(…, $device)`);
  `internal/collab/access` takes `devices` [{id, user_id}] → `revoked_devices` (shared with the live endpoint:
  `AppTokens::revokedAmong`); the collab server sends them on connect and in the minute re-check and closes revoked
  devices' sockets with 4410; the live push (`/internal/live/devices/revoked`) closes collab sockets too.
- **L2 — `SignOutEverywhere` covers app devices:** `run($user, $keepSessionId, $appReason)` calls
  `AppTokens::revokeAllFor`; the direct calls in password change / reset and suspension go.
- Tests: `AppClientAbilitiesTest` (every blocked area + the allowed list for tv; desktop unaffected), `AppSignInTest`
  (confirm pages, desktop UA, CSRF, `?to=`), `AppWebSessionRevocationTest`, `PageCollabTest` / collab access, realtime
  `server.test.js` + `live.test.js`; e2e `app-sign-in`, `app-device-sign-in`, `app-api-realtime`, `app-devices`.

### Security review fixes — result
Built as planned, no migration (the device code's IP + created time live in its cache payload). New:
`EnsureAppClientAbility` (priority before `SubstituteBindings`), `AppClients::allows`, `AppApiErrors::CLIENT_NOT_ALLOWED`,
`AppDeviceCodes::coarseIp`, `AppWebSessions` + `EndRevokedAppDeviceSessions` (web group), `Pages/AppAuth/Session.tsx` +
`POST /app/session` (`app.session.confirm`), `AppTokens::revokedAmong`, collab `dev` claim, `createCollabServer().revokeDevices`
+ `attachLive({onDevicesRevoked})`. `SignOutEverywhere::run($user, $keepSessionId, $appReason)` revokes the apps; the direct
`revokeAllFor` calls in password change / reset and suspension are gone. Follow-up `fix/app-api-reuse-scope`: refresh reuse
revokes only that device (+ its webview session, + email), not `SignOutEverywhere`; the mobile webview marker
` BrainitiveMobile/<version>` gets the one-step `/app/session` like ` BrainitiveDesktop/`.
Tests: `AppClientAbilitiesTest` (6), `AppWebSessionRevocationTest` (5), `AppSignInTest` (+7, webview tests now send the desktop UA),
`AppAuthTokensTest` (+reuse everywhere), `DeviceCodeSignInTest` (+2), `PageCollabTest` (+2), takeover asserts in
`AuthOAuthHardeningTest` / `MicrosoftLoginTest`; realtime `server.test.js` (+4), `live.test.js` (+1); e2e `app-sign-in` (+2),
`app-device-sign-in`, `app-api-realtime` (+1, document socket 4410), `app-devices` (+1). Deploy: restart the realtime service.

## Release materials (`docs/release-v1.49.0-materials`)
In-app changelog entry v1.49.0 (`HelpModal.tsx`), homepage (`Welcome.tsx`: "Brainitive on your desktop" spotlight card +
`#desktop` section with "Open API for apps", both marked beta / coming soon; `e2e/tests/homepage.spec.ts`), docs site
(`guide/desktop-app.md`, `integrations/app-api.md`, update of `self-hosting/desktop-and-mobile-apps.md`; the sidebar builds
itself from the folders and `order:`), public download page `GET /download` (`DesktopDownloadController`, `Pages/Download.tsx`,
`config/desktop.php` ← `DESKTOP_DOWNLOAD_{MACOS,WINDOWS,LINUX}_URL`, empty = disabled "Coming soon"; `tests/Feature/DesktopDownloadTest.php`,
`e2e/tests/desktop-download.spec.ts`). The Cloud homepage is the separate Astro website (brainitive-website repo): its own copy of
the desktop section is the owner's to do.

## Deploy checklist v1.49.0
Before: nothing to build specially; `desktop/` is its own repo and is not part of this deploy.
1. **Migration:** `php artisan migrate` — additive, one new table `sync_tombstones` (`2026_11_08_100000_create_sync_tombstones_table`).
2. **Realtime service:** restart after the deploy (`realtime/` changed): prod `127.0.0.1:6201`, dev `:6202`
   (`sudo systemctl restart brainitive-realtime` of each instance). Until then old servers ignore the device claim (per-user behaviour, nothing breaks);
   since BRAI-377 the restart also makes revoked devices lose their live-doc (collab) sockets.
3. **nginx:** check `/api/v1` (Authorization header unchanged, no basic-auth / IP allow-list on prod; dev's basic auth must not cover
   it if the apps are tested against dev), `/app/authorize`, `/app/device`, `/app/session/` and the websocket `/collab` are not blocked.
   The `brainitive.app` → `https://www` redirect is already fixed (verify it still answers 301, not a loop).
4. **Env** (prod and dev `.env`):
   - `APP_API_MIN_DESKTOP_VERSION` (default `0.1.0`; raise only when an app version must be forced to update) and `APP_API_MIN_MOBILE_VERSION`;
   - the download links `DESKTOP_DOWNLOAD_MACOS_URL`, `DESKTOP_DOWNLOAD_WINDOWS_URL`, `DESKTOP_DOWNLOAD_LINUX_URL` — leave empty until builds exist (phase 10 fills them);
   - prod has no config cache, so no `config:cache` step; otherwise run `php artisan optimize` after editing `.env`.
5. **Scheduler:** `routes/console.php` already schedules `model:prune --model=SyncTombstone` daily at 04:37 (tombstones older than 30 days). Check the
   cron `schedule:run` runs on prod and dev, and that `php artisan schedule:list` shows the entry.
6. **Docs site:** push triggers `docs.brainitive.app` deploy (new pages Desktop app and App API). Docs-only pushes skip app CI.
7. **Smoke tests after the deploy:**
   - `curl -s https://<host>/api/v1/instance` answers 200 JSON with `min_versions`;
   - `curl -s -o /dev/null -w '%{http_code}' https://<host>/api/v1/me` answers 401 (not 404 / 502);
   - sign in from the desktop app (dev build pointed at dev): browser opens `/app/authorize`, app is signed in, the device shows under Profile → Notifications → Desktop & mobile apps, Revoke signs the app out;
   - `/app/device` opens signed in and asks for a code; `/download` renders three "Coming soon" tiles;
   - `/docs/api` opens as an instance admin;
   - the homepage shows the new "Brainitive on your desktop" section; Help → Changelog lists v1.49.0;
   - realtime: open a board in two browsers, a card move shows live (the service restarted correctly).
