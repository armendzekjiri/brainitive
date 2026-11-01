# App API v1 — registry (desktop + mobile)

The JSON API of the first-party **desktop** (Tauri) and **mobile** (Expo) apps: `/api/v1`, guard
`app`, routes in `routes/app-api.php`. It is **separate from MCP, the CLI and the browser extension**
(`/mcp`, `/api/cli/*`, `/api/ext/v1/*`): own tokens, own guard, own throttles. Only the business
logic underneath (Support services, policies) is shared.

- Machine-readable spec: [`docs/api/openapi.json`](../api/openapi.json) (OpenAPI 3.1, Scramble,
  committed so the mobile app can generate a typed client). Regenerate with **`composer openapi`**
  (in a worktree: `TEST_DB_SUFFIX=<name> composer openapi`); `tests/Feature/AppApi/OpenApiSpecTest.php`
  fails in CI when the file is stale. It runs in PHPUnit on purpose: Scramble reads column types from
  the database, so only the migrated test database gives the same file everywhere. Live UI:
  `/docs/api` (local env or instance admins only). Config: `config/scramble.php`,
  `App\Providers\AppApiDocsServiceProvider`, `App\Support\AppApi\OpenApi\*`.
- Recipe for a new endpoint: [`skills/add-app-api-endpoint.md`](./skills/add-app-api-endpoint.md).
- Every endpoint also gets an `## API (ready)` section on its card on the **Mobile app** board, so
  the mobile work only has to read the card (template below).
- Spec tags = the module headings below (`#[Group('Account', weight: 30)]` on the controller):
  Instance 10, Auth 20, Account 30, Devices 40, Invitations 50 (the "Invitations & onboarding"
  section), Workspaces 60, Projects 70. A new module gets a heading here, a weight, and an entry in `OpenApiSpecTest`.

## Conventions

| | |
|---|---|
| Auth | `Authorization: Bearer bra_…` (access, 15 min). Refresh `brr_…` (60 days, single use). Tokens never in URLs. |
| Responses | Wrapped: `{data: {...}}` (API Resources' default); lists `{data: [...], next_cursor}`. **DELETE → 204** No Content when it removes a resource; removing a *part* of one (e.g. `DELETE /me/avatar`) answers 200 with the updated parent instead. Only unwrapped exception: the OAuth token responses of `POST /auth/exchange` and `POST /auth/refresh` are unwrapped (`{token_type, access_token, expires_in, refresh_token, refresh_expires_in, device}`). Resources list their fields explicitly, never a model's `toArray()`. |
| Errors | `{"message": "…", "code": "…", "errors"?: {field: [..]}}` — 400 `bad_request` / `invalid_grant`, 401 `unauthenticated`, 402 `limit_reached` (+ `limit` object, see Invitations), 403 `forbidden`, 404 `not_found`, 405 `method_not_allowed`, 409 `conflict`, 410 `gone`, 422 `validation_failed`, 429 `rate_limited`, 500 `server_error`. 404 instead of 403 when the user must not learn that something exists. Documented once for all endpoints (`App\Support\AppApi\OpenApi`); a 402 needs `@throws LimitReached` on the controller method. |
| Dates | ISO-8601 UTC (`2026-11-01T09:16:04Z`). |
| Lists | Cursor pagination `?cursor=&limit=` (default 50, max 100) → `{data: [...], next_cursor}`; `updated_since` for sync. Server side: `ListRequest` (rules + `limit()` / `cursor()` / `updatedSince()`) and a `CursorCollection` subclass per list. An unknown / stale cursor starts again at the first page. |
| Writes | `Idempotency-Key` header accepted; updates carry `revision` / `updated_at` → 409 on conflict. |
| Limits | `throttle:app-api` 240/min per token + 600/min per IP; `throttle:app-auth` 20/min per IP on exchange / refresh. |
| CORS | None. Native clients only; browsers can't call it cross-origin. |
| Versioning | `info.version` of the spec is the **API** version (`1.0.0`, `config/scramble.php`), not the app release. Additive changes (new endpoint, new optional field) stay in v1; removing / renaming a field or changing a meaning needs `/api/v2`. Apps learn the server's minimum app versions from `GET /instance`. |

## Native sign-in

```
App                                   System browser                      Server
 │ verifier + state, S256 challenge                                          │
 │── open /app/authorize?client=desktop&code_challenge=…&state=…&device_name=… ──▶│
 │                                     │ sign in (password / Google / Microsoft / GitHub)
 │                                     │ consent "Sign in to Brainitive Desktop?" → Continue
 │◀── brainitive://auth/callback?code=…&state=… (code: 60 s, single use) ────│
 │── POST /api/v1/auth/exchange {code, code_verifier, device} ───────────────▶│ → device row + token pair
 │── POST /api/v1/auth/web-session (Bearer) → {url} ─────────────────────────▶│
 │   webview opens url (/app/session/<code>) → web session cookie → /dashboard
 │── POST /api/v1/auth/refresh {refresh_token} (before access expiry) ───────▶│ → new pair; reuse of an old one revokes the device
```

Token response (exchange + refresh):

```json
{ "token_type": "Bearer", "access_token": "bra_…", "expires_in": 900,
  "refresh_token": "brr_…", "refresh_expires_in": 5184000,
  "device": { "id": 12, "name": "Armend's MacBook", "platform": "macos" } }
```

Cancel on the consent page → `brainitive://auth/callback?error=access_denied&state=…`.

## The `## API (ready)` section on a mobile card

Each endpoint's card on the **Mobile app** board (`mobile-app`, project `brainitive`, workspace
`ndizeal-everything`) gets this at the end of its description (BRAI-380):

```
## API (ready)
- GET /api/v1/... — what it returns
- POST /api/v1/... — what it does
Scope: app · OpenAPI: docs/api/openapi.json#/<tag> · Registry: docs/ai/API.md#<module>
Built in: BRAI-xxx (desktop) · Mobile scope: Full | Read + light edit | Read-only
```

A feature the apps only open in the browser / webview gets `## API: web only — open in browser`
instead. BRAI-168 (Backend / mobile API inventory) links this registry.

## Endpoints

Columns: **Mobile** = scope in the mobile app (Full / Read + light edit / Read-only / web only);
**Card** = the Mobile app board card that carries the `## API` section.

### Instance
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/instance` | none (public) | first-run "connect a server" screen: check it's Brainitive, show its name + version, which sign-in buttons exist, refuse a too-old app (`min_versions`) | Full | BRAI-171 | ready (v1.49.0, BRAI-354) |

`{data: {name, url, version, api_version: "1", sign_in: {password, google, github, microsoft},
sign_up_open, email_verification_required, min_versions: {desktop, mobile}, authorize_path}}`.
Only what the login page already shows; sign-in flags use the Login page's rule
(`App\Support\Auth\SignInMethods`). `Cache-Control: public, max-age=300`. `version` is `null` when
the server can't read its changelog (a stripped package).

### Auth
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /app/authorize` (browser) | web session (`auth` + `verified`) | System browser: consent page "Sign in to Brainitive Desktop as …?" (`client`, `code_challenge` S256, `state`, `device_name?`). Invalid parameters → 400 error page, never a redirect to the app | Full (expo-auth-session) | BRAI-171 | ready |
| `POST /app/authorize` (browser) | web session + CSRF | Continue → `<redirect>?code=…&state=…` (code: 60 s, single use, PKCE-bound); Cancel → `?error=access_denied&state=…` | Full | BRAI-171 | ready |
| `POST /api/v1/auth/exchange` | public, `throttle:app-auth` | `{code, code_verifier, device: {name, platform, app_version, os_version?}}` → token response + `device` (unwrapped). Bad / used / expired code or verifier mismatch → 400 `invalid_grant`; platform not of the client → 422. Over `max_devices` → the least recently used device is signed out (`limit`) | Full | BRAI-171 | ready |
| `POST /api/v1/auth/refresh` | public, `throttle:app-auth` | `{refresh_token}` → new pair (unwrapped); the old pair is dead. Invalid / expired → 401 `invalid_grant`. A refresh token used **again** → device revoked (`refresh_reuse`) + email → 401 `invalid_grant`. **Send one refresh at a time** | Full | BRAI-172 | ready |
| `POST /api/v1/auth/logout` | `auth:app` | Sign this device out → 204 | Full | BRAI-174 | ready |
| `POST /api/v1/auth/web-session` | `auth:app` | → `{data: {url}}`: one-time (60 s) `/app/session/{code}` for the webview; append `?to=/path` (relative) to land elsewhere | Web only (desktop webview) | BRAI-177 | ready |
| `GET /app/session/{code}` (browser) | the code | Webview: signs that user into the web guard with remember-me (cookies survive restarts), signs out another signed-in user first, → `?to=` or `/dashboard`. Used / expired code, revoked device or suspended user → login page | Web only | BRAI-177 | ready |

### Account
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/me` | app | tray header, account menu, workspace switcher, limits | Full | BRAI-176 | ready (v1.49.0, BRAI-384) |
| `PATCH /api/v1/me` | app | edit name / bio / phone, sync the OS time zone, daily digest on/off | Full | BRAI-176 | ready (v1.49.0, BRAI-384) |
| `POST /api/v1/me/avatar` | app | upload a profile picture (multipart `avatar`, JPEG/PNG ≤ 2 MB) | Full | BRAI-176 | ready (v1.49.0, BRAI-384) |
| `DELETE /api/v1/me/avatar` | app | remove the profile picture | Full | BRAI-176 | ready (v1.49.0, BRAI-384) |

`GET /me` → `{data: {id, name, email, email_verified, email_verified_at, avatar_url, bio, phone,
timezone, effective_timezone, task_digest, is_instance_admin, created_at, limits_enforced,
workspaces: [{slug, name, role, is_owner, logo_url, billing_url, limits: {plan_name, free,
unlimited, members, projects, content_bytes, file_bytes, versions: {keep_latest, keep_days},
grace_until}}], device: {id, name, client, platform, app_version}}}`. The PATCH and both avatar
calls answer with the same object.
- `email_verified` is also `true` when the instance doesn't require verification.
- `timezone` is `null` until a browser or app sent one; `effective_timezone` is what reminders use.
- Limits come from the Entitlements contract (all `null` + `unlimited: true` on self-hosted);
  `billing_url` is the web "Plan & billing" page, `null` without billing.
- PATCH fields (all optional, the web Profile page's rules): `name`, `bio`, `phone`, `timezone`
  (IANA, old names accepted), `task_digest`. **`email` → 422**: a new address has to be verified,
  so it is changed on the web only. Password, account deletion and notification settings: web only.
- There is no language or theme on the server (the web app keeps the theme per browser), so the
  apps keep those locally.

### Devices
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/devices` | app | Settings → signed-in devices: the user's active installs, most recently used first → `{data: [{id, client, platform, name, app_version, os_version, has_push_token, current, last_used_at, created_at}]}` (≤ `max_devices`, not paginated). Never token hashes, IP or the push token. | Full | BRAI-173, BRAI-266 | ready |
| `PATCH /api/v1/devices/current` | app | After start / update / rename: `{name?, app_version?, os_version?, push_token?}` (only sent fields change; `push_token: null` removes it; stored encrypted, APNs / FCM delivery later) → `{data: device}`. The card said `POST /devices`: the row already exists after `/auth/exchange`, so it's an update of the calling device. | Full | BRAI-177, BRAI-266 | ready |
| `DELETE /api/v1/devices/{id}` | app | Remote sign-out of one of the user's own active devices → 204 (its tokens die at once; the calling device = sign out). Another user's / revoked / unknown id → 404. | Full | BRAI-173, BRAI-266 | ready |

Web: Profile → Notifications → "Desktop & mobile apps" lists the same devices (prop `appDevices`, same
fields) with "Sign out" → `DELETE /profile/app-devices/{id}` (session + CSRF, own devices only, 404
otherwise). e2e seeds a device with `POST /profile/app-devices/e2e-create` (APP_ENV=e2e only).

### Invitations & onboarding
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/invitations/{token}` | app | Deep-linked invite (`/invite/{token}`) opened in the app → `{data: {email, role, expires_at, expired, accepted, email_matches, already_member, can_accept, workspace: {name, slug}, inviter: {name}}}`; `slug` only for members. Unknown token → 404. | Full | BRAI-175 | ready |
| `POST /api/v1/invitations/{token}/accept` | app + verified email | Join → `{data: {id, slug, name, role}}`. Same rules as the web: 404 unknown, 410 `gone` expired, 403 other email / unverified, 402 `limit_reached` (workspace over its member limit); a member accepting just closes it; already accepted → 200 if still a member, else 409. | Full | BRAI-175 | ready |

First run "create a workspace" is `POST /api/v1/workspaces` (see Workspaces).

Plan limits under `/api/v1`: 402 `{message, code: "limit_reached", limit: {kind, limit, usage, plan,
workspace, billing_url}}` (`billing_url` null for people who can't upgrade; the message then says to
ask an admin) — `AppApiErrors::limitReached`, called from `LimitReached::render`.

### Workspaces
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/workspaces` | app + verified | Workspace switcher (tray / menu): the user's workspaces by name, cursor-paginated, `updated_since` | Read + create | BRAI-179 | ready (v1.49.0, BRAI-388) |
| `GET /api/v1/workspaces/{slug}` | app + verified | One workspace: role, permissions, time zone, counts, plan limits + usage. Not a member / unknown → 404 | Read + create | BRAI-179 | ready (v1.49.0, BRAI-388) |
| `POST /api/v1/workspaces` | app + verified email | First run "create a workspace": `{name, description?}` (name unique per owner, ≤ 255; description ≤ 1000) → 201 `{data: workspace}` with `role: "admin"`; 402 `limit_reached` (free workspaces per owner). | Read + create | BRAI-175, BRAI-179 | ready (v1.49.0, BRAI-386) |

List item / POST / accept-invitation shape (`WorkspaceResource`): `{id, slug, name, role, is_owner,
logo_url, url, created_at, updated_at}`; `role` is `admin | editor | member | viewer`, `url` the web
page. `GET /workspaces/{slug}` adds `{description, timezone, language, counts: {projects, members},
can: {update, invite_members, create_projects}, limits_enforced, limits: {…as GET /me}, usage:
{members, projects, content_bytes, file_bytes, measured_at}, billing_url}`.
- `counts.projects` = the projects this user can open (workspace admins: all); `usage.projects` =
  all projects (what the plan counts); `usage.members` counts open invitations too.
- Settings, logo, member roles and removals, billing, deleting: web only (`can.update` says whether to
  show an "Open settings" link).

### Projects
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/workspaces/{slug}/projects` | app + verified | Native menu "Go to project", capture destinations: the projects of the workspace the user can open (workspace admins: all; others: their projects), by name, cursor-paginated, `updated_since` | Read + create | BRAI-180 | ready (v1.49.0, BRAI-389) |
| `GET /api/v1/projects/{id}` | app + verified | Project home: boards, docs (folder tree), task lists, plans, helpdesks, lead forms, recently updated, `can` flags. Can't open / unknown → 404 | Read + create | BRAI-180 | ready (v1.49.0, BRAI-389) |
| `GET /api/v1/workspaces/{slug}/project-templates` | app + verified | "New project" gallery: built-in (`builtin:<key>`) and the workspace's saved templates (`template:<uuid>`), not paginated. 403 without `can.create_projects` | Read + create | BRAI-180 | ready (v1.49.0, BRAI-389) |
| `POST /api/v1/workspaces/{slug}/projects` | app + verified | Create a project: `{name, key, description?, template?, start_date? (Y-m-d, required with template), include?: [sections], request_id?: uuid}` → 201 `{data: project}`. Blank = "Main Board" + the user as project admin. 402 `limit_reached` (projects / storage), 403 without `createProjects`, 404 outside the workspace | Read + create | BRAI-180 | ready (v1.49.0, BRAI-389) |

Project (list item, POST): `{id, uuid, slug, key, name, description, icon, color, workspace: {slug,
name}, url, created_at, updated_at}`. Projects are addressed by **id** (`/projects/{id}`): slugs are
unique only per workspace and change on rename. `GET /projects/{id}` adds `{role, member_count, can:
{update, manage_members, create_boards, create_pages, create_issues}, boards: [{id, slug, name, type,
url}], docs: [{id, uuid, parent_id, type: page|folder, name, icon, is_whiteboard, is_draft, url,
updated_at}], task_lists, plans, helpdesks: [{id, slug, name, url}], lead_forms: [{…, status}],
recently_updated: [{type, id, name, url, updated_at}]}`.
- `docs` is the web's folder tree as a flat list (build the tree from `parent_id`); unpublished drafts
  only for their author and project admins, and nothing under a parent the user can't see.
- `role` is the project role (`admin | editor | viewer`), null for a workspace admin who isn't a member.
- Template runs are idempotent with `request_id`: a retry of a completed run answers 200 with the same
  project (no validation, as the web form). Editing, moving, archiving projects: web only.
- All workspace content (Workspaces, Projects and everything after) needs a verified address (403
  `forbidden`), like the web app; `GET /me` does not, so the app can show "verify your email".

## Mobile card texts (`## API (ready)`)

The lead pastes these onto the Mobile app cards (they are kept here, not edited on the board by agents).

**BRAI-179** (workspaces)
```
## API (ready)
- GET /api/v1/workspaces — the user's workspaces (switcher), cursor-paginated, updated_since
- GET /api/v1/workspaces/{slug} — one workspace: role, permissions, counts, plan limits + usage
- POST /api/v1/workspaces — create a workspace (402 at the free-workspace limit)
Scope: app · OpenAPI: docs/api/openapi.json#/Workspaces · Registry: docs/ai/API.md#workspaces
Built in: BRAI-388 (desktop) · Mobile scope: Read + create (settings web only)
```

**BRAI-180** (projects)
```
## API (ready)
- GET /api/v1/workspaces/{slug}/projects — the projects the user can open, cursor-paginated
- GET /api/v1/projects/{id} — project home: boards, docs tree, task lists, plans, helpdesks, lead forms, recently updated
- GET /api/v1/workspaces/{slug}/project-templates — templates for "New project"
- POST /api/v1/workspaces/{slug}/projects — create a project, blank or from a template (402 at the plan limit)
Scope: app · OpenAPI: docs/api/openapi.json#/Projects · Registry: docs/ai/API.md#projects
Built in: BRAI-389 (desktop) · Mobile scope: Read + create
```
