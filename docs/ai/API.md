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
  section), Workspaces 60, Projects 70, Members 80, Navigation 90, Search 100, Notifications 110, Connected apps 120, My Work 130. A new module gets a heading here, a weight, and an entry in `OpenApiSpecTest`.

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

### TV: device-code sign-in (RFC 8628, BRAI-438)

**The TV app uses the device code; desktop and mobile keep PKCE** (`/app/authorize` + `/auth/exchange`).
Clients and their grant + platforms: `config/app-api.php` → `clients` (`tv`: grant `device_code`,
platform `android_tv`, no redirect — `/app/authorize` refuses it; PKCE clients can't start a device code).

```
TV                                        Phone / computer (signed in)              Server
 │── POST /api/v1/auth/device {client: "tv", device: {name, platform: "android_tv", app_version, os_version?}} ──▶│
 │◀── {device_code, user_code: "BCDF-GHJK", verification_uri, verification_uri_complete, expires_in: 600, interval: 5}
 │ shows user_code + a QR of verification_uri_complete
 │                                          │ opens /app/device (types the code) or the QR (code filled in)
 │                                          │ sees code, device name, client, platform → Approve / Deny (POST)
 │── POST /api/v1/auth/device/token {device_code} every `interval` s ────────────────────────────────▶│
 │◀── 400 {code: authorization_pending} … then 200 token response (as /auth/exchange, unwrapped, + device)
```

`POST /auth/device` → (unwrapped)
```json
{ "device_code": "k3…64 chars", "user_code": "BCDF-GHJK",
  "verification_uri": "https://brainitive.example.com/app/device",
  "verification_uri_complete": "https://brainitive.example.com/app/device?code=BCDF-GHJK",
  "expires_in": 600, "interval": 5 }
```
`POST /auth/device/token {device_code}` → 200 the token response above (`device.platform: "android_tv"`),
or 400 `{message, code}` with `code`:

| code | meaning | the TV does |
|---|---|---|
| `authorization_pending` | not decided yet | poll again after `interval` |
| `slow_down` | polled sooner than `interval` (+ `interval`: the new wait, +5 s each time) | wait the new `interval` from now on |
| `access_denied` | the user clicked Deny (or was suspended) | stop, show "denied" |
| `expired_token` | 10 minutes passed | start again |
| `invalid_grant` | unknown or already used `device_code` | start again |

- The user code: 8 letters of `BCDFGHJKLMNPQRSTVWXZ` (no vowels, no look-alikes), shown `XXXX-XXXX`; typing
  ignores case, spaces and dashes. Single use: once approved or denied it no longer opens, for anyone.
- `/app/device` needs a signed-in, verified web session (guests sign in first and come back). Opening
  the QR never approves: only the Approve click (POST + CSRF) does.
- Limits: `POST /auth/device` 10/min per IP; polling 120/min per IP (the per-code `interval` is the real
  limit); `/app/device` 15/min per user + 40/min per IP, and 10 wrong codes per user lock it for 15 min (429).
- The token is issued once (the next poll → `invalid_grant`); `max_devices`, refresh rotation + reuse
  detection, logout and revocation work as for desktop / mobile. TV tokens get 401 on `/mcp`, `/api/cli`, `/api/ext`.

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
| `POST /api/v1/auth/device` | public, 10/min per IP | TV: start a device-code sign-in `{client: "tv", device: {name, platform: "android_tv", app_version, os_version?}}` → `{device_code, user_code, verification_uri, verification_uri_complete, expires_in, interval}` (unwrapped). Not a device-code client / platform not the client's → 422 | none (TV only) | BRAI-438 | ready (v1.49.0) |
| `POST /api/v1/auth/device/token` | public, 120/min per IP | TV: poll `{device_code}` → token response (unwrapped) once approved; else 400 `authorization_pending` / `slow_down` (+`interval`) / `access_denied` / `expired_token` / `invalid_grant` | none (TV only) | BRAI-438 | ready (v1.49.0) |
| `GET /app/device` (browser) | web session (`auth` + `verified`) | Confirm page: enter the TV's code, or `?code=` from the QR → code, device name, client, platform + Approve / Deny. Never approves by itself | the page is used from phones | BRAI-438 | ready (v1.49.0) |
| `POST /app/device` (browser) | web session + CSRF | `{user_code, decision: approve\|deny}` → done / denied page; a decided, expired or unknown code → 422 page; 10 wrong codes / 15 min → 429 | — | BRAI-438 | ready (v1.49.0) |
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
  so it is changed on the web only. Password and account deletion: web only (notification settings: see Notifications).
- There is no language or theme on the server (the web app keeps the theme per browser), so the
  apps keep those locally.

### Devices
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/devices` | app | Settings → signed-in devices: the user's active installs (`client` desktop / mobile / tv; `platform` macos / windows / linux / ios / android / android_tv), most recently used first → `{data: [{id, client, platform, name, app_version, os_version, has_push_token, current, last_used_at, created_at}]}` (≤ `max_devices`, not paginated). Never token hashes, IP or the push token. | Full | BRAI-173, BRAI-266 | ready |
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

### Members
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/workspaces/{slug}/members` | app + verified | Assignee / mention pickers: the workspace's members by name with their workspace role, `?q=` (name or email), cursor-paginated | Read + invite | BRAI-181 | ready (v1.49.0, BRAI-390) |
| `GET /api/v1/projects/{id}/members` | app + verified | Same for a project, with the project role. Can't open the project → 404 | Read + invite | BRAI-181 | ready (v1.49.0, BRAI-390) |
| `POST /api/v1/workspaces/{slug}/invitations` | app + verified | Invite `{email, role: admin\|editor\|member\|viewer}` → 201 `{data: {result, email, role, member, expires_at}}`. An existing account joins at once (`member_added`, `member` set); any other address gets the invitation email (`invitation_sent`, valid 30 days, a repeat refreshes it). Admins only (403), outside → 404, already a member → 422 `email`, 402 `limit_reached` (member limit) | Read + invite | BRAI-181 | ready (v1.49.0, BRAI-390) |

Member: `{id, name, email, avatar_url, role}` — what the web's pickers get. No `updated_since` on these
lists (joining doesn't change the member's record): reload them when opening a picker. The web asks
"send an invitation?" before mailing an unknown address; the apps ask in their own UI and then POST.
Role changes, removals, cancelling invitations, project membership: web only.

### Navigation
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/me/navigation?workspace={slug}&recent[]={type}:{id}` | app + verified | Dock / taskbar jump list, "Recent" menu, app menus: the web sidebar of one workspace + the app's recent destinations resolved (≤ 12, most recent first; types `project`, `board`, `page`, `item`, `plan`). Not a member / unknown → 404, no `workspace` → 422 | Full | BRAI-182 | ready (v1.49.0, BRAI-391) |
| `PUT /api/v1/me/pins` | app + verified | Pin / unpin / reorder in one project: `{project_id, pins: [{type, id}] (≤ 50, the whole list in order; [] = none), pinned_only?}` → `{data: {project_id, pinned_only, pins}}`. Pins the user can't open or not in the project are dropped. Can't open the project → 404 | Full | BRAI-182 | ready (v1.49.0, BRAI-391) |

`GET /me/navigation` → `{data: {workspace, nav: [keys], hidden_nav: [keys], projects: [{id, slug, key,
name, icon, color, url, groups: {health, workload, releases, boards, lists, plans, helpdesks,
lead_forms, pages, whiteboards, folders: bool}, pinned_only, pins: [{type, id, name, url,
is_whiteboard}], boards: [{id, name, type, url}], task_lists, plans, helpdesks, lead_forms: [{id,
name, url}]}], recent: [{type, id, title, subtitle, url}]}}`.
- Same data as the web sidebar (`users.sidebar_preferences`, BRAI-106): a pin or hidden group set in
  the app shows on the web and back. Pin types: `board`, `list`, `plan`, `helpdesk`, `lead_form`, `page`
  (docs, whiteboards, folders). `nav` = `home`, `inbox` + the entries the user didn't hide.
- **Recent is kept on the device**, as the web keeps it in the browser (command palette); the server
  only resolves the entries and drops what the user can no longer open (`RecentDestinations`).
- Docs are not in the navigation (they can be many): a project's folder tree is `GET /projects/{id}`.
  Hiding top-level entries / groups: web only for now.

### Search
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/search?q=&type=&workspace=&project[]=&status=&assignee=&updated=&limit=&cursor=` | app + verified | Global hotkey quick switcher: everything the user can open, ranked, typo tolerant — the same `SearchService` (ranking + visibility) as the web search page, the command palette, the MCP `search` tool and `ext/v1/search`. `workspace` not the user's → 404 | Full | BRAI-184, BRAI-219 | ready (v1.49.0, BRAI-392) |

→ `{data: [{id: "type:id", type, group, label, source_id, container_id, key, title, snippet,
snippet_html, url, external_url, context, project: {id, name}, workspace, status, is_draft,
updated_at}], next_cursor, total, counts: {issues, docs, comments, tickets, tasks, plans, snippets,
bookmarks, captures, files}, fuzzy, places: [{type, id, title, subtitle, url, workspace}]}`.
- `q` required (≤ 300; `"phrase"`, `-word`, an issue key ranks first; one letter finds nothing).
  `type` = a group key above; `workspace` = slug; `project[]` = ids; `status` = `open | done`;
  `assignee` = `me` or a user id; `updated` = `7d | 30d | 90d | 365d`; `limit` 1–50 (default 20).
- Pages are numbered: `next_cursor` is the next page number (`"2"`), up to 100 pages. `total` / `counts`
  are for the whole query.
- `places` (workspaces, projects, boards, plans, snippet / bookmark collections by name, as the
  command palette) only on the first page of a search without filters; `[]` otherwise.
- `snippet_html` contains only `<mark>` around matches (everything else escaped); `snippet` is plain.
- The workspace filter matches project content by the project's current workspace (so it follows a
  moved project) and snippets / bookmarks by their own; personal captures have no workspace and are
  left out when filtering by one.

### Notifications
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/notifications?filter=all\|unread\|mentions\|assigned&archived=&cursor=&limit=` | app + verified | Tray inbox / native notifications: the web's inbox (bell), newest first, cursor-paginated (no `updated_since`: start again from the first page) | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `GET /api/v1/notifications/unread-count` | app + verified | Dock / tray badge → `{data: {unread_count}}` | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `POST /api/v1/notifications/{id}/read` | app + verified | Mark one read → `{data: {unread_count}}`. Not the user's / unknown → 404 | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `POST /api/v1/notifications/read-all` | app + verified | Mark all inbox rows read → `{data: {unread_count: 0}}` | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `POST /api/v1/notifications/{id}/archive` | app + verified | Archive one (leaves the inbox, counts as read) → `{data: {unread_count}}`. Not the user's → 404 | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `GET /api/v1/me/notification-preferences` | app + verified | Settings → Notifications: `{data: {categories: [{key, label, description, channels: {in_app, push, email}}]}}` (effective: the user's choice or the default) | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `PUT /api/v1/me/notification-preferences` | app + verified | `{preferences: {"<category>": {"in_app"?, "push"?, "email"?: bool}}}` — partial, only sent channels change → same shape as GET; unknown category / channel → 422 | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |

Notification: `{id (uuid), type, category, data, read, read_at, archived_at, created_at}` — the rows
of the web bell (`NotificationInbox`, shared). `type` is e.g. `mentioned`, `item_assigned`,
`item_commented`; `category` the catalog group (null for an unknown type); `data` the type's payload
the web renders (`actor_name`, `item_title`, `url` …, different per type — show a generic row with
`data.url` for types the app doesn't know). Every read / archive is announced on the user's live
channel, so the web bell follows at once. `push` in the preferences is browser push today and the
apps' push once it ships (APNs / FCM via the device's `push_token`).

### Connected apps
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/me/connected-apps` | app + verified | Settings → Connected apps: every app with access to the account — signed-in desktop / mobile installs first (most recently used), then Passport clients (MCP connectors, CLI, browser extension), not paginated | View + revoke | BRAI-185, BRAI-256 | ready (v1.49.0, BRAI-394) |
| `DELETE /api/v1/me/connected-apps/{id}` | app + verified | Revoke at once → 204: `device:<id>` signs that install out (the calling one = sign out), `oauth:<client uuid>` revokes all of this account's tokens of that client (as Profile → Connected apps). Not the user's / malformed → 404 | View + revoke | BRAI-185, BRAI-256 | ready (v1.49.0, BRAI-394) |
| `GET /api/v1/me/data-export` | app + verified | "Download my data": `{data: {latest, exports: [≤ 5], available_in}}` (seconds until a new request is allowed) | Request export | BRAI-262 | ready (v1.49.0, BRAI-394) |
| `POST /api/v1/me/data-export` | app + verified, `throttle:5,1` | Request the ZIP (prepared in the background; notification + email when ready) → 202 with the same shape. Already being prepared / within the hour → 422 `export` | Request export | BRAI-262 | ready (v1.49.0, BRAI-394) |

Connected app: `{id, kind: desktop|mobile|tv|mcp|cli|extension|oauth, name, platform, app_version,
scopes, current, connected_at, last_used_at, expires_at}` — never a token, hash, IP or push token.
Passport tokens are grouped per client (one row each, as on the web): the CLI and tokens from
`php artisan mcp:get-token` share the personal access client and show as `cli`.

Export: `{id (uuid), status: queued|running|ready|failed|expired, file_size, counts, error,
created_at, finished_at, expires_at, download_url, download_path}`. The ZIP link is signed, works
for 24 h and **only in the owner's web session**: open `download_url` in the system browser (the user
is signed in there after the app's sign-in), or in the webview via `POST /auth/web-session` and
`{url}?to=<urlencoded download_path>`. Account deletion: web only.

### My Work
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/me/work?due=overdue\|today\|upcoming&status=open\|done\|all&updated_since=&cursor=&limit=` | app + verified | Tray "My Work", TV wallboard: issues and list tasks assigned to the user in every project they can open, soonest due first (no due date last), cursor-paginated. `due` takes several, comma separated (`overdue,today`); dates are the user's local day | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `PATCH /api/v1/me/work/{id}` | app + verified | The circle: `{done: bool}` → `{data: work item}`. Issue → its board's first done column (reopen → first open column), watchers notified; task → status (a repeating task with a due date moves to its next date and stays open). 403 without edit rights, 404 not visible / malformed id, 422 board without a done column | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `GET /api/v1/me/my-day` | app + verified | Tray "My Day", TV "Today" panel: today's plan in the user's order → `{data: {date, items: [work item], carry_over_count}}` | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `POST /api/v1/me/my-day/items` | app + verified | `{id: "issue:12"}` → plan it for today (at the end; again = no-op) → the updated My Day. Work the user can't open → 422 `id` | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `PATCH /api/v1/me/my-day/items/{id}` | app + verified | `{done: bool}`, as `PATCH /me/work/{id}` → the updated My Day | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `DELETE /api/v1/me/my-day/items/{id}` | app + verified | Take it off today's plan (the work is untouched) → 200 the updated My Day (a part of the plan, not a resource) | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `POST /api/v1/me/my-day/carry-over` | app + verified | "Bring over": unfinished work of the last 30 days' plans onto today → the updated My Day | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |

Work item (one resource for My Work, My Day and `/me/today`): `{id: "issue:<id>"|"task:<id>", type:
issue|task, key, title, done, status, priority: {id, name, color}|null, due_date (Y-m-d), due_time (H:i),
project: {id, name, key, color}, workspace: {slug, name}, source: {type: board|list, id, name}, url,
updated_at}`.
- Same query as the extension's `ext/v1/my-work` (`App\Support\MyWork\AssignedWork`): assigned to the user,
  projects they can open (workspace admins: all), open = not `completed_at` and not in a done column /
  task status not `done`. Tasks have no priority (`null`); `status` is the column name or `To do` / `Done`.
- `due_date` / `due_time` are the user's wall clock, like on the web; `overdue` / `today` / `upcoming`
  compare with the user's local today (`effective_timezone` of `GET /me`).
- Sync: `updated_since` + `status=all` returns what changed, done ones with `done: true`, so the app can
  drop them. Reassigned work never shows up as a change: reload the full list now and then (e.g. hourly).
- My Day = the web's My Day (BRAI-74, `MyDayPlan`): one plan per local day; unfinished work of earlier
  days is **not** moved by itself — `carry_over_count` says how many "Bring over" would add, as the web's
  button. Work the user can no longer open is left out. Reordering: web only for now.

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

**BRAI-181** (members)
```
## API (ready)
- GET /api/v1/workspaces/{slug}/members — workspace members with role, ?q= filter, cursor-paginated
- GET /api/v1/projects/{id}/members — project members with project role
- POST /api/v1/workspaces/{slug}/invitations — invite by email (admins; 402 at the member limit)
Scope: app · OpenAPI: docs/api/openapi.json#/Members · Registry: docs/ai/API.md#members
Built in: BRAI-390 (desktop) · Mobile scope: Read + invite (roles and removals web only)
```

**BRAI-182** (navigation)
```
## API (ready)
- GET /api/v1/me/navigation?workspace={slug}&recent[]=board:12 — sidebar of a workspace (entries, projects, groups, pins) + the app's recent destinations resolved
- PUT /api/v1/me/pins — replace one project's pins (and "pinned only"); synced with the web sidebar
Scope: app · OpenAPI: docs/api/openapi.json#/Navigation · Registry: docs/ai/API.md#navigation
Built in: BRAI-391 (desktop) · Mobile scope: Full (recent list kept on the device)
```

**BRAI-184** (search) — also **BRAI-219**
```
## API (ready)
- GET /api/v1/search?q=&type=&workspace=&project[]=&status=&assignee=&updated=&limit=&cursor= — global search (the web's ranking + visibility), page cursor, counts per group, places by name
Scope: app · OpenAPI: docs/api/openapi.json#/Search · Registry: docs/ai/API.md#search
Built in: BRAI-392 (desktop) · Mobile scope: Full
```

**BRAI-183** (notifications)
```
## API (ready)
- GET /api/v1/notifications?filter=all|unread|mentions|assigned&archived= — the inbox, newest first, cursor-paginated
- GET /api/v1/notifications/unread-count — badge count
- POST /api/v1/notifications/{id}/read · POST /api/v1/notifications/read-all · POST /api/v1/notifications/{id}/archive — answer the new unread count
- GET|PUT /api/v1/me/notification-preferences — per category: in_app / push / email (partial update)
Scope: app · OpenAPI: docs/api/openapi.json#/Notifications · Registry: docs/ai/API.md#notifications
Built in: BRAI-393 (desktop) · Mobile scope: Full
```

**BRAI-185** (connected apps) — also **BRAI-256**
```
## API (ready)
- GET /api/v1/me/connected-apps — devices (desktop / mobile) and MCP / CLI / extension clients
- DELETE /api/v1/me/connected-apps/{id} — revoke (device:<id> or oauth:<client uuid>) → 204
Scope: app · OpenAPI: docs/api/openapi.json#/Connected apps · Registry: docs/ai/API.md#connected-apps
Built in: BRAI-394 (desktop) · Mobile scope: View + revoke
```

**BRAI-215** (My Work + My Day)
```
## API (ready)
- GET /api/v1/me/work?due=overdue|today|upcoming&status=open|done|all&updated_since= — assigned issues + tasks, soonest due first, cursor-paginated
- PATCH /api/v1/me/work/{id} — {done: true|false}: complete / reopen (issue:<id> or task:<id>), as the web's circle
- GET /api/v1/me/my-day — today's plan in the user's order + carry_over_count
- POST /api/v1/me/my-day/items {id} · PATCH /api/v1/me/my-day/items/{id} {done} · DELETE /api/v1/me/my-day/items/{id} · POST /api/v1/me/my-day/carry-over — answer the updated My Day
Scope: app · OpenAPI: docs/api/openapi.json#/My Work · Registry: docs/ai/API.md#my-work
Built in: BRAI-396 (desktop) · Mobile scope: Full (reordering My Day: web only for now)
```

**BRAI-262** (data export)
```
## API (ready)
- POST /api/v1/me/data-export — request "Download my data" (one per hour; 202)
- GET /api/v1/me/data-export — status of the latest exports + download link (opens in the web session)
Scope: app · OpenAPI: docs/api/openapi.json#/Connected apps · Registry: docs/ai/API.md#connected-apps
Built in: BRAI-394 (desktop) · Mobile scope: Request export (account deletion web only)
```
