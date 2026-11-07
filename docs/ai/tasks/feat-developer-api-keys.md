# Developer API: personal API keys for the App API

**Branch:** feat/developer-api-keys (from `pre-release`)
**Status:** done (2026-11-07; local commits on feat/developer-api-keys, not pushed)
**Started:** 2026-11-07
**Release:** v1.52.0 (together with feat/board-workflow-rules)
**Board card:** none yet (MCP not used from this worktree; the lead creates / links it)

## Problem / goal
People want to script Brainitive (CI jobs, small internal tools, Zapier-style glue) with the same JSON API the
desktop / mobile apps use (`/api/v1`). Today tokens are only issued to the first-party apps. Goal: every user
(Cloud, every plan, and self-hosted) can create **fine-grained personal API keys** and call `/api/v1` with
them, with public documentation.

## Approach

### Keys
- Table `api_keys`: `user_id`, `name`, `prefix` (first 12 chars of the key, shown in lists), `token_hash`
  (sha256 of the whole key, unique index; the secret is never stored), `scope_type`
  (`all | workspaces | projects | boards`), `scope_ids` (json list of ids), `abilities` (json
  `{area: none|read|write}`), `can_delete`, `expires_at`, `last_used_at`, `last_used_ip`, `revoked_at`,
  `revoked_reason`, `created_from_ip`, timestamps.
- Format `brai_` + 43 base62 chars (≈ 256 bits, `random_bytes`), shown **once** after creation. `brai_`
  can't collide with the app tokens (`bra_`, `brr_`).
- Lookup by the sha256 hash on a unique index (the hash is the lookup key, so no secret-dependent string
  comparison happens in PHP; a `hash_equals` re-check guards against a collation surprise).
- Workspace column `api_keys_enabled` (default true): workspace admins turn keys off for their workspace.
- Instance switch `API_KEYS_ENABLED` (config `api-keys.enabled`, default true): off → every key 401
  `api_keys_disabled`, the profile page shows that they are off, creating is refused.
- Revoked on: user revoke, password change / reset / suspension / "sign out everywhere" (`SignOutEverywhere`),
  account deletion (cascade). An email goes to the user when a key is created.

### Authentication
- Guard `app` (AppTokens::userFromRequest) also accepts `Bearer brai_…` → `ApiKeys::userFromRequest`: active
  (not revoked, not expired) key of a not-suspended user; the key goes on the request
  (`ApiKeys::ATTRIBUTE`). Failure reason → 401 with code `api_key_expired | api_key_revoked |
  api_keys_disabled` (else `unauthenticated`). `last_used_at` / `last_used_ip` written at most once a minute.
- `EnsureAppClientAbility` lets API keys through (they have no device); `AuthorizeApiKey` decides for them.

### Central authorization (`AuthorizeApiKey` + `ApiKeyRoutes`)
One mechanism: a **central map** `App\Support\ApiKeys\ApiKeyRoutes::MAP`, keyed by route name (like the TV's
`clients.*.abilities`): `area` (or `null` = no area, e.g. `GET /me`), optional `level` override, optional
`scope` rule (`workspace` = needs the whole workspace, `project` = needs the whole project (not limited to
boards), `boards_filtered` …) and the parameter to resolve. **Excluded routes are listed with `false`**;
a route missing from the map is denied too (403 `key_endpoint_not_allowed`), and
`ApiKeyRoutesTest` fails for any `/api/v1` route that is not in the map.

The middleware (runs after `auth:app`, before route-model binding — priority list like
`EnsureAppClientAbility`) does, for API-key requests only:
1. endpoint allowed? (map entry, not `false`) else 403 `key_endpoint_not_allowed`;
2. level: GET/HEAD = read, POST/PUT/PATCH = write, DELETE = write + the delete flag (overrides: unwatch,
   unlink, remove from My Day = write) → 403 `key_ability_denied` ("This key can't write issues and boards");
3. scope: resolves the route's first resource parameter (workspace slug, project, board, item, sprint, task
   list, page uuid, plan, release, collection, snippet, bookmark group, helpdesk, ticket, lead form, lead,
   portal, feedback post, deliverable, work log, item key, poll scope) to workspace / project / board and
   checks it → 403 `key_scope_denied` ("This key has no access to project ToolExpert") when the user can
   open it, the normal 404 when they can't (no names of things the user can't see), 403
   `workspace_api_keys_disabled` when the workspace turned keys off.

Defense in depth for ids in **bodies / queries** (move to another board, link an issue, convert a capture,
start a timer on an issue, meeting subjects …) and for everything the controllers check with policies: a
**deny-only `Gate::before`** (`ApiKeyGate`) that, during an API-key request for the key's user, locates any
model argument (Item, Board, Sprint, Page, Plan, Release, TaskList(Item), Snippet(Collection), Bookmark,
Helpdesk, Ticket, LeadForm, Lead, Feedback*, Client*, TimeEntry, Meeting, Project, Workspace, Comment,
Attachment, PageReview) and returns `false` when it is out of scope or its area/level isn't granted
(`view*` = read, `delete*`/`forceDelete` = delete flag, anything else = write). It never grants.

Lists: the user's access helpers become key-aware for the key's own user only — `User::accessibleProjectIds()`,
`adminProjectIds()`, `hasWorkspaceAccess()`, `hasProjectAccess()` intersect with the key's scope
(`ApiKeyAccess::forUser`) — so every list built on them (My Work, My Day, today, search, capture
destinations, timesheet, work logs, calendar agenda …) is filtered in the query. Lists that read
`$user->workspaces()` / workspace relations directly (workspaces, projects of a workspace, boards of a
project, dated issues, search viewer workspaces) get explicit `ApiKeyAccess` filters. Board scope filters
board / issue lists; cross-project issue lists that can't filter by board are refused for board-limited
keys (`scope: project`).

### Scope semantics ("Where")
- `all`: every workspace of the user whose admins allow API keys — including ones joined later.
- `workspaces`: the chosen workspaces (∩ the user's, ∩ allowed).
- `projects`: the chosen projects; workspace-wide content (snippets, bookmarks, workspace members, workspace
  insights) is not included; the workspace itself is readable (name, slug) so the projects can be listed.
- `boards`: the chosen boards; Issues & boards endpoints only see those boards (board lists, issue lists,
  dated issues, sprints, backlog). Other granted areas see the **whole projects** those boards belong to
  (docs, task lists, plans and releases …). Project-wide issue aggregates (health, workload) are refused.
- Always ∩ the user's own access (policies still run): losing access to a project removes it from the key.
- Personal data (captures, calendar, bookings, timer) has no workspace: only the area decides.

### Areas ("What": None / Read / Read + write) — from the route groups
| Area key | Label | Routes |
|---|---|---|
| `issues` | Issues & boards | Boards, Issues (comments, watchers, attachments, activity, links, children), Sprints, project config, dated issues, insights / health / workload (read), polls in issues |
| `docs` | Docs & whiteboards | Docs, reviews, versions, page links, whiteboards (scene), polls in docs, `links/resolve` |
| `plans` | Plans & releases | Plans, Releases (read only in v1 of the App API) |
| `work` | My Work, tasks & captures | My Work, My Day, today, task lists, captures |
| `time` | Time tracking | Timer, work logs, timesheet |
| `calendar` | Calendar & bookings | Calendar events, meetings, bookings |
| `helpdesk` | Helpdesk, leads & feedback | Helpdesk, Leads, feedback + client portals |
| `library` | Snippets & bookmarks | Snippets, Bookmarks (workspace-wide) |
| `members` | Members | Workspace / project members (read only) |
Plus the **Delete** flag (off by default). No area needed: `GET /instance`, `GET /me` (workspaces filtered to
the scope), `GET /workspaces`, `GET /workspaces/{slug}`, `GET /workspaces/{slug}/projects`, `GET /projects/{id}`
(sections of areas the key can't read are left out). `POST /uploads` needs write on Issues or Docs. `GET /search`
needs read on every content area and a key not limited to boards.

### Never with an API key (403 `key_endpoint_not_allowed`)
Auth (logout, web session), `PATCH /me`, avatar, devices, invitations, creating workspaces / projects, project
templates, inviting members, navigation / pins, notifications + their preferences, calendar feed link (a
credential), connected apps, data export, realtime tokens (a socket outlives the key's revocation), live
co-editing tokens for docs / whiteboards (same reason; edit with `PATCH /pages/{uuid}`), offline sync
(`/sync`, `/sync/changes`: its change feed and appliers span every area — excluded rather than re-scoped).
API keys can't be managed with an API key (the management is web only).

### Rate limits
`throttle:app-api` for a `brai_` bearer: per key 120 reads/min and 60 writes/min (`config/api-keys.php`,
`API_KEYS_READ_PER_MINUTE` / `API_KEYS_WRITE_PER_MINUTE`), plus the existing 600/min per IP. Keyed by the
key's hash (no DB lookup, invalid keys are limited too). `X-RateLimit-Limit/Remaining`, 429 + `Retry-After`.

## Changes
- Migrations: `create_api_keys_table`, `add_api_keys_enabled_to_workspaces_table`.
- Models: `ApiKey` (casts, scopes `active()`), `User::apiKeys()`, Workspace cast.
- Support: `App\Support\ApiKeys\{ApiKeys, ApiKeyAreas, ApiKeyRoutes, ApiKeyAccess, ApiKeyLocator, ApiKeyGate}`.
- Middleware: `AuthorizeApiKey` (+ priority), `EnsureAppClientAbility` passes keys through.
- Guard: `AppTokens::userFromRequest` → keys; `AppApiErrors` key codes; `AppApiServiceProvider` limiter.
- User access helpers key-aware; list filters in Workspace/Project/Board/Issue(dated)/Search/Me/ProjectDetail.
- Web: `ApiKeyController` (`/profile/api-keys` index/create/store/destroy/regenerate), Inertia page
  `Profile/ApiKeys.tsx` + components, Profile card link, Connected apps lists keys (web + App API
  `GET /me/connected-apps` kind `api_key`, `DELETE api_key:<id>`), workspace settings card "Developer API"
  (`PATCH /workspaces/{workspace}/api-keys`, admins).
- Notification: `ApiKeyCreatedNotification` (account mail, EmailPreviews entry).
- OpenAPI: `x-brainitive-key-ability` + description line per operation, `apiKey` bearer scheme; regenerate
  `docs/api/openapi.json`.
- Docs-site: `integrations/developer-api.md` (guide), `api.md` (embedded reference from the committed
  spec, copied at build), `app-api.md` updated, `self-hosting/configuration.md` (`API_KEYS_*`).
- HelpModal `v1.52.0` entry ("Developer API and workflow rules for boards").

## Test plan
PHPUnit (`tests/Feature/ApiKeys/*`): creation (secret once, hash only, email), auth (ok / expired / revoked /
suspended / instance off / bad key), scopes (all / workspaces / projects / boards incl. list filtering + 403 /
404 on single resources, name not leaked), areas × levels, delete flag, every route declared, excluded
endpoints 403, workspace switch, rate-limit headers + 429, Gate (ids in bodies), password change revokes,
existing AppApi suites green. Playwright `e2e/tests/api-keys.spec.ts`: UI create (one board, Issues
read + write), copy the secret, list / create / move on that board 200, other project 403, delete 403,
revoke → 401.

## Docs to update
API.md (Developer API section + conventions), ARCHITECTURE (module entry), KNOWLEDGE (deny-only Gate::before,
key-aware user helpers, why sync / realtime / collab are excluded), CONVENTIONS (new App API route → declare it
in `ApiKeyRoutes`), skill add-app-api-endpoint (step: declare the area).

## Outcome
- Built as planned. Extra decisions made while building: search answers `places: []` for keys (place names aren't
  scoped); the 403 names the widest place out of scope (workspace, else project, else board); the create / regenerate
  response carries the secret with `Cache-Control: no-store` and Inertia history encryption; the workspace switch lives
  in Workspace settings → Legal → "Developer API"; the public reference is Scalar 1.75.1 (pinned, jsDelivr) on
  `docs-site/api.md`, fed by `docs-site/public/openapi.json`.
- Tests: `tests/Feature/ApiKeys/*` (auth, routes declared / excluded, areas × levels, scopes, workspace switch, rate
  limits, management, email), `OpenApiSpecTest` (scheme + extension + docs-site copy), e2e `api-keys.spec.ts`.

## Response audit (Codex reviews, v1.52.0)
Every route with an area was checked for fields of ANOTHER area in its answer. **Central fix:** models with the
`ScopedToApiKey` trait (Item, TimeEntry, Page, Plan, Release, Capture, SnippetCollection, Snippet, Bookmark,
BookmarkItem, BookingPage, Booking) only load during a key's request when the key READS the model's area and the row
is in its scope (`ApiKeyAccess::scopeModel`, fail closed) — so a relation of another area simply loads as null / empty
wherever it is used. Stored copies of another area's data are gated with `ApiKeyAccess::reads($area)`. System work
(derived links, reference pruning, sync-queue jobs) runs unscoped (`ApiKeyAccess::unscoped()`, Queue before/after).
`ApiKeySingleAreaSweepTest` calls every GET route with a one-area key per area and asserts no other area's markers.

| Route(s) | Declared area | Other-area data in the answer | Decision |
|---|---|---|---|
| `items.show/store/update/by-key` | issues | `release` (plans) | Release relation scoped → null without Plans |
| `items.activity` | issues | `changes.release_id` (plans), `meeting_*` (calendar), `work_log*` (time) entries | release change dropped; meeting / work-log entries filtered out without that area |
| `projects.config` | issues | `releases[]` (plans) | empty without Plans (`reads('plans')`) |
| `boards.show` | issues | swimlane rule `release_ids` (plans, ids only) | kept: ids, no names |
| `projects.health` | issues + plans | plan milestones | route needs both areas |
| `projects.workload` | issues | project members (names) | kept: the workload's own rows (documented) |
| `releases.show/index` | plans | `items[]` + progress (issues) | Item scope → no issues / zero counts without Issues |
| `plans.show` | plans | `rows[].issue` (issues) | `can('view')` → gate; rows' own stored names stay (plan data) |
| `pages.links`, `links.resolve` | docs | issues / tickets / plans | filtered by `ApiKeyGate::allows` (not_found / left out) |
| `pages.show`, versions | docs | smart-link anchors stored in the doc's HTML | kept: the doc's own text |
| `whiteboards.show` | docs | issue cards' `customData.itemId` | kept: ids inside the doc's own scene |
| `client-deliverables.show`, `client-portals.deliverables` | helpdesk | doc snapshot `html` / `markdown`, `title` (docs / plans) | null / empty without Docs (Plans for plan deliverables) |
| `feedback-portals.posts`, `feedback-posts.*` | helpdesk | linked `item` (issues) | Item scope → null |
| `bookings.*`, `booking-pages.index` | calendar | `card` (issues) | Item scope → null |
| `calendar.events`, `today` | calendar / work | issues, to-dos, external events, bookings | each source needs its area (not fetched otherwise) |
| `work.index`, `my-day.*`, `today` | work | issue rows (issues) | Item scope + `limitIssues`; completing an issue needs Issues write (`can('update')`) |
| `captures.*` | work | `target` title / breadcrumb / url (issue / doc / bookmark / snippet) | empty without the target's area; `convert` needs write on the target area |
| `captures.destinations` | work | boards (issues), pages (docs), lists (work), bookmarks / snippets (library) | gate per destination (`createItems` / `create` / `manageItems`), library scope; key's workspaces only |
| `work-logs.*`, `timesheet.show`, `timer.*` | time | `issue` block (issues) | Item scope → null; no stored key / title fallback for keys; timer checked first |
| `meetings.*` | calendar | subject (issue / doc), `notes_page` (docs) | subject loads through the scope (404 without its area); `notes` needs Docs write; notes page scoped |
| `projects.show` | — | sections per area, `member_count` (members) | sections per area; `member_count` null without Members |
| `me.show`, `workspaces.*`, `projects.index` | — | workspace / project names only | kept (scope-filtered) |
| `insights.show` | every area | team time, helpdesk, members | route needs every area |
| `search` | all content areas | everything | route needs every content area; `places` empty |
| snippets / bookmarks, members, helpdesk / leads routes | own area | none found | — |

## Open questions
- Plans & releases are read-only in the App API today, so "Read + write" there grants nothing more yet.
- A Brainitive board card was not created from this worktree (no MCP use here); the lead links it.
