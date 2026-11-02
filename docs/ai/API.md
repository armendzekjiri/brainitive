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
  section), Workspaces 60, Projects 70, Members 80, Navigation 90, Search 100, Notifications 110, Connected apps 120, My Work 130, Calendar 140, Task lists 150, Captures 160, Snippets 170, Bookmarks 180, Time tracking 190, Boards 200, Issues 210, Sprints 220, Docs 230, Whiteboards 240, Plans 250, Releases 260, Files 270, Bookings 280, Helpdesk 290, Leads 300, Portals 310, Insights 320, Sync 330, Realtime 340. A new module gets a heading here, a weight, and an entry in `OpenApiSpecTest`.

## Conventions

| | |
|---|---|
| Auth | `Authorization: Bearer bra_…` (access, 15 min). Refresh `brr_…` (60 days, single use). Tokens never in URLs. |
| Responses | Wrapped: `{data: {...}}` (API Resources' default); lists `{data: [...], next_cursor}`. **DELETE → 204** No Content when it removes a resource; removing a *part* of one (e.g. `DELETE /me/avatar`) answers 200 with the updated parent instead. Only unwrapped exception: the OAuth token responses of `POST /auth/exchange` and `POST /auth/refresh` are unwrapped (`{token_type, access_token, expires_in, refresh_token, refresh_expires_in, device}`). Resources list their fields explicitly, never a model's `toArray()`. |
| Errors | `{"message": "…", "code": "…", "errors"?: {field: [..]}}` — 400 `bad_request` / `invalid_grant`, 401 `unauthenticated`, 402 `limit_reached` (+ `limit` object, see Invitations), 403 `forbidden`, 403 `client_not_allowed` (this app may not call the endpoint at all: the TV's scope, see "TV: what it may call"), 404 `not_found`, 405 `method_not_allowed`, 409 `conflict`, 410 `gone`, 422 `validation_failed`, 429 `rate_limited`, 500 `server_error`. 404 instead of 403 when the user must not learn that something exists. Documented once for all endpoints (`App\Support\AppApi\OpenApi`); a 402 needs `@throws LimitReached` on the controller method. |
| Polling | `GET /me/today`, `/me/my-day`, `/me/timer`, `/me/work`, `/notifications` (first page, no `cursor`), `/notifications/unread-count` and `/capture-destinations` answer an `ETag` (hash of the answer + the user): send it as `If-None-Match` → 304 without body. Shared `AnswersWithETag` trait; a new polled endpoint uses it. |
| Dates | ISO-8601 UTC (`2026-11-01T09:16:04Z`). |
| Lists | Cursor pagination `?cursor=&limit=` (default 50, max 100) → `{data: [...], next_cursor}`; `updated_since` for sync. Server side: `ListRequest` (rules + `limit()` / `cursor()` / `updatedSince()`) and a `CursorCollection` subclass per list. An unknown / stale cursor starts again at the first page. |
| Writes | Creates take an optional `request_id` (uuid): a retry with the same id and input answers **200** with what the first call created (201 the first time); the same id with other input → 409 `conflict` (`Concerns\CreatesOnce`, stored 7 days like the MCP's writes). A replay answers the model as it is **now**, so the caller must still see it: moved meanwhile to a project / workspace they can't open → the same 404 the read gives, never its data (Codex review; the policy's `view`, or the endpoint's own check). Updates carry `revision` / `updated_at` → 409 on conflict where a resource has one. |
| Rich text | Issue descriptions, comments, task notes, doc text and release notes are **Markdown** both ways (the web stores the editor's HTML; docs and release notes also come as sanitized `html` for rendering); a mention is `@[Name](user:<id>)` (`IssueMarkdown`), an image `![alt](url)` (`POST /uploads`). Send rich text only when the user changed it: editor formatting Markdown lacks is lost on save. |
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

A consent-page code approved before the user **signed out everywhere** (password change or reset, suspension,
account takeover) is dead: the exchange answers 400 `invalid_grant` (Codex review; `users.app_auth_generation`).

**Webview link `/app/session/{code}` (BRAI-377):** the code is spent on the first open (60 s, single use) and the
sign-in it carries is bound to that browser session; the session is then bound to the device (revoking the device
logs it out on its next request). It signs in **at once** only when the same user is already signed in, or when
nobody is signed in and the user agent contains an app marker: ` BrainitiveDesktop/<version>` (desktop webview) or
` BrainitiveMobile/<version>` (mobile webview — append it to the webview's user agent, with the leading space).
Otherwise a confirm
page — "You're signed in as A. Continue as B?" or "Sign in as B on this browser?" — and only its **Continue**
(`POST /app/session`, CSRF) signs in; Cancel keeps whoever was signed in. The confirmation stays valid 5 minutes.

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
| `access_denied` | the user clicked Deny (or was suspended, or signed out everywhere — password change / reset — after approving) | stop, show "denied" |
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
- The confirm page also shows **where and when** the request started — the coarse network of the TV's
  `POST /auth/device` (`203.0.113.x`; IPv6: the first three groups) and "Started N minutes ago" — and labels the
  name "Name reported by the device" (the TV chooses it). Stored with the code in the cache, never the full address.

#### TV: what it may call (BRAI-377)

A device-code approval can be phished ("type this code on your phone"), so the TV gets a **read-mostly scope**,
not the account: `config/app-api.php` → `clients.*.abilities` (route names without `app.v1.`; desktop and mobile
`['*']`), enforced by `EnsureAppClientAbility` on every signed-in route (before route-model binding). Anything
else → **403 `{message, code: "client_not_allowed"}`** — never retry it, hide the feature.

| Allowed for `tv` | Route name |
|---|---|
| `GET /instance`, `POST /auth/refresh` (public anyway), `POST /auth/logout` | `instance`, `auth.refresh`, `auth.logout` |
| `GET /me` | `me.show` |
| `GET /me/today` | `today` |
| `GET /me/work`, `PATCH /me/work/{id}` (`{done}` only) | `work.index`, `work.update` |
| `GET /me/my-day`, `PATCH /me/my-day/items/{id}` (`{done}` only) | `my-day.show`, `my-day.items.update` |
| `GET /calendar/events`, `GET /calendar/events/{event}`, `GET /calendar/connections` | `calendar.events`, `calendar.events.show`, `calendar.connections` |
| `GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/{id}/read` | `notifications.index`, `notifications.unread-count`, `notifications.read` |
| `GET /realtime`, `POST /realtime/token` | `realtime.show`, `realtime.token` |

Everything else is refused for the TV, notably: `POST /auth/web-session` (**never** for a device-code client),
devices and connected apps (no signing other devices out), invitations / creating workspaces / inviting,
`/me/data-export`, `/me/calendar-feed`, profile changes (`PATCH /me`, avatar, notification preferences),
`/uploads`, every other read (workspaces, projects, boards, docs, search, sync …) and every other write.
A new endpoint is closed to the TV until its route name is added to the list (`AppClientAbilitiesTest` pins it).

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

### App API v1: complete endpoint overview
The entry point for the desktop, mobile and TV apps: module → OpenAPI tag → main paths (all under `/api/v1`). Details, shapes and
rules in each module's section below; the typed spec is `docs/api/openapi.json`.

| Module | Tag | Main paths |
|---|---|---|
| Instance | Instance | `GET /instance` (public) |
| Auth | Auth | `POST /auth/exchange`, `/auth/refresh`, `/auth/logout`, `/auth/web-session`; TV: `POST /auth/device`, `/auth/device/token` |
| Account | Account | `GET\|PATCH /me`, `POST\|DELETE /me/avatar` |
| Devices | Devices | `GET /devices`, `PATCH /devices/current`, `DELETE /devices/{id}` |
| Invitations & onboarding | Invitations | `GET /invitations/{token}`, `POST /invitations/{token}/accept` |
| Workspaces | Workspaces | `GET\|POST /workspaces`, `GET /workspaces/{slug}` |
| Projects | Projects | `GET\|POST /workspaces/{slug}/projects`, `GET /projects/{id}`, `GET /workspaces/{slug}/project-templates` |
| Members | Members | `GET /workspaces/{slug}/members`, `GET /projects/{id}/members`, `POST /workspaces/{slug}/invitations` |
| Navigation | Navigation | `GET /me/navigation`, `PUT /me/pins` |
| Search | Search | `GET /search` |
| Notifications | Notifications | `GET /notifications`, `…/unread-count`, `POST …/{id}/read\|archive`, `…/read-all`, `GET\|PUT /me/notification-preferences` |
| Connected apps | Connected apps | `GET\|DELETE /me/connected-apps…`, `GET\|POST /me/data-export` |
| My Work | My Work | `GET /me/work`, `PATCH /me/work/{id}`, `/me/my-day…`, `GET /me/today` |
| Calendar | Calendar | `GET /calendar/events`, `/calendar/connections`; `POST /calendar/events`, `GET\|PATCH\|DELETE /calendar/events/{id}`, `GET /calendar/meeting-calendars`, `GET\|POST /meetings`, `DELETE /meetings/{id}`, `POST /meetings/{id}/notes`, `GET\|PUT\|DELETE /me/calendar-feed`, `POST /me/calendar-feed/reset` |
| Task lists | Task lists | `GET\|POST /projects/{id}/task-lists`, `GET /task-lists/{id}`, `GET\|POST /task-lists/{id}/items`, `PATCH\|DELETE …/items/{task}`, `POST …/items/reorder` |
| Captures | Captures | `GET\|POST /captures`, `GET\|PATCH\|DELETE /captures/{uuid}`, `…/archive\|restore\|preview\|convert`, `GET /capture-destinations` |
| Snippets | Snippets | `/workspaces/{slug}/snippet-collections`, `/snippet-collections/{id}…`, `/snippets/{id}` |
| Bookmarks | Bookmarks | `/workspaces/{slug}/bookmarks`, `/bookmarks/{id}…`, `/bookmarks/{id}/items…` |
| Time tracking | Time tracking | `/me/timer…`, `/work-logs…`, `GET /me/timesheet` |
| Boards | Boards | `GET /projects/{id}/boards`, `GET /boards/{id}`, `GET /boards/{id}/items`, `GET /boards/{id}/views`, `POST /items/{id}/move`, `GET /projects/{id}/config` |
| Issues | Issues | `POST /boards/{id}/items`, `GET\|PATCH\|DELETE /items/{id}`, `/items/by-key/{KEY}`, comments, watchers, attachments, activity, children, links, `GET /projects/{id}/items` (dated) |
| Sprints | Sprints | `GET /boards/{id}/sprints`, `/sprints/{id}…`, `/boards/{id}/backlog…`, burndown, velocity |
| Docs | Docs | `GET\|POST /projects/{id}/pages`, `GET\|PATCH /pages/{uuid}`, publish, move, collab, versions, reviews, links, `GET /links/resolve` |
| Whiteboards | Whiteboards | `GET /whiteboards/{uuid}` |
| Plans | Plans | `GET /projects/{id}/plans`, `GET /plans/{id}` |
| Releases | Releases | `GET /projects/{id}/releases`, `GET /releases/{id}` |
| Files | Files | `POST /uploads` |
| Bookings | Bookings | `GET /booking-pages`, `GET /bookings`, `GET /bookings/{uuid}`, `POST /bookings/{uuid}/cancel` |
| Helpdesk | Helpdesk | `GET /projects/{id}/helpdesks`, `GET /helpdesks/{id}`, `GET /helpdesks/{id}/tickets`, `GET\|PATCH /tickets/{id}`, `POST /tickets/{id}/replies` |
| Leads | Leads | `GET /projects/{id}/lead-forms`, `GET /lead-forms/{id}/leads`, `GET /lead-forms/{id}/analytics`, `GET\|PATCH /leads/{id}` |
| Portals | Portals | `GET /projects/{id}/feedback-portal`, `GET /feedback-portals/{id}/posts`, `PATCH /feedback-posts/{id}`, `POST /feedback-posts/{id}/moderate`, `GET /projects/{id}/client-portal`, `GET /client-portals/{id}/deliverables`, `GET /client-deliverables/{id}` |
| Insights | Insights | `GET /workspaces/{slug}/insights/{tab}`, `GET /projects/{id}/health`, `GET /projects/{id}/workload` |
| Sync | Sync | `GET /sync/changes`, `POST /sync` |
| Realtime | Realtime | `GET /realtime`, `POST /realtime/token` + the WebSocket (event catalog in "Realtime") |
| Web only | — | see "Web only (no App API in v1)" |

Columns of the module tables: **Mobile** = scope in the mobile app (Full / Read + light edit / Read-only / web only);
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
| `POST /api/v1/auth/refresh` | public, `throttle:app-auth` | `{refresh_token}` → new pair (unwrapped); the old pair is dead. Invalid / expired → 401 `invalid_grant`. A refresh token used **again** — any earlier one of the device, however many rotations ago (`app_device_refresh_uses`, Codex review) — → that device is revoked (`refresh_reuse`: its tokens, live / collab sockets and the web session its webview opened) + email → 401 `invalid_grant`; the user's other apps and browsers stay signed in. **Send one refresh at a time** | Full | BRAI-172 | ready |
| `POST /api/v1/auth/logout` | `auth:app` | Sign this device out → 204 | Full | BRAI-174 | ready |
| `POST /api/v1/auth/web-session` | `auth:app` (not `tv`: 403 `client_not_allowed`) | → `{data: {url}}`: one-time (60 s) `/app/session/{code}` for the webview; append `?to=/path` (relative) to land elsewhere. The webview must send its ` BrainitiveDesktop/<version>` (mobile: ` BrainitiveMobile/<version>`) user agent to get the one-step sign-in | Web only (desktop webview) | BRAI-177 | ready |
| `POST /api/v1/auth/device` | public, 10/min per IP | TV: start a device-code sign-in `{client: "tv", device: {name, platform: "android_tv", app_version, os_version?}}` → `{device_code, user_code, verification_uri, verification_uri_complete, expires_in, interval}` (unwrapped). Not a device-code client / platform not the client's → 422 | none (TV only) | BRAI-438 | ready (v1.49.0) |
| `POST /api/v1/auth/device/token` | public, 120/min per IP | TV: poll `{device_code}` → token response (unwrapped) once approved; else 400 `authorization_pending` / `slow_down` (+`interval`) / `access_denied` / `expired_token` / `invalid_grant` | none (TV only) | BRAI-438 | ready (v1.49.0) |
| `GET /app/device` (browser) | web session (`auth` + `verified`) | Confirm page: enter the TV's code, or `?code=` from the QR → code, "Name reported by the device", client, platform, the coarse network (`203.0.113.x`) and "Started N minutes ago" + Approve / Deny. Never approves by itself | the page is used from phones | BRAI-438 | ready (v1.49.0) |
| `POST /app/device` (browser) | web session + CSRF | `{user_code, decision: approve\|deny}` → done / denied page; a decided, expired or unknown code → 422 page; 10 wrong codes / 15 min → 429 | — | BRAI-438 | ready (v1.49.0) |
| `GET /app/session/{code}` (browser) | the code | Webview: spends the code and signs that user into the web guard, bound to the device — never remembered (Codex review: no remember-me cookie, an older one is expired; when the session expires the app lands on `/login` and opens a new one with its token; a webview session rebuilt from an old remember-me cookie is signed out), → `?to=` or `/dashboard` — at once for the same user, or for an app webview (` BrainitiveDesktop/` / ` BrainitiveMobile/` UA) with nobody signed in; otherwise a confirm page (another user signed in: "Continue as B?", never a silent swap; a plain browser: "Sign in as B on this browser?"). Used / expired code, revoked device or suspended user → login page | Web only | BRAI-177, BRAI-377 | ready |
| `POST /app/session` (browser) | web session + CSRF | The confirm page: `{decision: continue\|cancel}` → continue signs in (signing the other account out) → `?to=` of the link or `/dashboard`; cancel keeps the current user. No / expired (5 min) confirmation, revoked device → login page | Web only | BRAI-377 | ready (v1.49.0) |

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

Since v1.49.0 (Codex web review B / C / D) the content limit covers **every** type the meter counts, so these can
answer 402 `limit_reached` too: comments (`POST /items/{id}/comments`), task lists and tasks, snippets, bookmark
items, ticket replies, doc reviews and review comments, plus **edits that grow** an issue / task / snippet /
ticket / comment / bookmark item / doc body (shrinking edits always pass). In `POST /sync` each such change answers
`status: limit_reached` (402) and nothing is stored. Every file (attachments, bookmark files) reserves its bytes
atomically: a file that doesn't fit is 402 even before the nightly measure. Maximum lengths are shared with the
web (`ContentLimits`): comments ≤ 100 000, issue descriptions / snippet bodies / task notes ≤ 1 000 000, doc
Markdown ≤ 10 000 000, ticket replies ≤ 50 000 → 422 `validation_failed` above. `PATCH /tickets/{id}`:
`assignee_id` must be someone who can open the ticket's project (422 otherwise). `GET /pages/{uuid}/versions[/{v}]`
leaves out versions recorded while the page was a private draft, unless the caller is its author or an admin
(404 for one of them).
`PUT /me/calendar-feed`: a `project_ids` selection with a project the user can't open is 422 `validation_failed`
(`options.project_ids`), never stored as "all projects". Work logs (`/work-logs`, `/me/timesheet`): `project` is
`{id: null, name: "Deleted or moved project", key: null}` once the project left the entry's workspace or the user
can't open it.

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
| `GET /api/v1/search?q=&type=&workspace=&project[]=&status=&assignee=&updated=&limit=&cursor=` | app + verified | Global hotkey quick switcher: everything the user can open, ranked, typo tolerant — the same `SearchService` (ranking + visibility) as the web search page, the command palette, the MCP `search` tool and `ext/v1/search`. `workspace` not the user's → 404. **No `q` (missing / empty) = "recent" mode (BRAI-440):** the newest issues, docs, tickets, plans, tasks, snippets and bookmarks the user can open (same visibility as a search: `SearchViewer`, other people's drafts and captures stay hidden), newest `updated_at` first, ≤ 12 (`limit` can lower it), same result shape (no `snippet`; `counts` zero, `places` empty, `next_cursor` null); `type` / `workspace` / `project` / `status` / `assignee` / `updated` filters still apply. Not "opened" tracking: the apps keep their own opened list and resolve it with `/me/navigation` | Full | BRAI-184, BRAI-219, BRAI-440 | ready (v1.49.0, BRAI-392, BRAI-440) |

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
| `GET /api/v1/notifications?filter=all\|unread\|mentions\|assigned&archived=&cursor=&limit=` | app + verified | Tray inbox / native notifications: the web's inbox (bell), newest first, cursor-paginated (no `updated_since`: start again from the first page). Each row has `summary` (the bell's sentence, plain text) and `url` (absolute, or null). The first page (no `cursor`) answers an `ETag` → 304 while it is unchanged (per user) | Full | BRAI-183 | ready (v1.49.0, BRAI-393; summary / url / ETag: feat/app-api-client-gaps) |
| `GET /api/v1/notifications/unread-count` | app + verified | Dock / tray badge → `{data: {unread_count}}`. Answers an `ETag`; send it as `If-None-Match` → 304 without body while the count is unchanged (tag is per user; BRAI-440) | Full | BRAI-183, BRAI-440 | ready (v1.49.0, BRAI-393, BRAI-440) |
| `POST /api/v1/notifications/{id}/read` | app + verified | Mark one read → `{data: {unread_count}}`. Not the user's / unknown → 404 | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `POST /api/v1/notifications/read-all` | app + verified | Mark all inbox rows read → `{data: {unread_count: 0}}` | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `POST /api/v1/notifications/{id}/archive` | app + verified | Archive one (leaves the inbox, counts as read) → `{data: {unread_count}}`. Not the user's → 404 | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `GET /api/v1/me/notification-preferences` | app + verified | Settings → Notifications: `{data: {categories: [{key, label, description, channels: {in_app, push, email}}]}}` (effective: the user's choice or the default) | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |
| `PUT /api/v1/me/notification-preferences` | app + verified | `{preferences: {"<category>": {"in_app"?, "push"?, "email"?: bool}}}` — partial, only sent channels change → same shape as GET; unknown category / channel → 422 | Full | BRAI-183 | ready (v1.49.0, BRAI-393) |

Notification: `{id (uuid), type, category, summary, url, data, read, read_at, archived_at, created_at}` — the rows
of the web bell (`NotificationInbox`, shared). `type` is e.g. `mentioned`, `item_assigned`,
`item_commented`; `category` the catalog group (null for an unknown type); **`summary`** the bell's sentence as
one line of plain text — show it as text, never as HTML (HTML / Markdown stripped, `@[Name](user:<id>)` → `@Name`,
≤ 200 characters, English: the app has no translations; an unknown type still gets a sentence, at worst "Someone
sent you a notification"); **`url`** the page to open — an absolute URL on this server **only while the user can
open it now** (workspace, project, board, issue, doc, ticket checked like the web), else `null` (no link, a foreign
host, lost access, a deleted issue, someone else's draft); `data` the type's raw payload the web renders
(`actor_name`, `item_title` …, different per type). Server side: one formatter per worded type in
`App\Support\Notifications\Summaries\*` (mirrors `NotificationMessage.tsx`), `ContractSummary` for catalog / unknown
types, `NotificationText` + `NotificationLinks`.

```json
{"id": "9b2c…", "type": "item_assigned", "category": "assignments",
 "summary": "Ada assigned you to WEB-12",
 "url": "https://www.brainitive.app/workspaces/acme/projects/web/boards/main/items/412",
 "data": {"type": "item_assigned", "actor_name": "Ada", "item_key": "WEB-12", "item_title": "Fix login", "item_url": "…"},
 "read": false, "read_at": null, "archived_at": null, "created_at": "2026-11-02T09:16:04Z"}
```
 Every read / archive is announced on the user's live
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
| `GET /api/v1/me/work?due=overdue\|today\|upcoming&status=open\|done\|all&updated_since=&cursor=&limit=` | app + verified | Tray "My Work", TV wallboard: issues and list tasks assigned to the user in every project they can open, soonest due first (no due date last), cursor-paginated. `due` takes several, comma separated (`overdue,today`); dates are the user's local day. Every page answers an `ETag` (hash of that page + the user): `If-None-Match` → 304 while unchanged | Full | BRAI-215 | ready (v1.49.0, BRAI-396; ETag: feat/app-api-client-gaps) |
| `PATCH /api/v1/me/work/{id}` | app + verified | The circle: `{done: bool}` → `{data: work item}`. Issue → its board's first done column (reopen → first open column), watchers notified; task → status (a repeating task with a due date moves to its next date and stays open). 403 without edit rights, 404 not visible / malformed id, 422 board without a done column | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `GET /api/v1/me/my-day` | app + verified | Tray "My Day", TV "Today" panel: today's plan in the user's order → `{data: {date, items: [work item], carry_over_count}}`. `ETag` / `If-None-Match` → 304 while the plan (and what it shows) is unchanged; per user (BRAI-440) | Full | BRAI-215, BRAI-440 | ready (v1.49.0, BRAI-396, BRAI-440) |
| `POST /api/v1/me/my-day/items` | app + verified | `{id: "issue:12"}` → plan it for today (at the end; again = no-op) → the updated My Day. Work the user can't open → 422 `id` | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `PATCH /api/v1/me/my-day/items/{id}` | app + verified | `{done: bool}`, as `PATCH /me/work/{id}` → the updated My Day | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `DELETE /api/v1/me/my-day/items/{id}` | app + verified | Take it off today's plan (the work is untouched) → 200 the updated My Day (a part of the plan, not a resource) | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `POST /api/v1/me/my-day/carry-over` | app + verified | "Bring over": unfinished work of the last 30 days' plans onto today → the updated My Day | Full | BRAI-215 | ready (v1.49.0, BRAI-396) |
| `GET /api/v1/me/today` | app + verified | TV wallboard / tray "Today", one call every few minutes: `{data: {date, timezone, work: [work item] (open, overdue + due today, ≤ 100), my_day: [work item], events: [event] (today's connected-calendar events + bookings), errors}}`. `ETag` on every answer; send it as `If-None-Match` → **304** without a body when nothing changed | Full | BRAI-215, BRAI-222 | ready (v1.49.0, BRAI-396) |

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
- `GET /me/today` runs a fixed number of queries whatever the amount of work (no per-row lookups; a
  test counts them) plus one live call per connected calendar. Its `events` are only calendar events and
  bookings (dated work is in `work`); same event shape as `GET /calendar/events`.

### Calendar
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/calendar/events?from=&to=&sources[]=&me_only=&show_completed=` | app + verified | Tray "next meeting", TV wallboard "next meeting in 12 min", mobile agenda: everything the web calendar shows for the user's local days `from`…`to` (Y-m-d, inclusive, ≤ 62 days; default today + 7), sorted, not paginated → `{data: [event], from, to, timezone, errors: [{connection_id, message}]}` | Full | BRAI-222 | ready (v1.49.0, BRAI-418 read part) |
| `GET /api/v1/calendar/connections` | app + verified | Settings → calendars: the user's connected calendars / Google Tasks lists with status → `{data: [{id, provider, provider_label, kind, account_email, account_name, name, color, is_active, read_only, sync_error, sync_error_at}]}`, not paginated. Connecting / removing: web only | Status only | BRAI-229 | ready (v1.49.0, BRAI-418 read part) |
| `POST /api/v1/calendar/events` | app + verified, 30/min | Quick create / time block: `{connection_id, title, date, time?, end_date?, end_time?, description?, location?, timezone?, conference?: google_meet\|teams, attendees?: [email], request_id?}` on one of the user's own writable calendars → 201 `{data: event + description}` (200 on a replayed `request_id`). Not the user's calendar, or one they switched off → 422 `connection_id`; read-only (ICS) / provider refused → 422 `calendar`; a call the calendar can't add → 422 `calendar` | Full | BRAI-222, BRAI-220, BRAI-225 | ready (v1.49.0, BRAI-418) |
| `GET /api/v1/calendar/events/{id}` | app + verified | One event of a connected calendar with its `description`; `{id}` = the list's `event:<connection>:<event id>` (URL-encoded). Another user's calendar, one they switched off (`is_active` false — Codex review) / unknown event / other id kinds → 404 | Full | BRAI-222 | ready (v1.49.0, BRAI-418) |
| `PATCH /api/v1/calendar/events/{id}` | app + verified | Change the sent fields `{title, description, location, date, time, end_date, end_time, timezone?}`; moving = send `date` (+ times; no `time` → all-day) → `{data: event}`. Read-only → 422 `calendar` | Full | BRAI-222 | ready (v1.49.0, BRAI-418) |
| `DELETE /api/v1/calendar/events/{id}` | app + verified | Delete it (the provider tells attendees) → 204. Read-only → 422 `calendar` | Full | BRAI-222 | ready (v1.49.0, BRAI-418) |
| `GET /api/v1/calendar/meeting-calendars` | app + verified | The user's calendars that can add a video call → `{data: [{id, provider, provider_label, name, account_email, color, conference_providers: [google_meet\|teams]}]}`, not paginated | Full | BRAI-225 | ready (v1.49.0, BRAI-418) |
| `GET /api/v1/meetings?subject_type=item\|page&subject_id=` | app + verified | The meetings of an issue (id) / doc (uuid), oldest first (≤ 200, not paginated) → `{data: [meeting]}`. Can't open the issue / doc → 404 | Full | BRAI-225 | ready (v1.49.0, BRAI-418) |
| `POST /api/v1/meetings` | app + verified, 20/min | Schedule a Meet / Teams call on an issue / doc: `{subject_type, subject_id, connection_id, title, description?, date, start_time, duration (5–720 min), timezone?, conference: google_meet\|teams\|none, member_ids?, emails?, request_id?}` → 201 `{data: meeting}`. Can't edit the issue / doc → 403; outsiders → 404; not a project member in `member_ids` → 422 | Full | BRAI-225 | ready (v1.49.0, BRAI-418) |
| `DELETE /api/v1/meetings/{id}?cancel_event=0\|1` | app + verified | Unlink from the issue / doc (`can.remove`), or also cancel the calendar event (`can.cancel`: the organiser) → 204; else 403 | Full | BRAI-225 | ready (v1.49.0, BRAI-418) |
| `POST /api/v1/meetings/{id}/notes` | app + verified | The meeting's notes doc, created the first time (published, linked to the issue / doc) → 201 / 200 `{data: {uuid, name, url}}`; read it with `GET /pages/{uuid}`. No right to create docs → 403 | Full | BRAI-225 | ready (v1.49.0, BRAI-418) |
| `GET /api/v1/me/calendar-feed` | app + verified | The private ICS link → `{data: {enabled, url, webcal_url, links: {apple, google, outlook}, options: {assigned, created, tasks, my_day, focus, completed, descriptions, project_ids}, created_at, last_fetched_at, fetch_count}}` (`enabled: false` → links null, default options) | Full | BRAI-229 | ready (v1.49.0, BRAI-418) |
| `PUT /api/v1/me/calendar-feed` | app + verified | Turn on / replace the options `{options?: {…}}` (a missing option = its default; `project_ids` only projects the user can open) → 201 when it was off, else 200 | Full | BRAI-229 | ready (v1.49.0, BRAI-418) |
| `POST /api/v1/me/calendar-feed/reset` | app + verified | New secret link, the old one dies → `{data: feed}`; off → 404 | Full | BRAI-229 | ready (v1.49.0, BRAI-418) |
| `DELETE /api/v1/me/calendar-feed` | app + verified | Turn it off (the link dies) → 204 | Full | BRAI-229 | ready (v1.49.0, BRAI-418) |

Event: `{id, type: issue|task|event|google_task|booking, work_id, title, all_day, start, end, location,
conference_url, conference_provider, color, calendar: {connection_id, name, provider, provider_label}|null,
project: {id, name, key}|null, url, external_url, done, read_only}`.
- Same sources as the web calendar (`App\Support\Calendar\CalendarEvents`, shared with
  `CalendarController@index`): connected calendars of any provider + Google Tasks (`external`), the
  user's bookings (`booking`), dated issues (`board`) and list tasks (`list`) of every project they can
  open — all of them, as the web; `me_only=1` = only work assigned to the user, `show_completed=0`
  hides completed work. `sources[]` limits to some (default all).
- Timed: `start` / `end` are ISO-8601 UTC instants; all-day: `Y-m-d` and `end` is the **last day**
  (inclusive). Brainitive's own dates / times are the user's wall clock (`timezone` in the response).
  An event is listed when it touches one of the user's local days in the range.
- `id` is unique per occurrence (`task:<id>:<date>` for every date of a repeating task;
  `event:<connection>:<event id>`); `work_id` (`issue:<id>` / `task:<id>`) is what `PATCH /me/work/{id}`
  takes. `conference_url` is an https join link (Meet / Teams) when the event has one; `color` is the
  calendar's colour (events) or the project's (work).
- A calendar that fails to load is left out, listed in `errors`, and its `sync_error` shows in
  `/calendar/connections` (as the web's "Reconnect …" warning). Calls go to the providers live: refresh
  every few minutes, not every few seconds.
- **Writes (BRAI-418)** go through the web's provider layer (`CalendarProviders::writable`, as
  `CalendarConnectionController` and the MCP's calendar tools): only the user's own connections, any provider that writes
  (Google, Outlook, CalDAV); ICS links are read-only. Dates / times are wall clock in `timezone` (default the user's
  `effective_timezone`, not the server's). Provider errors are 422 `{code: validation_failed, errors: {calendar: [..]}}`
  (`CalendarProviderException::render`), with the provider's short message. The API never calls a provider's OAuth:
  **connecting, reconnecting and removing calendars stay on the web** (`sync_error` → "Reconnect on the web").
- Event ids of providers can hold `=`, `~`, `_`, `-` (never `/`): URL-encode the whole `event:<connection>:<id>`.
- **Meetings** (`Support\Meetings\MeetingScheduler`, shared with the web's `MeetingController`; `MeetingPolicy`): the event
  with its call goes into the organiser's own calendar, the provider invites attendees (Brainitive sends nothing). Meeting:
  `{id, title, subject: {type: item|page, id}, starts_at, ends_at, timezone, conference_provider, conference_label, join_url,
  event_url, provider, provider_label, attendees: [{name, email}], organizer: {id, name}, notes_page: {uuid, name, url}|null,
  can: {remove, cancel}}`. Attendee `email` only for the organiser (others see names, "Guest" for typed addresses), as on
  the web. `conference_provider` set while `join_url` is null = the provider hasn't returned the link yet (open `event_url`).
  Attendee picker: `GET /projects/{id}/members` (ids → `member_ids`; the server knows the addresses).
- **ICS feed** (`Support\CalendarFeed\CalendarFeeds`, shared with the web's "Subscribe in other apps"): the `url` is a
  secret (anyone with it reads the feed); calendar apps fetch it without a token. Share sheet / "Add to calendar" use
  `webcal_url` or `links.*`.

### Task lists
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/task-lists` | app + verified | A project's lists, in the web's order (position), with `counts: {todo, done}`, cursor-paginated, `updated_since`. Can't open the project → 404 | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `POST /api/v1/projects/{id}/task-lists` | app + verified | New list `{name, description?, request_id?}` → 201 `{data: list detail}` (at the end; the creator watches it). 403 for viewers (project admins / editors and workspace admins create), 404 outside | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `GET /api/v1/task-lists/{id}` | app + verified | One list: the list fields + `can: {update, delete, manage_items}` + `watching` | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `GET /api/v1/task-lists/{id}/items?status=todo\|done\|all` | app + verified | The list's tasks in its order (position), cursor-paginated, `updated_since` (deleted tasks don't come back: reload now and then) | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `POST /api/v1/task-lists/{id}/items` | app + verified | Add a task `{title, notes? (Markdown), assignee_id?, due_date?, due_time? (H:i), start_date?, start_time?, recurrence? (daily\|weekly\|monthly\|yearly), reminder_minutes? (0\|10\|60\|1440), request_id?}` → 201 `{data: task}`. 403 without `can.manage_items`; an assignee who can't open the project → 422 | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `PATCH /api/v1/task-lists/{id}/items/{task}` | app + verified | Change only the sent fields (`null` clears); `status: done\|todo`. Completing a **repeating** task with a due date keeps it `todo` and moves `due_date` to the next date, as the web → `{data: task}` | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `DELETE /api/v1/task-lists/{id}/items/{task}` | app + verified | Delete a task → 204 | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |
| `POST /api/v1/task-lists/{id}/items/reorder` | app + verified | `{ids: [every task id, top to bottom]}` → positions 1, 2, 3 …; ids of other lists are ignored → `{data: list detail}` | Full | BRAI-216 | ready (v1.49.0, BRAI-397) |

List: `{id, uuid, slug, name, description, position, counts: {todo, done}, project: {id, name, key}, workspace:
{slug, name}, url, created_at, updated_at}`; the detail (`GET`, `POST`, reorder) adds `can` and `watching`.
Task: `{id, work_id: "task:<id>", list_id, title, status: todo|done, done, notes (Markdown|null), assignee: {id,
name, avatar_url}|null, due_date (Y-m-d), due_time (H:i), start_date, start_time, recurrence, reminder_minutes,
position, url, created_at, updated_at}`.
- Same rules and code as the web (`TaskListPolicy`; `App\Support\TaskLists\TaskLists` for create / rules /
  reorder, also used by the CLI and the MCP; `WorkCompletion::updateTask` for done + repeating tasks).
  Everybody who can open the project reads; project admins / editors and workspace admins write.
- `notes` is Markdown both ways; the web stores the editor's HTML, so formatting the editor has and Markdown
  doesn't (colours, alignment) is lost when a task is saved through the API with `notes`. Send `notes` only
  when the user changed them.
- Dates / times are the user's wall clock, like on the web. `work_id` is what `PATCH /me/work/{id}` and My Day
  take; assigned tasks also show in `GET /me/work`.
- Renaming, describing, deleting lists and watching: web only for now (`can.update` / `can.delete` say whether
  to offer "Open in browser"). Duplicating a task: web only.

### Captures
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/captures?state=inbox\|processed\|archived&q=` | app + verified | The private capture inbox, newest first (`captured_at`), cursor-paginated, `updated_since`; `q` searches text, URL and source | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `GET /api/v1/captures/counts` | app + verified | Inbox badge → `{data: {inbox, processed, archived}}` | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `POST /api/v1/captures` | app + verified, 60/min | Global hotkey quick capture / share sheet: `{text, url?, intended_type? (issue\|task\|page\|bookmark\|snippet), source?, request_id?}` → 201 `{data: capture}`; `source` defaults to the app's name ("Brainitive Desktop") | Full (+ share sheet) | BRAI-217, BRAI-258 | ready (v1.49.0, BRAI-398) |
| `GET /api/v1/captures/{id}` | app + verified | One capture (`id` = uuid) | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `PATCH /api/v1/captures/{id}` | app + verified | Edit `{text?, url?, intended_type?, source?}` → `{data: capture}` | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `POST /api/v1/captures/{id}/archive` · `…/restore` | app + verified | Out of the inbox / back (to processed when it was converted) → `{data: capture}` | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `DELETE /api/v1/captures/{id}` | app + verified | Delete for good → 204; only archived captures (422 `capture` otherwise), as the web | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `GET /api/v1/captures/{id}/preview?type=` | app + verified | The convert dialog: the fields the capture becomes for a type → `{data: {type, fields}}` (issue `{title, description}`, task `{title, notes}`, page `{title, markdown}`, bookmark `{type, title, url, content}`, snippet `{title, content, language, description}`) | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `POST /api/v1/captures/{id}/convert` | app + verified, 60/min | `{type, destination_id, fields}` → 201 `{data: {replayed: false, location, capture}}`; converted before → 200 `replayed: true` (nothing new). Destination rights → 403; archived / invalid fields → 422; 402 `limit_reached` (issues, docs) | Full | BRAI-217 | ready (v1.49.0, BRAI-398) |
| `GET /api/v1/capture-destinations` | app + verified | Where the user can save into, per workspace: `{data: [{id, name, slug, projects: [{id, name, key, icon, color, can_create_pages, boards: [{id, name, type, columns: [{id, name}]}], task_lists: [{id, name}]}], bookmarks: [{id, name, icon}], snippet_collections: [{id, name}]}]}`. `ETag` / `If-None-Match` → 304 while unchanged (per user: what they may write to) | Full | BRAI-217 | ready (v1.49.0, BRAI-398; ETag: feat/app-api-client-gaps) |

Capture: `{id (uuid), text, url, intended_type, source, state: inbox|processed|archived, captured_at, processed_at,
archived_at, updated_at, target: {type, title, breadcrumb, url, available}|null}`.
- Same code as the web inbox, the extension's and the MCP's "save to inbox" (`App\Support\Captures\CaptureInbox`,
  `CaptureConversion`, `CaptureFields`, `CaptureDestinations`). Captures are private: anybody else's id is a 404,
  checked before validation.
- `destination_id` per type: issue → a board id, task → a task list id, page → a project id (`can_create_pages`),
  bookmark → a bookmark group id, snippet → a snippet collection id — all from `GET /capture-destinations`.
  Destinations list only what the user may write to; the convert checks again (403).
- `target.url` / `available`: the content's current place while the user can still open it; deleted or out of
  reach → the title / breadcrumb seen at conversion and `url: null`, `available: false`.
- `location` of the convert answer = where the new content is (`{type, title, workspace, project, breadcrumb,
  url}`; `type` as the web names it: `board item`, `task list`, `page`, `bookmark`, `snippet collection`).
- Files / images on a capture: not in v1 (the web's captures are text + a link). The share sheet sends a shared
  image's link or text; uploading files to a capture is later.

### Snippets
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/workspaces/{slug}/snippet-collections` | app + verified | Quick switcher "copy snippet": a workspace's collections in the web's order, with `snippets_count`, cursor-paginated, `updated_since`. Not a member → 404 | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `POST /api/v1/workspaces/{slug}/snippet-collections` | app + verified | `{name, description?, icon? (emoji), visibility? (private\|public), request_id?}` → 201 `{data: collection detail}`. Workspace editors / admins (403) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `GET /api/v1/snippet-collections/{id}` | app + verified | One collection + `can: {update, delete, manage_snippets}` | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `PATCH /api/v1/snippet-collections/{id}` · `DELETE …` | app + verified | Change (sent fields) → `{data: collection detail}` / delete with its snippets → 204. Its creator or a workspace admin (403) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `GET /api/v1/snippet-collections/{id}/snippets` | app + verified | The snippets with their `content`, in the web's order, cursor-paginated, `updated_since` | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `POST /api/v1/snippet-collections/{id}/snippets` | app + verified | `{title, content?, language?, description?, visibility? (inherit\|private\|public), request_id?}` → 201 `{data: snippet}`. Workspace editors / admins (403) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `GET /api/v1/snippets/{id}` · `PATCH` · `DELETE` | app + verified | One snippet / change it (sent fields) / delete → 204. Writes: workspace editors / admins (403) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |

Collection: `{id, uuid, slug, name, description, icon, visibility: private|public, snippets_count, position, share_url
(public only), workspace: {slug, name}, url, created_at, updated_at}` (+ `can` on the detail). Snippet: `{id, uuid,
slug, collection_id, title, description, language, content, visibility: inherit|private|public, effective_visibility,
share_url (public only), position, url, created_at, updated_at}`.
- The web's rules (`SnippetPolicy`; `App\Support\Snippets\Snippets` for rules + create, shared with
  `SnippetController`): every member reads, workspace editors / admins write snippets, a collection is changed by
  its creator or a workspace admin. Like every input of the web, `content` is trimmed (leading / trailing blank
  lines and spaces). Search finds snippets too (`GET /search?type=snippets`). Image icons: web only.

### Bookmarks
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/workspaces/{slug}/bookmarks` | app + verified | The bookmark groups the user may see (own, shared with them, public, all for workspace admins), in the web's order, with item counts, cursor-paginated, `updated_since` | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `POST /api/v1/workspaces/{slug}/bookmarks` | app + verified | `{name, description?, icon? (emoji), visibility? (private\|public), request_id?}` → 201 `{data: group detail}`. Workspace editors / admins (403) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `GET /api/v1/bookmarks/{id}` | app + verified | One group + `can: {update, delete}`. A private group of someone else, or any group of another workspace → 404 | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `PATCH /api/v1/bookmarks/{id}` · `DELETE …` | app + verified | Change name / description / emoji / visibility → `{data: group detail}` (creator, editor collaborators, admins) / delete with items + files → 204 (creator, admins) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `GET /api/v1/bookmarks/{id}/items?type=link\|todo\|note\|file` | app + verified | The group's items in the web's order, cursor-paginated, `updated_since` | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `POST /api/v1/bookmarks/{id}/items` | app + verified | `{type: link\|todo\|note\|file, title?, url? (http/https), content?, request_id?}`; a file as multipart `file` (≤ 200 MB, the web's types; scripts / executables → 422; 402 `limit_reached` when the file storage is full, since BRAI-416) → 201 `{data: item}`. Links get title / favicon / description fetched in the background | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |
| `PATCH /api/v1/bookmarks/{id}/items/{item}` · `DELETE …` | app + verified | `{title?, url?, content?, is_completed?}` → `{data: item}` / delete (and its file) → 204. Needs `can.update` (403) | Full | BRAI-218 | ready (v1.49.0, BRAI-399) |

Group: `{id, uuid, slug, name, description, icon ('' = none), visibility, counts: {links, todos, todos_completed, notes,
files}, is_owner, position, share_url (public only), workspace: {slug, name}, url, created_at, updated_at}` (+ `can`
on the detail). Item: `{id, group_id, type, title, url, content, file_url, is_completed, favicon_url,
page_description, position, created_at, updated_at}`.
- The web's rules (`BookmarkPolicy`; `App\Support\Bookmarks\Bookmarks` for rules, create, files, deletes, shared
  with `BookmarkController`). Groups are addressed by id; the path parameter is not `{bookmark}` (that name has a
  slug binding in the web routes).
- A public group of another workspace is only reachable through its `share_url` (the web's public page), not the API.
- Collaborators (invite / roles), image icons and reordering: web only for now.

### Time tracking
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/me/timer` | app + verified | Tray: running time → `{data: {running, timer: {id, started_at, elapsed_seconds, elapsed_minutes, description, issue: {id, work_id, key, title, url, workspace}\|null}\|null, activities, activity_required, default_activity_id, today_logged_seconds}}`. `today_logged_seconds`: the user's finished work logs on their local today (`spent_on`, every workspace), **without** the running timer — show "today" as `today_logged_seconds` + the running elapsed time. `ETag` / `If-None-Match` → 304 while nothing changed (a new work log changes it); per user. `elapsed_seconds` / `elapsed_minutes` tick by themselves and are **not** part of the tag: after a 304 count from `timer.started_at` (BRAI-440) | Full | BRAI-241, BRAI-440 | ready (v1.49.0, BRAI-400, BRAI-440) |
| `POST /api/v1/me/timer/start` | app + verified | `{id: "issue:<id>", description?}` → 201 timer state. `description` (≤ 255, what the user works on) becomes the work log's note when the stop sends none (REST, sync and the web pill). The web's timer (one per user): another one running → 409 `conflict`; no edit right on the issue → 403; not visible → 404; `task:<id>` → 422 (list tasks have no work log) | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |
| `POST /api/v1/me/timer/stop` | app + verified | `{timer_id, time? (default: elapsed), note?, activity_id?, spent_on?}` → `{data: {result: logged\|already_stopped\|no_access, work_log\|null}}`. Logs once (a retry → `already_stopped`); activity missing / unusable where required → 422 `activity_id`, the timer keeps running; that 422 also carries top-level `activities: [{id, name}]` (what can be picked, no rates) and `default_activity_id` (or null), so the app can show a picker and retry with `activity_id` (BRAI-440) | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |
| `POST /api/v1/me/timer/discard` | app + verified | `{timer_id}` → stop without logging → timer state | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |
| `GET /api/v1/work-logs?workspace=&from=&to=` | app + verified | The user's own work logs, newest day first, cursor-paginated, `updated_since`; `workspace` (slug) not theirs → 404 | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |
| `POST /api/v1/work-logs` | app + verified, 120/min | `{project_id, issue_id?, spent_on (Y-m-d), time ("1h 30m", "45m", "90"), activity_id?, note?, billable? (workspace admins), request_id?}` → 201 `{data: work log}` — the web timesheet's rules (422 `project_id` / `issue_id` / `activity_id` / `spent_on`) | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |
| `GET /api/v1/work-logs/{id}` · `PATCH` · `DELETE` | app + verified | One own entry / change the sent fields (the rest is kept; the whole form is checked as on the web) / delete → 204. Billed (`locked`) → 422 `entry`; someone else's entry → 404 | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |
| `GET /api/v1/me/timesheet?workspace={slug}&week=Y-m-d` | app + verified | My timesheet: `{data: {workspace, week: {start, end, previous, next, current, number, today}, total_minutes, days: [{date, minutes}] ×7, entries: [work log], projects: [{id, name, key}], activities, activity_required, default_activity_id}}`. Not a member → 404 | Full | BRAI-241 | ready (v1.49.0, BRAI-400) |

Work log: `{id, spent_on, minutes, duration, note, billable, source: manual|timer|legacy, locked, project: {id, name, key}|null,
issue: {id, work_id, key, title, url}|null, activity: {id, name, color, billable, archived}|null, workspace: {slug, name},
created_at, updated_at}`. Activity option: `{id, name, color, pricing_type: hourly|fixed, pricing_label, billable}`.
- **Never a rate or an amount** (the web's member-facing rule). Rates, clients, VAT, billing reports and invoices are
  web only (BRAI-242, BRAI-244, BRAI-245); workspace admins change other people's entries on the web's time overview.
- Same code as the web: `App\Support\Billing\WorkTimers` (start / stop / discard, shared with `WorkTimerController`)
  and `App\Support\Billing\TimeEntries` (week, rules, create / update / delete + the issues' history, shared with
  `TimesheetController`); `TimeLog` / `TimeLogActivities` for who may log where and the activities.
- The timer is the web's: started in the app, the web's top-bar pill shows it, and the other way round. Send the
  `timer_id` of `GET /me/timer` when stopping, so a stale device never logs onto a newer timer.
- An issue's live key / title / url only while the user can still open it; otherwise the snapshot from when it was
  logged and `url: null`. `source: legacy` (logged before the timesheet existed): only date, activity and note change.
- Picking an issue for a log: `GET /search?type=issues&project[]=` or `GET /me/work`; projects from `GET /me/timesheet`.

### Boards
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/boards` | app + verified | A project's boards in the web's order, cursor-paginated, `updated_since`. Can't open the project → 404 | Read + move cards | BRAI-187 | ready (v1.49.0, BRAI-403) |
| `GET /api/v1/boards/{id}` | app + verified | One board: `columns` (WIP limit, done flag, `cards_count`), `swimlanes`, `done_retention_days`, `active_sprint`, `can` | Read + move cards | BRAI-187 | ready (v1.49.0, BRAI-403) |
| `GET /api/v1/boards/{id}/items?column=&include_old_done=&q=&assignee=&status=&labels=&priority=&type=&quick=&f<id>=` | app + verified | The board's cards as the board page shows them, cursor-paginated, `updated_since`; `column` not on the board → 422. Filters = the board URL's / a saved view's params (BRAI-407) | Read + move cards | BRAI-187, BRAI-191 | ready (v1.49.0, BRAI-403 / 407) |
| `POST /api/v1/items/{id}/move` | app + verified | Drag & drop: `{column_id, position? (0 = top), with_subtasks?}` → `{data: card}`. Into a done column completes it (`with_subtasks`: its open subtasks too), out of one reopens it; watchers are notified. 403 without `can.edit_items`, 404 not visible / malformed id, 422 column of another board | Read + move cards | BRAI-187 | ready (v1.49.0, BRAI-403) |
| `GET /api/v1/projects/{id}/config` | app + verified | What issue forms and filters offer: `{project_id, types, priorities, labels, fields (with options, board_ids), releases (fix versions: unreleased / released), can: {manage}}`, not paginated | Use views + set field values | BRAI-191 | ready (v1.49.0, BRAI-407) |
| `GET /api/v1/boards/{id}/views` | app + verified | Saved views the user may use (shared + own, the web's order): `{id, name, is_shared, is_own, owner, is_default, layout, params, dropped}`, not paginated | Use views + set field values | BRAI-191 | ready (v1.49.0, BRAI-407) |

Board: `{id, uuid, slug, name, description, type: kanban|scrum|list, position, project: {id, name, key}, workspace: {slug,
name}, url, created_at, updated_at}`; `GET /boards/{id}` adds `{columns: [{id, name, slug, color, position, is_done, wip_limit,
cards_count}], done_retention_days, swimlanes: {mode: none|custom|assignee|priority|type|release, rest_name, lanes: [{id, name,
color, rules: {priority_ids, type_ids, label_ids, assignee_ids, release_ids, overdue}}]}, active_sprint: {id, name, goal,
start_date, end_date}|null, can: {update, create_items, edit_items, delete_any_item, manage_sprints}}`.
Card (issue on a board): `{id, work_id: "issue:<id>", key, title, board_id, column_id, status (column name), done, completed_at,
type: {id, name, icon, color}|null, priority: {id, name, icon, color}|null, assignee: {id, name, avatar_url}|null, labels: [{id,
name, color}], start_date, start_time, due_date, due_time, story_points, parent_id, sprint_id, position, counts: {subtasks,
comments, attachments}, url, created_at, updated_at}`.
- The cards are what the web board shows (`App\Support\Boards\BoardCards`): subtasks are cards too (`parent_id`); a **Scrum**
  board shows only the running sprint (none running → no cards; backlog and planned sprints: see Sprints); a **Kanban** board
  leaves out done cards finished more than `done_retention_days` ago (`include_old_done=1` lists them too).
- Sorted by `column_id`, then `position` inside the column: group them by `column_id` and lay the columns out in the order of
  `GET /boards/{id}`. `?column=` loads one column (a mobile column view).
- Swimlanes: the app evaluates the rules as the web does (`resources/js/lib/swimlanes.ts`): first lane whose rules all match
  (any listed id per rule; assignee / release id `0` = none), the rest in `rest_name`; `assignee` / `priority` / `type` /
  `release` modes group by that field.
- Moves go through `WorkCompletion::moveIssue` (the board's drag & drop): open boards on the web update live, watchers get the
  status notification. `wip_limit` only warns on the web, it never blocks a move.
- Creating / renaming / reordering / deleting columns, swimlane setup, done retention, board settings: web only (`can.update`
  says whether to offer "Open in browser").
- **Filters (BRAI-407)** are the board page's own URL params, so a saved view's `params` go to `/items` as they are
  (`App\Support\Boards\BoardFilters` applies them in SQL like `Pages/Boards/Show.tsx` + `lib/customFields.ts` in the browser):
  `q` (title / key contains), `assignee` = `me | unassigned | user-<id>`, `status` / `labels` / `priority` / `type` = comma-separated
  ids (any of them), `quick` = `recent` (updated ≤ 7 days) / `due` (open, due ≤ 3 days from the user's today, or overdue) / `blocked`
  (all must match), custom fields `f<field id>` = `none | any | yes | no | ~text | <option ids>[,none] | min..max` (dates
  `Y-m-d`; a value that doesn't fit the field filters nothing). Malformed `assignee` / id lists / `quick` → 422. `sort`, `view`, `hide`
  in a view's params are display settings (`layout` = `params.view`): the API ignores them.
- Saved views: `params` are sanitized as the board does when opening a view (`BoardViewConfig::sanitize`): ids that no longer exist
  are left out and named in `dropped`. Creating / changing / sharing views and the default view: web only.
- Config: the enabled issue types (an issue may keep a switched-off one), priorities, labels, active and archived custom fields
  (`board_ids: null` = every board; archived fields / options aren't offered for new values), fix versions. Setting them up: web only.
  Members for the assignee picker: `GET /projects/{id}/members`. Setting field values: `PATCH /items/{id}` `fields`.

### Issues
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `POST /api/v1/boards/{id}/items` | app + verified | New issue: `{title, description? (Markdown), column_id? (default: first column), type_id?, priority_id?, assignee_id?, label_ids?, start_date?, start_time?, due_date?, due_time?, story_points?, original_estimate? ("1h 30m"), release_id?, parent_id?, sprint_id?, fields?: {"<field id>": value}, request_id?}` → 201 `{data: issue}`. 403 without `can.create_items`, 404 board not visible, 422 the dialog's rules, 402 `limit_reached` (storage) | Full | BRAI-188 | ready (v1.49.0, BRAI-404) |
| `GET /api/v1/items/{id}` | app + verified | The issue view: card fields + description, reporter, estimate / time spent, fix version, parent, sprint, custom fields, GitHub development, `watching`, `can` | Full | BRAI-188, BRAI-252 | ready (v1.49.0, BRAI-404) |
| `GET /api/v1/items/by-key/{KEY}?workspace=` | app + verified | Open `WEB-12` from a link / a typed key (case-insensitive). Not visible → 404; the key in several of the user's workspaces → 409 until `workspace` (slug) says which | Full | BRAI-188 | ready (v1.49.0, BRAI-404) |
| `PATCH /api/v1/items/{id}` | app + verified | Change the sent fields (as POST, plus `label_ids` = the whole list, `fields` with `null` to clear; no `column_id` / `sprint_id`: move / sprints) + optional `updated_at` (as last read) → `{data: issue}`; changed since → 409 `conflict`. 403 without `can.update` | Full | BRAI-188 | ready (v1.49.0, BRAI-404) |
| `DELETE /api/v1/items/{id}` | app + verified | Delete with its subtasks → 204. Its creator, project admins, workspace admins (403) | Full | BRAI-188 | ready (v1.49.0, BRAI-404) |
| `GET /api/v1/projects/{id}/items?from=&to=&board=&status=open\|done\|all` | app + verified | Timeline / calendar: the project's issues (every board, subtasks too) whose start … due touch the days `from` … `to` (Y-m-d, default today + 30, ≤ 366 days), sorted by their first day, cursor-paginated, `updated_since`. Reschedule with `PATCH /items/{id}` (`start_date`, `due_date`, times). `board` not in the project → 422 | Read + reschedule | BRAI-194 | ready (v1.49.0, BRAI-409) |

Issue = the card of Boards + `{description (Markdown|null), reporter, creator: {id, name, avatar_url}|null, original_estimate_minutes,
time_spent_minutes, release: {id, name, status}|null, parent: {id, key, title}|null, sprint: {id, name, status}|null, fields:
[{field_id, name, type: text|number|date|checkbox|select, value, display}], watching, development: {connected, branches, commits,
commit_count, pull_requests, deployments, merge_column}, can: {update, delete, comment}}`.
- Same rules and code as the web's issue dialog (`App\Support\Boards\Issues`: rules, create, update, labels, delete — used by
  `BoardController` too): the project's enabled types / priorities, assignees who can open the project, a parent on the same board
  (3 levels, no cycles), "Subtasks must have a parent", the project's labels (others are dropped), active fields of the board
  (errors come back as `fields.<id>`). Notifications (assignee, mentions), history and live boards as on the web.
- `description` is Markdown both ways (stored as the editor's HTML, `App\Support\Boards\IssueMarkdown`); a mention is
  `@[Name](user:<id>)` — the person is notified when they can open the project (otherwise it stays plain text). Formatting the
  editor has and Markdown doesn't is lost when the app sends `description`: send it only when the user changed it.
- Custom field values: text → string, number → number, date → `Y-m-d`, checkbox → bool, select → option id (the options:
  BRAI-407's project config). `original_estimate` is a duration text in, minutes out (1d = 8h, 1w = 5d).
- Dated issues (BRAI-409): an issue with only a start or only a due date is that one day; undated ones aren't listed. Done
  issues are included (the web's list, timeline and calendar keep them; `status=open` leaves them out) — unlike the Kanban board's
  done retention. Recurring issues and templates: web only. The user's own dated work across projects: `GET /calendar/events`.
- `development` is read-only (GitHub branches, commits, pull requests, deployments; at most 30 per kind); connecting repositories:
  web only. Duplicating, moving to another board, logging work from the issue: web only for now (time: `POST /work-logs`).

#### Comments, watchers, files, history (same `Issues` tag)
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/items/{id}/comments` | app + verified | The discussion, oldest first, replies with `parent_id`, cursor-paginated, `updated_since` | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `POST /api/v1/items/{id}/comments` | app + verified, 60/min | `{body (Markdown, ≤ 20 000), parent_id?, request_id?}` → 201 `{data: comment}`. Mentions, watcher / assignee / reporter notifications, history and webhooks as on the web. 403 without `can.comment` (the web's rule: people who may edit the issue), 422 `parent_id` of another issue | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `GET /api/v1/items/{id}/watchers` | app + verified | `{data: {item_id, watching, watchers: [{id, name, avatar_url}]}}` | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `POST /api/v1/items/{id}/watch` · `DELETE …/watch` | app + verified | Watch / stop watching yourself (anybody who can open the issue) → 200 the watchers (a part of the issue, not 204). Adding / removing other people: web only | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `GET /api/v1/items/{id}/attachments` | app + verified | Files, newest first, cursor-paginated, `updated_since` | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `POST /api/v1/items/{id}/attachments` | app + verified, 30/min | Multipart `file` (≤ 10 MB, as the issue dialog), `request_id?` → 201 `{data: attachment}`. 403 without `can.update`, 402 `limit_reached` when the file storage is full (since BRAI-416) | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `DELETE /api/v1/items/{id}/attachments/{attachment}` | app + verified | Remove a file → 204. 403 without `can.update`; a file of another issue → 404 | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |
| `GET /api/v1/items/{id}/activity` | app + verified | The History tab, newest first, cursor-paginated (no `updated_since`: entries never change) | Full | BRAI-189 | ready (v1.49.0, BRAI-405) |

Comment: `{id, item_id, parent_id, body (Markdown, mentions @[Name](user:<id>)), author: {id, name, avatar_url}|null, created_at,
updated_at}`. Attachment: `{id, item_id, name, mime_type, size (bytes), url, uploaded_by: {id, name, avatar_url}|null, created_at}`.
History entry: `{id, action, changes: {field: {from, to}}, metadata: {…}, user: {id, name, avatar_url}|null, created_at}` — `action`
e.g. `created`, `updated`, `moved`, `assigned`, `commented`, `labels_changed`, `field_changed`, `attachment_added`,
`attachment_removed`, `work_logged`; show a generic line for an unknown one.
- Same code as the issue dialog: `ItemComments::add` (history, mentions, "commented on" for watchers / assignee / reporter, webhooks,
  live issue views) and `App\Support\Boards\ItemAttachments` (shared with `BoardController`). People never carry an email address.
- `url` of a file is the web's public storage link (as the issue dialog's); it needs no token.
- **Editing / deleting comments: not built** — the web can't either (planned on the card as "own comments"; it comes with the web
  feature). Files go straight to the issue; an image / file *inside* a comment or description: `POST /uploads` (Files) + its `markdown`.

#### Subtasks and linked issues (same `Issues` tag)
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/items/{id}/children` | app + verified | Direct subtasks (cards) in board order, cursor-paginated, `updated_since`; deeper levels: `counts.subtasks` + this call on them. A new subtask = `POST /boards/{id}/items` with `parent_id` (same board, 3 levels at most → 422) | Read + light edit | BRAI-190 | ready (v1.49.0, BRAI-406) |
| `GET /api/v1/items/{id}/links` | app + verified | Linked issues read from this issue's side, oldest first, not paginated | Read + light edit | BRAI-190 | ready (v1.49.0, BRAI-406) |
| `POST /api/v1/items/{id}/links` | app + verified | `{target_id, type: blocks\|is_blocked_by\|duplicates\|is_duplicated_by\|relates_to\|clones\|is_cloned_by}` → 201 the link; already linked that way (either side) → 200 the existing one. 403 without `can.update`; a target the user can't open / the issue itself → 422 `target_id` | Read + light edit | BRAI-190 | ready (v1.49.0, BRAI-406) |
| `DELETE /api/v1/items/{id}/links/{link}` | app + verified | Remove a link of this issue (added on either side) → 204. 403 without `can.update`; not a link of the issue → 404 | Read + light edit | BRAI-190 | ready (v1.49.0, BRAI-406) |

Link: `{id, type, label ("is blocked by"), direction: outgoing|incoming, restricted, issue: {id, key, title, url}|null}`.
- One row per link (`App\Support\Boards\ItemLinks`, shared with the web's issue view and the MCP): "A blocks B" added on A reads
  `blocks` / `outgoing` on A and `is_blocked_by` / `incoming` on B; adding it again from either side changes nothing; adding and
  removing write the issue's history. Links to issues of other projects are allowed when the user can open both.
- An issue the user can't open stays listed as `restricted: true` with `issue: null` (BRAI-435, the web's "Restricted").
- Checklists: issues have none of their own — use subtasks, or Markdown task lists (`- [ ] …`) in the description.

### Sprints
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/boards/{id}/sprints?status=active,planned,completed` | app + verified | A board's sprints in planning order (default `active,planned`, as the backlog page) with counts + points, cursor-paginated, `updated_since`. Kanban boards have none | Read + move items | BRAI-192 | ready (v1.49.0, BRAI-408) |
| `GET /api/v1/sprints/{id}` | app + verified | One sprint | Read + move items | BRAI-192 | ready (v1.49.0, BRAI-408) |
| `GET /api/v1/sprints/{id}/items` | app + verified | Its top-level issues (cards) in backlog order, cursor-paginated, `updated_since` | Read + move items | BRAI-192 | ready (v1.49.0, BRAI-408) |
| `GET /api/v1/boards/{id}/backlog` | app + verified | Top-level issues in no sprint, in backlog order, cursor-paginated (the card's "sprint 0") | Read + move items | BRAI-192 | ready (v1.49.0, BRAI-408) |
| `POST /api/v1/sprints/{id}/items` | app + verified | Plan issues into a planned / the active sprint: `{item_ids (≤ 200), request_id?}` → `{data: {moved, sprint_id}}`; subtasks follow their parent, each lands at the end. 403 without `can.manage_sprints`; ids that aren't top-level issues of the board, a completed sprint → 422 `item_ids` (nothing moves) | Read + move items | BRAI-192 | ready (v1.49.0, BRAI-408) |
| `POST /api/v1/boards/{id}/backlog/items` | app + verified | Back to the backlog, same body / rules → `{data: {moved, sprint_id: null}}`; a Kanban board → 422 | Read + move items | BRAI-192 | ready (v1.49.0, BRAI-408) |
| `GET /api/v1/sprints/{id}/burndown` | app + verified | `{data: {sprint_id, unit: points\|issues, total, days: [{date, ideal, remaining\|null}]}}` (the Reports page; a completed sprint: its snapshot) | Read-only | BRAI-193 | ready (v1.49.0, BRAI-408) |
| `GET /api/v1/boards/{id}/velocity` | app + verified | Committed vs. completed of the last completed sprints, oldest first, not paginated → `{data: [{sprint_id, name, committed_points, completed_points, committed_issues, completed_issues}]}` | Read-only | BRAI-193 | ready (v1.49.0, BRAI-408) |

Sprint: `{id, board_id, name, goal, status: planned|active|completed, position, start_date, end_date, started_at, completed_at,
counts: {issues, done}, points: {total, done}, committed_points, completed_points, committed_issues, completed_issues, url}` —
counts and points are the top-level issues (subtasks count with their parent), as the backlog page; `committed_*` / `completed_*` are
set when a sprint starts / completes.
- Same code as the web: `SprintLifecycle::moveIssues` (shared with the MCP's `move_items_to_sprint`; live boards get the moves),
  `SprintReport::burndown` / `velocity` (the Reports page). Reading: everybody who can open the board; planning: `BoardPolicy::update`.
- The running sprint's cards by column are `GET /boards/{id}/items` (a Scrum board shows only the running sprint).
- Creating, editing, starting, completing sprints and ranking inside the backlog: web only (`can.manage_sprints` → "Open in browser").

### Docs
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/pages?parent=root\|<uuid>` | app + verified | The page tree (docs, whiteboards, folders), flat with `parent_id` / `parent_uuid`, in the web's order (docs + whiteboards before folders, then `position`), cursor-paginated, `updated_since`; `parent` = one level. Unpublished pages only for their author and admins | Read + light edit | BRAI-198 | ready (v1.49.0, BRAI-411) |
| `GET /api/v1/pages/{uuid}` | app + verified | One page: `markdown` + sanitized `html` (docs), `revision`, `version`, breadcrumbs, `can`. Someone else's unpublished draft → 404 | Read + light edit | BRAI-198, BRAI-199 | ready (v1.49.0, BRAI-411) |
| `POST /api/v1/projects/{id}/pages` | app + verified, 60/min | `{name, kind?: doc\|whiteboard\|folder, parent_uuid?, icon? (emoji), markdown? (docs), publish?, request_id?}` → 201 `{data: page}`. 403 without `can.create`, 422 a parent the user can't see, 402 `limit_reached` (storage) | Read + light edit | BRAI-198 | ready (v1.49.0, BRAI-411) |
| `PATCH /api/v1/pages/{uuid}` | app + verified, 120/min | `{name?, icon?, markdown?, revision}` → `{data: page}`; `revision` is required with `markdown` and → 409 `conflict` when the page was saved since. A published doc's changes become unpublished changes. Whiteboards / folders: `name`, `icon`. 403 without `can.update` | Read + light edit | BRAI-199 | ready (v1.49.0, BRAI-411) |
| `POST /api/v1/pages/{uuid}/publish` | app + verified | `{message?, notify? (default true), revision?}` → `{data: page}`: publish a draft, or make a published doc's changes live as a new version (watchers notified). Nothing to publish → 200 unchanged; "review required" without an approved review → 422 `review`; folders → 422 | Read + light edit | BRAI-198 | ready (v1.49.0, BRAI-411) |
| `POST /api/v1/pages/{uuid}/move` | app + verified | `{parent_uuid (null = top level), before_uuid?, after_uuid?}` → `{data: page}`; into itself / its subpages → 422 `parent_uuid` | Read + light edit | BRAI-198 | ready (v1.49.0, BRAI-411) |
| `POST /api/v1/pages/{uuid}/collab-token` | app + verified, 60/min | Join a doc's live document (Yjs): `{data: {url, document, epoch, token, expires_at (unix s), edit, html_vector, user}}`. Ask for every (re)connect (~2 min). The token is bound to the device: once it is signed out the socket closes with **4410** (drop tokens, show sign-in — see Realtime). 403 when not offered (`live: null` on `GET /pages/{uuid}`: server without realtime, whiteboard / folder, archived, reader of a published doc) | Later (read-only fallback) | BRAI-200 | ready (v1.49.0, BRAI-412) |
| `POST /api/v1/pages/{uuid}/collab/bootstrap` | app + verified, 30/min | `{epoch, claim}` → `{data: {status: granted\|wait, html, name, icon}}`: the one client that loads the draft into an empty document. 409 `conflict` + `epoch`: replaced, reconnect | Later | BRAI-200 | ready (v1.49.0, BRAI-412) |
| `PUT /api/v1/pages/{uuid}/collab/draft` | app + verified, 180/min | `{epoch, html, name, icon?, vector, baseline?}` → `{data: {status: saved\|unchanged\|covered\|baseline, revision, has_unpublished_changes}}`: the live document's HTML as the draft (what a leading browser sends). 409 + `epoch`: replaced | Later | BRAI-200 | ready (v1.49.0, BRAI-412) |

Page (tree): `{id, uuid, project_id, parent_id, parent_uuid, kind: doc|whiteboard|folder, name, icon (emoji|null), has_image_icon,
status: draft|published|archived, position, url, created_at, updated_at}`. `GET /pages/{uuid}` adds `{icon_image (data: URL or
URL)|null, markdown|null, html|null, revision, version, published_at, has_unpublished_changes, review_required, breadcrumbs: [{uuid,
name, type}], creator, last_edited_by, watching, can: {update, delete, publish, create}}`.
- Same code and rules as the web: `PagePolicy` (unpublished pages are their author's and the project / workspace admins'),
  `Support\Pages\PageWrites` (create, the editor's draft save with the revision check on the locked row, publish through
  `ReviewGate` + watcher notifications; shared with `PageController` and the MCP's `create_page`) and `PageMover`.
- **What a user reads = their web view:** editors get the working copy (`name`, text: a published doc's unpublished changes
  included, `has_unpublished_changes`), readers the published version. The tree's `name` is always the published one.
- **Text is Markdown** with mentions `@[Name](user:<id>)` (`IssueMarkdown`; a mention notifies the person once, when they can open
  the project). `html` is the stored editor HTML through `SafeHtml` — for rendering, never for sending back. Editor formatting
  Markdown lacks (colours, embeds, smart-link cards) is lost when the app sends `markdown`: send it only when the user changed it.
- **Live editing (Yjs):** a text change from the app is a save from outside the live document, like the web's single-user
  editor: it needs the `revision` the app read (live editors save every few seconds, so a stale one → 409; load and retry) and
  starts a new live document (`PageCollab::reset`), so open browsers reload on the app's text. On a published doc it only reaches
  the draft; readers see it after publish.
- **Live co-editing from a native client (BRAI-412)** = the browser's three calls (`Support\Collab\PageCollab`, the web's
  `PageCollabController`): a token for the realtime server (`COLLAB_URL`; access = `PageCollab::access`, the rule the server checks
  through `internal/collab/access`: editors edit, readers of a never-published doc watch), the bootstrap claim, and the live draft
  save. A client that edits must also save (the leading client sends its HTML every few seconds; `covered` = another one holds
  more), or its edits stay in the Y state only. `GET /pages/{uuid}` → `live: edit|view|null` says what is offered. Whiteboards and
  issue descriptions: no app token (web / desktop webview).
- Icons from the app are emoji (`data:` / `http` / `/…` → 422); uploading image icons, duplicating, unpublishing, archiving,
  deleting, version restore, review requests: web only for now. Whiteboard scenes: Whiteboards.

#### History, reviews, links (same `Docs` tag)
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/pages/{uuid}/versions` | app + verified | The history (every publish; whiteboards also their checkpoints), newest first, cursor-paginated | Read | BRAI-201 | ready (v1.49.0, BRAI-413) |
| `GET /api/v1/pages/{uuid}/versions/{version}` | app + verified | One version with its text (`markdown`, `html`; null for a whiteboard). Restore: web only | Read | BRAI-201 | ready (v1.49.0, BRAI-413) |
| `GET /api/v1/pages/{uuid}/reviews` | app + verified | The doc's reviews the user may see, newest first, not paginated: editors all, a reviewer theirs (also on a draft they can't open); nobody else → 404 | Read + reply + approve | BRAI-202 | ready (v1.49.0, BRAI-413) |
| `GET /api/v1/pages/{uuid}/reviews/{review}` | app + verified | One review: snapshot (`snapshot_markdown`, `snapshot_html`), reviewers + decisions, `my_decision`, `threads`, `can: {decide, comment, open_page}` | Read + reply + approve | BRAI-202 | ready (v1.49.0, BRAI-413) |
| `POST /api/v1/pages/{uuid}/reviews/{review}/decision` | app + verified, 30/min | `{decision: approved\|changes_requested, comment?}` → the review. Active reviewers of an open review (403) | Read + reply + approve | BRAI-202 | ready (v1.49.0, BRAI-413) |
| `POST /api/v1/pages/{uuid}/reviews/{review}/comments` | app + verified, 60/min | `{body, parent_uuid? (reply), quote?, prefix?, suffix?, request_id?}` → 201 the thread. New threads: open reviews (403); a quote not in the snapshot → 422 `quote` | Read + reply + approve | BRAI-202 | ready (v1.49.0, BRAI-413) |
| `PATCH /api/v1/pages/{uuid}/review-threads/{thread}` | app + verified | `{resolved}` → the thread (anyone who sees its review) | Read + reply + approve | BRAI-202 | ready (v1.49.0, BRAI-413) |
| `GET /api/v1/pages/{uuid}/links` | app + verified | `{data: {related, referenced_by}}`: what the page links to and its backlinks (smart links, issues, plan rows, whiteboard cards, manual links), visible content only | Read | BRAI-203 | ready (v1.49.0, BRAI-413) |
| `GET /api/v1/links/resolve?workspace=&refs[]=&urls[]=` | app + verified, 240/min | Smart link chips: per requested ref (`item:12`, `page:<uuid>`, `ticket:5`, `plan:3`) or Brainitive URL (≤ 100 each), in order: `{requested, state: ok\|restricted\|not_found, ref, type, label, key, title, url, status, due_date, person, priority, project}` | Read | BRAI-203 | ready (v1.49.0, BRAI-413) |

Version: `{version, name, icon, message, author, created_at}` (+ `markdown`, `html` on one). Review: `{uuid, number, status:
pending|approved|changes_requested|published|cancelled, stale, snapshot_name, message, requested_by: {id, name}, created_at, closed_at,
url, reviewers: [{user_id, name, decision, comment, decided_at, active}]}`. Thread: `{uuid, review_uuid, review_number, quote, prefix,
suffix, outdated, resolved, resolved_at, resolved_by_name, comments: [{uuid, author: {id, name}, body, created_at}]}`. Link entry:
`{type: item|page|ticket|plan, kind, id, key, uuid, title, url, status, origin: manual|derived|manual+derived, derived_from}`.
- Same code as the web: `PageReviews` (decide, comment, resolve + their notifications), `ReviewPresenter` (summary, threads),
  `PageReviewPolicy` — a reviewer reads the **snapshot** of a draft they can't open (`can.open_page: false`), never the draft;
  `QuoteAnchor` (quotes are plain text of the snapshot, `prefix` / `suffix` a few characters around it to place it); history =
  `PageVersion`; links = `ContentLinkFormatter::references` (the MCP's `get_page`); chips = `SmartLinks::resolve` (+ `refFromUrl`,
  since Markdown keeps the link but not the `data-smart-link` ref): restricted content never shows a title.
- An approved review stays open (status `approved`, `can.decide` true: the reviewer may change their mind) until the doc is
  published (`POST /pages/{uuid}/publish`) or the draft changes (`stale`). Comment bodies are plain text.
- Web only: requesting / cancelling reviews, "review required", restoring versions, adding / removing related content.

### Whiteboards
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/whiteboards/{uuid}` | app + verified | The scene, read-only: page fields + `revision`, `scene: {elements (deleted ones left out), app_state (viewBackgroundColor, grid, scroll, zoom), files (embedded images)}`, `preview_image_url`, `can.update`. `ETag` → 304 on `If-None-Match`. Not a whiteboard / not visible → 404 | View only | BRAI-206, BRAI-209 | ready (v1.49.0, BRAI-414) |

- Whiteboards are pages (`kind: whiteboard` in `GET /projects/{id}/pages`; same `PagePolicy`: an unpublished one is its author's and
  the admins'); create / rename / move / publish them through Docs (`POST /projects/{id}/pages {kind: whiteboard}`), history through
  `GET /pages/{uuid}/versions`.
- **No server-side image export:** the server never renders Excalidraw. `preview_image_url` is the PNG of the last "Save & exit" on
  the web (null when none; it can be older than the scene). Apps render `scene.elements` themselves (Excalidraw in a webview) for an
  exact picture. While people draw live, the scene is saved every few seconds — poll with the `ETag`.
- Drawing, templates, sticky notes → issues, issue cards, live cursors: web / the desktop app's webview only (BRAI-207, 208, 210).

### Plans
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/plans` | app + verified | A project's Gantt plans in the web's order (`position`), cursor-paginated, `updated_since`: `{id, project_id, slug, name, description, rows_count, start, end, revision, url, created_at, updated_at}` | Read-only | BRAI-212 | ready (v1.49.0, BRAI-415) |
| `GET /api/v1/plans/{id}` | app + verified | One plan + `rows: [{id, name, level, who, status, notes, collapsed, issue: {id, key, title}\|null, bars: [{id, start, end, type: task\|milestone, color, label, progress}]}]`, `dependencies: [{id, from, to}]` (bar ids, finish → start), `can.update` | Read-only | BRAI-212, BRAI-213 | ready (v1.49.0, BRAI-415) |

- Same reader as the MCP's `get_plan` (`Support\Plans\PlanReader`, extracted from it): rows in outline order (`level` = indent
  under the row above), dates `Y-m-d`; a row's linked issue is named only when the user can open it (`issue: null` otherwise, even
  if the row is linked). `PlanPolicy`: everybody who can open the project reads plans.
- Editing rows / bars / dependencies, creating issues from rows, sharing, versions: web only (`can.update` → "Open in browser").
  The linked issue's own dates: `GET /items/{id}`.

### Releases
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/releases?status=unreleased,released,archived` | app + verified | A project's releases (fix versions) in the web's order (unreleased by release date, released, archived), not paginated: `{id, project_id, name, status, start_date, release_date, released_at, overdue, published, progress: {total, done, in_progress, todo}, url, created_at, updated_at}` | Read-only | BRAI-214 | ready (v1.49.0, BRAI-415) |
| `GET /api/v1/releases/{id}` | app + verified | One release + `description`, `notes_markdown`, `notes_html`, `published_notes_markdown`, `notes_changed`, `public_url`, `items: [{id, key, title, state: todo\|in_progress\|done, status, type, assignee, is_subtask, url}]` (≤ 500, by key), `can.manage` | Read-only | BRAI-214 | ready (v1.49.0, BRAI-415) |

- What the web's releases pages show members (`ReleasePolicy`: everybody who can open the project). Clients of the client portal
  are not project members and have no app sign-in into a project; they only ever see published notes on the public page
  (`public_url`). Planning, shipping, writing / generating / publishing notes: web only (`can.manage`).

### Files
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `POST /api/v1/uploads` | app + verified, 30/min | Multipart `file` + `workspace` (slug): an image (jpeg, png, gif, webp ≤ 10 MB) or document (pdf, doc(x), xls(x), ppt(x), txt, csv, zip, rar ≤ 20 MB) for rich text → 201 `{data: {url, name, size, mime_type, kind: image\|file, markdown}}`. Not a member → 404; SVG / scripts / executables / other types → 422; storage full → 402 `limit_reached`. Counted in the workspace's file usage at once (`editor_uploads`; the bytes are reserved under a row lock, so concurrent uploads can't both take the last room — Codex review) | Full | BRAI-267 | ready (v1.49.0, BRAI-416) |

- The web editor's upload (`Support\Files\EditorUploads`, shared with `FileController` → `/upload/image`, `/upload/document`): stored on
  the public disk under `uploads/images|documents/<uuid>.<ext>` (`Uploads`: never the client's name, the extension from the content
  for images, active types never kept). Put `markdown` (`![name](url)` / `[name](url)`) into the Markdown of a doc, an issue
  description or a comment; the Markdown converter keeps images both ways (http(s) or site paths only — `data:` / `javascript:` are
  dropped).
- **Storage limit:** `Support\Files\FileRoom` (= the web's `file.room`) → 402 `limit_reached` (`limit.kind: files`); since BRAI-416 also
  on `POST /items/{id}/attachments` and bookmark files (they skipped it before). Checked inside the idempotent write: a replayed
  `request_id` stores nothing and is never refused.
- **Files that belong to something have their own upload:** issue attachments (`POST /items/{id}/attachments`), bookmark files
  (`POST /bookmarks/{id}/items`). There is no "upload first, attach by id" step and no file registry: every file's `url` is its
  public storage address (no token, as on the web), so there is no `GET /files/{id}`, no signed URL and no server-side thumbnail.
  Files on captures / tickets: not in the web either (later).

### Bookings
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/booking-pages` | app + verified | The user's booking pages by title (read): `{uuid, title, enabled, public_url, timezone, workspace: {slug, name}, types_count, upcoming_count, types: [{id, name, duration_minutes, enabled, public_url}], url}`, not paginated — share a type's link | Read + share | BRAI-226 | ready (v1.49.0, BRAI-419) |
| `GET /api/v1/bookings?status=upcoming\|past\|cancelled&page=<uuid>` | app + verified | The bookings guests made on the user's pages: upcoming (default, soonest first), past / cancelled (latest first); cursor-paginated, `updated_since`. Another user's `page` → 404 | Full | BRAI-228 | ready (v1.49.0, BRAI-419) |
| `GET /api/v1/bookings/{uuid}` | app + verified | One booking. Not of the user's pages → 404 | Full | BRAI-228 | ready (v1.49.0, BRAI-419) |
| `POST /api/v1/bookings/{uuid}/cancel` | app + verified, 30/min | The host cancels `{reason?}` (≤ 1000): calendar event removed, card commented, the guest emailed → `{data: booking}`; already cancelled → 200, nothing changes | Full | BRAI-228 | ready (v1.49.0, BRAI-419) |

Booking: `{uuid, type, page: {uuid, title}, starts_at, ends_at, guest_name, guest_email, guest_timezone, answers: [{question,
answer}], status: confirmed|cancelled, cancelled_by: host|guest|null, cancel_reason, cancelled_at, conference_url, in_calendar,
unchecked_calendars: [name], card: {id, key, url}|null}`.
- What the web's booking page "Bookings" tab shows its host (`Support\Booking\BookingRows`, shared with `BookingPageController`):
  booking pages are **personal** (`BookingPagePolicy`: the owner, while still in the page's workspace). The guest's details are
  the host's to see; nobody else gets bookings (another user's booking → 404). Booked times also show in `GET /calendar/events`
  (`type: booking`).
- **Rescheduling is the guest's** (their manage link, a public page): the web has no host reschedule, so neither has the API
  (cancel with a reason; the guest books again). The public booking page, slots and the guest's links are public routes: never in
  the App API.
- Web only: creating / editing booking pages and meeting types (availability, buffers, rules, questions, calendars, consent,
  "also create a card"), templates, embedding (`url` opens the settings).

### Helpdesk
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/helpdesks` | app + verified | A project's helpdesks in the web's order: `{id, project_id, slug, name, description, columns: [{id, name, color, is_done}], open_count, url, can: {manage_tickets}}`, not paginated. Can't open the project → 404 | Full | BRAI-231 | ready (v1.49.0, BRAI-420) |
| `GET /api/v1/helpdesks/{id}` | app + verified | One helpdesk + `priorities: [{id, name, color}]` (for `priority_id`) | Full | BRAI-231 | ready (v1.49.0, BRAI-420) |
| `GET /api/v1/helpdesks/{id}/tickets?status=open\|done\|all&column=&assignee=me\|unassigned\|<id>&priority=&sla=` | app + verified | The ticket inbox, newest first, cursor-paginated, `updated_since`. `sla` = the web's SLA filter: `breached`, `at_risk`, `on_track`, `paused`, `met`, `none` | Full | BRAI-231, BRAI-233 | ready (v1.49.0, BRAI-420) |
| `GET /api/v1/tickets/{id}` | app + verified | One ticket: `description_markdown` / `_html`, `fields` (custom field values), `attachments`, `replies` (oldest first, ≤ 500: `{id, from: contact\|agent, author, internal, body_markdown, body_html, email_status, attachments, created_at}`), `watching`, `can.update` | Full | BRAI-231, BRAI-232 | ready (v1.49.0, BRAI-420) |
| `PATCH /api/v1/tickets/{id}` | app + verified | `{column_id?, position?, resolution_message?, notify_contact?, assignee_id?, priority_id?, subject?, updated_at?}` → the ticket. Into a done column = resolve (message saved as a reply, `notify_contact` emails the customer); a stale `updated_at` → 409; viewers → 403 | Full | BRAI-231 | ready (v1.49.0, BRAI-420) |
| `POST /api/v1/tickets/{id}/replies` | app + verified, 60/min | `{body (Markdown), internal?, request_id?}`: a reply (emailed to the customer, threaded through the helpdesk mailbox when connected) or an internal note → 201 `{data: reply}` (200 on a replay); viewers → 403 | Full | BRAI-232 | ready (v1.49.0, BRAI-420) |

Ticket (list): `{id, key, helpdesk_id, subject, status: {column_id, name, is_done}, priority: {id, name, color}|null, assignee:
{id, name, avatar_url}|null, contact: {name, email}|null, sla: {state: none|breached|respond|resolve|paused|met, due_at,
at_risk, breached_since, breached_targets: [first_response|resolution]}, created_at, updated_at, completed_at, url}`.
- **The agent side only.** What the web's helpdesk shows a project member (`HelpdeskPolicy::view` / `TicketPolicy`): every
  member reads tickets, internal notes and the customer's name + address; project editors / admins (and workspace admins) reply
  and change them (`can.update`, else 403). Never the ticket's request metadata (IP, browser), the contact's portal token or the
  forwarding address. The customer side — widget, support page, portal, public replies — is public and **never in the App
  API**.
- Shared with the web's `TicketController`: `Support\Helpdesks\TicketActions` (reply / internal note: customer email or the
  mailbox's threaded reply, watcher notifications, `ticket.replied` webhooks; move: watchers, resolution reply, "resolved"
  email, `ticket.resolved`; assignee notification). SLA state: `Support\Helpdesks\Sla\SlaStatus` (the PHP twin of
  `resources/js/lib/sla.ts`; `of()` per ticket, `filter()` in SQL — keep the three in sync).
- Reply bodies are Markdown without @mentions (the thread is the customer's email; a mention comes out as plain "@Name").
  Files: put `POST /uploads` links / images into the Markdown; separate ticket attachments from the app: later.
- Web only: creating / deleting tickets and helpdesks, columns, custom fields, SLA rules and business hours, templates, the
  widget, the support page, the mailbox / forwarding setup, resending a failed email, watching.

### Leads
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/lead-forms` | app + verified | A project's lead forms (read): `{id, project_id, slug, name, status: draft\|published, public_url, leads_count, columns: [{id, name, color, is_done, leads_count}], url, can: {manage_leads}}`, not paginated | Read | BRAI-236 | ready (v1.49.0, BRAI-421) |
| `GET /api/v1/lead-forms/{id}/leads?column=&assignee=me\|unassigned\|<id>` | app + verified | The leads: one pipeline column in board order (`column`), or all newest first; cursor-paginated, `updated_since` | Read + move | BRAI-236 | ready (v1.49.0, BRAI-421) |
| `GET /api/v1/leads/{id}` | app + verified | One lead | Read + move | BRAI-236 | ready (v1.49.0, BRAI-421) |
| `PATCH /api/v1/leads/{id}` | app + verified | `{column_id?, position? (0 = top; default end), name?, email?, phone?, company?, assignee_id?}` → the lead; viewers → 403; an assignee who can't open the project → 422 | Read + move | BRAI-236 | ready (v1.49.0, BRAI-421) |
| `GET /api/v1/lead-forms/{id}/analytics?days=7\|30\|90` | app + verified | The funnel: `{days, views, starts, submits, conversion, leads, series: [{date, views, submits}], steps: [{index, title, conditional, sessions}]}` (unique sessions) | Read | BRAI-236 | ready (v1.49.0, BRAI-421) |

Lead: `{id, uuid, number, lead_form_id, column_id, name, email, phone, company, source, answers: [{id, label, type, display}],
utm: {utm_source, …}|null, referrer, consent_at, consent_text, assignee: {id, name, avatar_url}|null, created_at, updated_at}`.
- The member side of lead forms (`LeadFormPolicy`): every project member reads forms and leads (what the web's lead board and lead
  sheet show: `LeadController::present`); project editors / admins and workspace admins move, edit and assign them. Never the
  visitor's IP / browser, the form's webhook URL / secret or its inbound token. Shared with the web: `Support\LeadForms\LeadActions`
  (move with the column's order, contact edits, assignment notification) and `LeadFormAnalytics` (the Analytics tab).
- **No lead notes:** the web has none (the card's `POST /leads/{id}/notes` waits for a web feature). Deleting leads: web only.
- The public form (`/f/{slug}`: view, submit, events) and the inbound API are for visitors / other systems, never the App API.
  The builder (questions, logic, design, publishing, webhooks, inbound token, columns): web only (`url`).

### Portals
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/projects/{id}/feedback-portal` | app + verified | The project's feedback portal: `{id, project_id, enabled, slug, title, public_url, counts: {pending, published, rejected, merged}, url}`. Project / workspace admins only (403 for other members); none → 404 | Read + status | BRAI-237 | ready (v1.49.0, BRAI-422) |
| `GET /api/v1/feedback-portals/{id}/posts?tab=pending\|published\|rejected\|merged` | app + verified | One moderation tab: pending oldest first, published most votes first (default), rejected / merged newest first; cursor-paginated | Read + status | BRAI-237 | ready (v1.49.0, BRAI-422) |
| `PATCH /api/v1/feedback-posts/{id}` | app + verified | `{status: open\|planned\|building\|released\|declined, note?}` → the idea (followers notified as on the web); merged → 422 | Read + status | BRAI-237 | ready (v1.49.0, BRAI-422) |
| `POST /api/v1/feedback-posts/{id}/moderate` | app + verified | `{decision: approve\|reject}` → the idea | Read + status | BRAI-237 | ready (v1.49.0, BRAI-422) |
| `GET /api/v1/projects/{id}/client-portal` | app + verified | The project's client portal: `{id, project_id, title, slug, enabled, public_url, url, can: {publish, manage}}`; every project member; none → 404 | Read-only | BRAI-238 | ready (v1.49.0, BRAI-422) |
| `GET /api/v1/client-portals/{id}/deliverables` | app + verified | What was shared, current versions first, newest first (≤ 300, not paginated): `{id, uuid, kind: page\|plan, title, version, status: awaiting\|approved\|changes_requested\|superseded\|withdrawn, decision, published_at, published_by, decisions_count, comments_count, last_decision_at}` | Read-only | BRAI-238 | ready (v1.49.0, BRAI-422) |
| `GET /api/v1/client-deliverables/{id}` | app + verified | One deliverable + `message`, `markdown` / `html` (doc snapshot), `client_url`, `decisions: [{name, email, decision, note, version, created_at}]`, `comments: [{author, from_team, visibility: client\|internal, body, created_at}]`, `versions` | Read-only | BRAI-238 | ready (v1.49.0, BRAI-422) |

- **Team side only.** Voters (feedback) and clients (client portal) are portal users, not workspace members: they sign in on the
  public portal pages and never use the App API.
- Feedback = the web's project Feedback page (`FeedbackModeration`: `counts`, `tabQuery`, `row` are shared with
  `FeedbackModerationController`): project admins see the author's name + address and the linked card, as on the web.
- Client portal = the web's team page (`ClientPortalPolicy::viewAny`: every project member; `Deliverables::summary` / `detail`
  shared with `ClientPortalTeamController`): clients' addresses only for project admins (`email: null` otherwise); internal
  notes are the team's and are listed. Plan snapshots: open `url` (the web renders the Gantt).
- Web only: portal settings and on / off, merging ideas, creating / linking cards, the audit log, inviting / revoking clients,
  publishing / withdrawing deliverables, answering clients, share links.

### Insights
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/workspaces/{slug}/insights/{tab}?period=this_month\|last_month\|last_30\|last_90\|custom&from=&to=&project=` | app + verified | One Insights tab (`overview`, `team`, `projects`, `helpdesk`, `usage`) → `{data: {tab, filters: {period, from, to, project, timezone}, projects: [{id, name}], insights: {…the web tab's numbers}}}`. Workspace admins only (403 members, 404 others); unknown tab → 404 | Read-only | BRAI-248, BRAI-243 | ready (v1.49.0, BRAI-423) |
| `GET /api/v1/projects/{id}/health?from=&to=&tz=` | app + verified | Live metrics of the period (default the last 14 days, ≤ a year; bad range → 422) + the last 30 status updates → `{data: {from, to, timezone, metrics: {as_of, timezone, today, period, values, unknown, lists}, reports: [{id, status, summary, author, as_of, created_at, ai_assisted}], can_create, url}}`. Every project member | Read-only | BRAI-247 | ready (v1.49.0, BRAI-423) |
| `GET /api/v1/projects/{id}/workload?from=&weeks=4\|6\|8\|12` | app + verified | The workload grid → `{data: {from (Monday), weeks_count, timezone, current_week, weeks, members: [{id, name, avatar_display, capacity_minutes, work, cells: [{week, capacity_minutes, allocated_minutes, free_minutes, over_minutes, load_percent, state: ok\|over\|unset\|unavailable, allocations}]}], team, review, can_plan, url}}`. Every project member | Read-only | BRAI-246 | ready (v1.49.0, BRAI-423) |

- The web pages' own Support classes and role checks: Insights = `App\Support\Insights\*Insights::compute` + `InsightsFilters`
  (`InsightsController::compute`; workspace admins: `WorkspacePolicy::update`), health = `HealthMetrics` + `HealthWindow`
  (extracted from `ProjectHealthController`; `ProjectHealthReportPolicy::viewAny`), workload = `WorkloadGrid::build`
  (`WorkloadPolicy::viewAny`; override notes only for planners, as on the web). Their nested shapes are passed as the web page gets
  them (aggregates, member names / avatars, no addresses), so the OpenAPI schema types the top level only: read the web page's
  props (`resources/js/Pages/Insights/*`, `Projects/Health`, `Workload/Index`) for the inner fields.
- **Team time** = the Insights `team` tab (hours, completed / open / overdue per member). The billing time overview (amounts,
  clients, rates) and every CSV / Excel export stay on the web (money never goes to the apps, BRAI-242 / 244 / 245).
- Web only: writing status updates (and the AI draft), planning workload (capacity, overrides, allocations), exports.

### Sync
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/sync/changes?cursor=&limit=&scopes=work,task_lists,my_day,captures&task_lists=mine,1,2&captures=all\|inbox` | app + verified, 120/min | Offline copy: what changed since the cursor → `{data: [change], next_cursor, has_more, reset, project_ids, task_lists}`. No cursor = a full run (`reset: true`) | Full | BRAI-263, BRAI-264 | ready (v1.49.0, BRAI-425; v2 BRAI-441) |
| `POST /api/v1/sync` | app + verified, 60/min | The outbox after being offline: `{changes: [{request_id, type, target?, base?, data}]}` (≤ 50, in order) → 200 `{data: [result]}`, one per change, same order; `entity` = the feed's record (`record_type`) | Full | BRAI-263, BRAI-264 | ready (v1.49.0, BRAI-425; v2 BRAI-441) |

Change: `{type: work|task_list|task|capture|my_day, id, op: upsert|delete, project_id, updated_at, data}`. `data` is the
record exactly as its own endpoint returns it (`work` = a My Work item, `task_list` / `task` = Task lists, `capture` =
Captures, `my_day` = My Day), `null` for a delete. `task_list` records also carry `can: {update, delete, manage_items}`
(as `GET /task-lists/{id}`: hide "Add task" / editing offline when `manage_items` is false). `id`: `issue:12` / `task:5`
(work), the id as text (task_list, task), the uuid (capture), the date (my_day).
- **Runs.** Start without a cursor (optionally `scopes` — default all four —, `task_lists` and `captures`): a **full
  run**, `reset: true`. Page with `next_cursor` while `has_more`; when `has_more` is false, drop every cached record of
  the scope the run didn't send and **keep that `next_cursor`**. Call again with it later: a **delta run** (`reset:
  false`) — upserts (add / replace) and deletes (drop). `next_cursor` is never null. New `scopes`, another `captures`
  filter, a cursor of another account, an unknown one or one older than 30 days → a full run again. The same record may
  come twice (runs overlap by 10 s): apply by `updated_at`.
- **Kept task lists** (`task_lists`, ≤ 50): ids, `mine`, or both (`mine,12`). **`mine`** = the lists the user works in,
  across all their workspaces: lists pinned in the sidebar, lists with open tasks assigned to them, lists where they
  created or were assigned a task changed in the last 30 days, lists they created in the last 30 days — only lists they
  can open, ids first, then in that order, at most 50. Resolved at the start of every run; the answer's `task_lists` says
  which lists the run keeps. **Changes are additive:** other `task_lists` with a cursor (or a `mine` set that changed by
  itself) give a delta run in which only the new lists come in full (the list + all its tasks) and dropped lists come as
  `task_list` deletes (drop the list and its tasks); the other scopes go on from the cursor. A change in the middle of a
  run restarts that run with the same `since`.
- **Scopes.** `work` = the work assigned to the user in every project they can open (as `GET /me/work`; a full run sends
  the open work, delta runs also completed work with `done: true`), `task_lists` = the kept lists + all their tasks,
  `my_day` = today's plan as one record (sent when it changed — also when an item's `done` or `updated_at` changed, e.g.
  after a `work.done`: the next run re-sends it; a new local day changes it), `captures` = the private inbox: `captures=all`
  (default) every state, `captures=inbox` only captures neither processed nor archived — in a delta run a capture that
  left the inbox comes as a `capture` delete, one restored to it as an upsert.
- **Deletes** come for issues / tasks / lists that were deleted (also: their board / list deleted), work reassigned to
  someone else, lists no longer kept (or no longer visible), captures that left the inbox (filter `inbox`) and captures
  deleted for good. **Lost access** is not a delete: drop every cached record whose `project_id` is not in `project_ids`
  (the projects the user can open now). Nothing of other users or other projects is ever in the feed (every query is the
  user's own or limited to `project_ids`).
- **Permissions:** role changes don't touch a list, so the cursor remembers the kept lists' abilities: when they change,
  the next run re-sends every kept list with the new `can`.
- **Cursors** are opaque. v2 (BRAI-441) still reads v1 cursors (BRAI-425): they go on where they were; their next run
  re-sends the kept lists once (now with `can`). A cursor that can't be read starts a full run (`reset: true`).

Result of a change: `{request_id, type, status, http_status, replayed, entity_type, record_type, entity, conflicts, applied, message, errors, activities, default_activity_id}`.
`entity` (applied, conflict) is the server's current state as the **feed's record** of `record_type` — replace the cached
record with it: `issue.*` and `work.done` → `work`, `list_task.create|update` → `task`, `list_task.reorder` → `task_list`
(with `can`), `capture.create` → `capture`, `my_day.add|remove` → `my_day`, `issue.comment` → `comment` (as
`GET /items/{id}/comments`), `timer.*` → `timer` (as `GET /me/timer`), `notification.read` → `notification`,
`notifications.read_all` → `unread_count`. `activities` / `default_activity_id`: only on an `invalid` `timer.stop` (else null). Replays render the current state too; something gone or no longer visible → `entity: null`.
The web's `POST /offline/sync` keeps its own snapshots.

| status | http_status | the device does |
|---|---|---|
| `applied` | 200 | done: drop it from the outbox, store `entity` (the server state) |
| `conflict` | 409 | done: the conflicting fields were **not** written (`conflicts: {field: {base, mine, theirs}}`), the others were (`applied`); show mine / theirs, a new change if the user keeps theirs |
| `deleted` | 410 | drop it: the thing is gone or no longer visible (same answer for both) |
| `gone` | 410 | drop it: the timer is no longer running / the notification is gone (`timer.stop|discard`, `notification.read`); nothing was written |
| `waiting` | 424 | keep it: it refers to an offline create (`target.ref`) that hasn't arrived — send that one first |
| `forbidden` | 403 | drop it: the user may not do this (e.g. a viewer) |
| `invalid` | 422 | drop it: `errors` per field (also: a `request_id` reused for a different change → `errors.id`) |
| `limit_reached` | 402 | keep it and tell the user: a plan limit stops it (`issue.create` once the workspace's content storage is used up — the same check as the REST create, in `Issues::create`; Codex review). `code: "limit_reached"` and `limit` {kind, limit, usage, plan, workspace, billing_url} as the REST 402; nothing was stored, so the same change applies once there is room |
| `rate_limited` | 429 | keep it, send again in a minute (240 changes / min / user over all batches) |
| `error` | 500 | keep it, send again later |

- **The web's offline engine** (`App\Support\Offline\OfflineSync`, BRAI-82, the same as `POST /offline/sync`): types
  `list_task.create {task_list_id, title, status?}` · `list_task.update {title?, status?}` + `target` · `list_task.reorder
  {task_list_id, items: [{id}|{ref}]}` · `issue.update {title?, column_id?, priority_id?, assigned_to?, due_date?}` +
  `target` · `issue.comment {body}` (plain text) + `target` · `issue.create {board_id, title, column_id?, priority_id?,
  assigned_to?, due_date?, description?, item_type_id?}` (as the online create: `Issues::create`; `description` Markdown like REST, `item_type_id` a type of the board's project, else `invalid`) · `capture.create {text, url?, intended_type?, source?}` (source defaults to the app's name) ·
  `my_day.add|remove {work_type: board|list, work_id|work_ref}` · **`work.done {work_type, work_id|work_ref, done}`** (the
  circle: `WorkCompletion::setDone`, as `PATCH /me/work/{id}`).
- **Timer and inbox changes** (App API only in practice; same services as REST: `WorkTimers`, `NotificationInbox`):
  - `timer.start {issue_id, description?, started_at}` — `started_at` (the device clock) is clamped to ≥ now − 12 h and
    ≤ now. Another timer running → `conflict` (409) with the **running** timer as `entity` (`conflicts: []`, `message`).
    `task_id` → `invalid` (list tasks have no work log); an issue the user can't open → `deleted`; no edit right → `forbidden`.
  - `timer.stop {timer_id, stopped_at, activity_id?}` — `stopped_at` clamped to ≥ the start and ≤ now; logs
    `round(minutes)` (≥ 1) once on the user's **local day of the start**, note = the timer's `description`. Activity
    missing / unusable where required → `invalid` with `errors.activity_id` **plus `activities: [{id, name}]` and
    `default_activity_id`** (as the REST 422); the timer runs on — send a new change with `activity_id`. Not running
    any more (stopped / discarded elsewhere, someone else's id) → `gone`. Lost edit right on the issue → `applied`, timer
    stopped, nothing logged (`message` says so).
  - `timer.discard {timer_id}` — stop without logging; not running → `gone`.
  - `notification.read {notification_id}` — someone else's / deleted → `gone`.
  - `notifications.read_all {before}` — marks only inbox notifications created ≤ `before` (send the moment the user
    tapped "read all"), so what arrived later stays unread.
  - `record_type`: `timer` (entity = `GET /me/timer`'s data, the state **now**, incl. `today_logged_seconds`),
    `notification` (a notification row with `summary` / `url`), `unread_count` (`{unread_count}`).

```json
POST /api/v1/sync
{"changes": [
  {"request_id": "6f1c…", "type": "timer.start", "data": {"issue_id": 412, "description": "Reproduce the bug", "started_at": "2026-11-02T08:58:00Z"}},
  {"request_id": "a03e…", "type": "timer.stop", "data": {"timer_id": 77, "stopped_at": "2026-11-02T09:40:00Z"}},
  {"request_id": "c11d…", "type": "notifications.read_all", "data": {"before": "2026-11-02T09:41:12Z"}},
  {"request_id": "d42b…", "type": "issue.create", "data": {"board_id": 9, "title": "Offline task", "description": "**Steps** for @[Ada](user:3)", "item_type_id": 2}}
]}
→ {"data": [
  {"request_id": "6f1c…", "type": "timer.start", "status": "conflict", "http_status": 409, "replayed": false, "entity_type": "timer",
   "record_type": "timer", "entity": {"running": true, "timer": {"id": 76, "started_at": "…", "description": null, "issue": {…}, …}, "today_logged_seconds": 5400, …},
   "conflicts": [], "applied": [], "message": "A timer is already running — stop it first.", "errors": null, "activities": null, "default_activity_id": null},
  {"request_id": "a03e…", "type": "timer.stop", "status": "invalid", "http_status": 422, "entity": null,
   "errors": {"activity_id": ["Pick an activity."]}, "activities": [{"id": 4, "name": "Programming"}], "default_activity_id": 4, …},
  {"request_id": "c11d…", "type": "notifications.read_all", "status": "applied", "record_type": "unread_count", "entity": {"unread_count": 1}, …},
  {"request_id": "d42b…", "type": "issue.create", "status": "applied", "record_type": "work", "entity": {"id": "issue:913", …}, …}
]}
```
- **Updates carry `base.fields`**: each changed field's value as the device saw it (missing → `invalid`). A field
  someone else changed meanwhile is a conflict, never overwritten; already having the wanted value is a no-op.
- **`request_id`** (uuid, made once per change on the device) = idempotency for 30 days: a retry answers the stored
  result with `replayed: true`. `target: {id}` (server id) or `{ref}` (the request_id of an earlier create, in this
  batch or before); `data.work_ref` likewise.
- Changes are applied in order, each in its own transaction: a bad one never stops or rolls back the others. Same
  policies as the web (`ItemPolicy::update`, `BoardPolicy::createItems`, `TaskListPolicy::manageItems`).
- Docs are not part of offline sync: drafts go through the live editor / `PATCH /pages/{uuid}` with `revision` (409).

### Realtime
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
| `GET /api/v1/realtime` | app + verified | How to connect → `{data: {enabled, url (ws:// / wss://, null when off), token_path, token_ttl, max_channels, protocol: 1, channel_types: [{type, id: number\|uuid, access}]}}`. `enabled: false` → no live updates on this server: poll | Full | BRAI-196, BRAI-265 | ready (v1.49.0, BRAI-426) |
| `POST /api/v1/realtime/token` | app + verified, 60/min | `{channels: ["user.12", "board.4", …]}` (≤ 50) → `{data: {token, expires_at, expires_in, url, channels, denied, user: {id, name, avatar}}}`: a token for the channels the user may join, the rest in `denied` (never a 403). Live off → 404 | Full | BRAI-196, BRAI-265 | ready (v1.49.0, BRAI-426) |

Brainitive's realtime is **not** Laravel broadcasting / Reverb / Pusher: it is the app's own **live channels** (BRAI-151) on its
realtime server (`realtime/`, the same process as live co-editing), and the App API gives native clients the same tokens the web's
tabs get (`LiveToken::grant`, shared with the web's `POST /live/token`). Access = `LiveChannels` (the web's policies, one copy).
- **Who may join:** `user.{id}` only your own id; `board.{id}` / `item.{id}` / `plan.{id}` / `ticket.{id}` / `page.{uuid}` when you
  can open the board / issue / plan / helpdesk ticket / doc on the web (`view` policy; a trashed record or parent = no access). A
  suspended user gets nothing. The realtime server **re-checks every minute** and sends `{type: "revoked", channel}` for lost
  access — drop that view's channel then.
- **Connect:** open a WebSocket to `url`, send `{"type": "auth", "token": "…"}` as the **first message within 10 s** (never in the
  URL) → `{type: "ready", user: {id}, channels: [granted]}`. Then `{type: "subscribe", channel, presence?: true}` → `{type:
  "subscribed", channel, members}`; `{type: "unsubscribe", channel}`; `{type: "ping"}` → `{type: "pong"}` (optional keep-alive; the server pings every 30 s).
  New channels: ask for a new token and send another `auth` on the same socket (grants add up). Reconnect with a new token
  (tokens only need to be valid to join; `token_ttl` = 120 s by default).
- **Server → client:** `{type: "event", channel, event, payload}` · `{type: "presence", channel, members: [{id, name, avatar}]}`
  (with `presence: true`) · `{type: "signal", channel, name, data, from: {id, name}}` · `{type: "revoked", channel}` ·
  `{type: "error", code: forbidden|too_many_subscriptions|not_subscribed|invalid_signal|rate_limited, channel?}`. Close codes:
  4400 bad request, 4401 bad / expired token (or a token of another device on the same socket), 4403 access check failed,
  **4410 device signed out**, 4429 too many messages / sockets, 1001 server restart.
- **4410 = this device was signed out** (sign-out, "revoke device" in Profile / another device, refresh-token reuse, password
  change, suspension, "sign out everywhere"): live tokens **and live co-editing tokens** (`POST /pages/{uuid}/collab-token`,
  claim `dev`, BRAI-377) of the App API carry the device, and the realtime server closes its live-channel **and document**
  sockets within seconds (at most the minute re-check); a document socket of a revoked device that tries to connect is
  refused with 4410 (Hocuspocus reason `device-revoked`), not 4401. Don't reconnect: drop the stored tokens and show sign-in, as when `POST /auth/refresh` answers
  401 `invalid_grant`. Its remaining live tokens are refused with 4410 as well. The user's other devices and web tabs stay connected.
- **Client → server signals:** `{type: "signal", channel, name, data}` (subscribed channels, ≤ 1 KB, ~4/s): today only `typing`
  on `item.{id}` — `{}` while typing (≤ every 2 s), `{stop: true}` on send / cancel; receivers expire it after 4.5 s.
- **Limits:** 50 subscriptions per socket, 20 sockets per user, 8 messages/s (burst 40).
- **Payloads are ids, never content** (a socket whose access just ended can still get up to a minute of events): on an event,
  **refetch through the normal endpoint** (`GET /items/{id}`, `GET /boards/{id}/items`, `GET /notifications` …). Events during a
  disconnect are lost: refetch everything a view shows after every (re)connect.
- **Echo suppression:** send a per-window `X-Live-Client: <8–40 chars [A-Za-z0-9_-]>` header with your writes; board events then carry
  `client_id` + `actor_id`, and a window ignores an event only when **both** are its own.

**Event catalog** (every event Brainitive publishes; `client_id` / `actor_id` = the sender, on board / plan / item events of a write
that sent `X-Live-Client`):

| Channel | Event | Payload | When |
|---|---|---|---|
| `user.{id}` | `notification.created` | `{id}` (notification uuid) | a notification lands in the user's inbox (any type) → `GET /notifications` / badge |
| `user.{id}` | `notifications.changed` | `{unread_count}` | read / unread / archive / read-all, on the web or through the API |
| `board.{id}` | `item.created` · `item.restored` | `{id, column_id}` | a card is created / restored on the board |
| `board.{id}` | `item.moved` | `{id, column_id, from_column_id, position, fields}` | a card changed column (`fields`: what else changed) |
| `board.{id}` | `item.updated` | `{id, fields}` | a card changed (`fields` = attribute names, e.g. `title`, `assigned_to`, `due_date`, `labels`, `comments`, `attachments`, `links`, `time_spent`) |
| `board.{id}` | `item.deleted` | `{id}` | a card was deleted |
| `board.{id}` | `item.moved_out` · `item.moved_in` | `{id}` · `{id, column_id}` | a card moved to / from another board |
| `board.{id}` | `items.changed` | `{ids, deleted_ids, count, fields}` | several cards at once (bulk edit, sprint moves, subtrees; ≤ 100 ids per message) → reload the board's items |
| `board.{id}` | `column.created` · `column.updated` · `column.deleted` | `{id, fields?}` | one column changed |
| `board.{id}` | `columns.reordered` · `columns.changed` | `{ids}` | columns reordered / several changed → reload the board |
| `board.{id}` | `sprint.changed` | `{id, action: created\|updated\|deleted\|planned\|active\|completed, status}` | a sprint was created, edited, started, completed, deleted |
| `board.{id}` | `board.updated` | `{fields}` | board settings changed (name, type, swimlanes …) |
| `board.{id}` | `board.deleted` · `board.refreshed` | `{}` | the board was deleted · an import changed it (reload everything) |
| `item.{id}` | `item.updated` | `{id, fields}` | the issue changed (`title`, `description`, any card field; `deleted` / `moved_out` / `restored` as field names) → `GET /items/{id}` |
| `item.{id}` | `comment.created` · `comment.updated` · `comment.deleted` | `{id, comment_id, parent_id}` (`id` = the issue) | a comment was added / edited / removed → `GET /items/{id}/comments` |
| `item.{id}` | signal `typing` | `{}` / `{stop: true}` | someone is writing a comment (see signals) |
| `plan.{id}` | `plan.updated` | `{revision, fields}` | the Gantt plan changed (rows, bars, settings) → `GET /plans/{id}` |
| `plan.{id}` | `plan.deleted` | `{}` | the plan was deleted |
| `plan.{id}` | `issues.changed` | `{ids}` | issues linked to plan rows changed (dates, status, assignee …) |
| `ticket.{id}` | `reply.created` · `reply.updated` · `reply.deleted` | `{id, reply_id}` (`id` = the ticket) | a reply / internal note (agents, customer mail, portal) → `GET /tickets/{id}` |
| `page.{uuid}` | — | — | presence only (no events yet). Live doc / whiteboard editing is Yjs on the same server, not channels: `POST /pages/{uuid}/collab-token` (docs); whiteboards' live drawing is web only (BRAI-208) |

Presence (`presence: true`) is used by the web on `board.{id}`, `item.{id}` and `plan.{id}` ("who's here" avatars). Not live:
task lists, My Day, captures, calendar, notifications' content — poll those or use `GET /sync/changes`.

### Web only (no App API in v1, BRAI-424)
These mobile cards get **no native API in v1**: the apps show a short note and open the web page in the browser (desktop: the
webview). Their `## API: web only — open in browser` texts are under "Mobile card texts".

| Card | Feature | Why web only | What the app does |
|---|---|---|---|
| BRAI-195 | Recurring issues, issue templates | Setup UI on the web | Created issues are ordinary issues (Issues) |
| BRAI-207, 208, 210 | Whiteboard drawing, live cursors, stickies → issues | Excalidraw editor | View only: `GET /whiteboards/{uuid}` |
| BRAI-223, 224 | Connecting Google / Microsoft / CalDAV / ICS calendars | OAuth and app passwords stay on the web | Status `GET /calendar/connections`; events through Calendar |
| BRAI-227 | Booking availability, buffers, rules | Booking page builder | Bookings + share links (Bookings) |
| BRAI-234 | Helpdesk templates, widget, support page, customer portal | Public-facing setup | Work tickets (Helpdesk) |
| BRAI-235 | Lead form builder | Builder, publishing, webhooks | Pipeline + analytics (Leads) |
| BRAI-239 | Share links, embeds, public pages | Public web pages | `public_url` / `url` fields, open in the browser |
| BRAI-242, 244, 245 | Rates, clients, VAT, billing reports, invoices | Money never goes to the apps | Own time (Time tracking) |
| BRAI-250, 251 | Automation rule builder, test runner, logs | Admin tooling | — (automation runs on the server) |
| BRAI-253 | Outgoing webhooks | Secrets and delivery admin | — |
| BRAI-254, 255 | AI writing, BYO keys, AI meeting notes → issues | Key settings and review flows on the web | Read the results (docs, issues) |
| BRAI-257 | Extension / CLI / integration entry points | Other clients with their own auth (MCP, CLI, extension) | Deep links = the web URLs of issues, docs, boards |
| BRAI-260, 261 | Jira / Trello / Notion imports, project export / restore | Long-running admin jobs, file handling | Own data export (`/me/data-export`) |

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
- GET /api/v1/search?q=&type=&workspace=&project[]=&status=&assignee=&updated=&limit=&cursor= — global search (the web's ranking + visibility), page cursor, counts per group, places by name; without `q`: the user's recent content (≤ 12)
Scope: app · OpenAPI: docs/api/openapi.json#/Search · Registry: docs/ai/API.md#search
Built in: BRAI-392 (desktop) · Mobile scope: Full
```

**BRAI-183** (notifications)
```
## API (ready)
- GET /api/v1/notifications?filter=all|unread|mentions|assigned&archived= — the inbox, newest first, cursor-paginated; each row has summary (the bell's sentence, plain text) + url (absolute, null when the user can't open it); first page ETag → 304
- GET /api/v1/notifications/unread-count — badge count (ETag / 304)
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
- GET /api/v1/me/work?due=overdue|today|upcoming&status=open|done|all&updated_since= — assigned issues + tasks, soonest due first, cursor-paginated (ETag → 304)
- PATCH /api/v1/me/work/{id} — {done: true|false}: complete / reopen (issue:<id> or task:<id>), as the web's circle
- GET /api/v1/me/my-day — today's plan in the user's order + carry_over_count (ETag → 304)
- POST /api/v1/me/my-day/items {id} · PATCH /api/v1/me/my-day/items/{id} {done} · DELETE /api/v1/me/my-day/items/{id} · POST /api/v1/me/my-day/carry-over — answer the updated My Day
- GET /api/v1/me/today — work due today / overdue + My Day + today's events in one call (ETag → 304), for a "Today" screen / widget
Scope: app · OpenAPI: docs/api/openapi.json#/My Work · Registry: docs/ai/API.md#my-work
Built in: BRAI-396 (desktop) · Mobile scope: Full (reordering My Day: web only for now)
```

**BRAI-222** (unified calendar)
```
## API (ready)
- GET /api/v1/calendar/events?from=&to=&sources[]=&me_only= — connected calendars, Google Tasks, bookings and dated issues / tasks for the user's local days (≤ 62), sorted; timed = UTC instants, all-day = dates (end inclusive)
- POST /api/v1/calendar/events {connection_id, title, date, time?, end_date?, end_time?, location?, description?, conference?, attendees?, request_id?} — new event on one of the user's writable calendars
- GET|PATCH|DELETE /api/v1/calendar/events/{id} — one event (+ description) · move / edit the sent fields · delete (id = event:<connection>:<event id>, URL-encoded)
- Issues / tasks in the calendar: PATCH /api/v1/items/{id} (reschedule), PATCH /api/v1/me/work/{id} (done)
Scope: app · OpenAPI: docs/api/openapi.json#/Calendar · Registry: docs/ai/API.md#calendar
Built in: BRAI-418 (desktop) · Mobile scope: Full (read-only calendars → 422 calendar; connecting providers: web only)
```

**BRAI-220** (personal calendar / time blocks and task reminders)
```
## API (ready)
- POST /api/v1/calendar/events — block time in a connected calendar (a timed event; default zone = the user's)
- GET /api/v1/calendar/events?sources[]=board&sources[]=list&me_only=1 · GET /api/v1/me/work?due=today,upcoming — upcoming assignments
- POST /api/v1/me/timer/start · /stop — focus sessions (Time tracking) · task reminders: reminder_minutes on PATCH /api/v1/task-lists/{id}/items/{task}
Scope: app · OpenAPI: docs/api/openapi.json#/Calendar, #/My Work · Registry: docs/ai/API.md#calendar
Built in: BRAI-418 (desktop) · Mobile scope: Full (push delivery of reminders: later)
```

**BRAI-225** (Google Meet and Microsoft Teams meetings)
```
## API (ready)
- GET /api/v1/calendar/meeting-calendars — the user's calendars that can add a call, with conference_providers (google_meet / teams)
- GET /api/v1/meetings?subject_type=item|page&subject_id= — the meetings of an issue / doc (join_url, attendees, organizer, notes doc, can {remove, cancel})
- POST /api/v1/meetings {subject_type, subject_id, connection_id, title, date, start_time, duration, conference, member_ids?, emails?, request_id?} — schedule; the provider invites
- DELETE /api/v1/meetings/{id}?cancel_event=1 — unlink, or also cancel the event (organiser) · POST /api/v1/meetings/{id}/notes — open / create the notes doc
- POST /api/v1/calendar/events {conference: google_meet|teams} — a meeting from the calendar (no issue); events carry conference_url to join
Scope: app · OpenAPI: docs/api/openapi.json#/Calendar · Registry: docs/ai/API.md#calendar
Built in: BRAI-418 (desktop) · Mobile scope: Full (connecting Google / Microsoft: web only)
```

**BRAI-229** (calendar feed + provider status)
```
## API (ready)
- GET /api/v1/calendar/connections — connected calendars and Google Tasks lists: name, colour, provider, read-only, sync_error ("Reconnect …")
- GET /api/v1/me/calendar-feed — the private ICS link (url, webcal_url, subscribe links for Apple / Google / Outlook) and its options
- PUT /api/v1/me/calendar-feed {options?} — turn on / change what it contains · POST /api/v1/me/calendar-feed/reset — new link · DELETE — off
Scope: app · OpenAPI: docs/api/openapi.json#/Calendar · Registry: docs/ai/API.md#calendar
Built in: BRAI-418 (desktop) · Mobile scope: Status + feed link (connecting / reconnecting providers: web only)
```

**BRAI-223** (connect Google Calendar, Google Tasks, Outlook 365) · **BRAI-224** (connect iCloud / CalDAV, ICS subscriptions)
```
## API: web only — open in browser
Connecting, reconnecting and removing calendar accounts (OAuth, app passwords, ICS links) happens on the web (Calendar → Add calendar).
The apps show the connections and their status (GET /api/v1/calendar/connections: read_only, sync_error) and use them (GET|POST|PATCH|DELETE /api/v1/calendar/events).
Registry: docs/ai/API.md#calendar · Decided in: BRAI-418
```

**BRAI-262** (data export)
```
## API (ready)
- POST /api/v1/me/data-export — request "Download my data" (one per hour; 202)
- GET /api/v1/me/data-export — status of the latest exports + download link (opens in the web session)
Scope: app · OpenAPI: docs/api/openapi.json#/Connected apps · Registry: docs/ai/API.md#connected-apps
Built in: BRAI-394 (desktop) · Mobile scope: Request export (account deletion web only)
```

**BRAI-216** (task lists + recurring to-dos)
```
## API (ready)
- GET /api/v1/projects/{id}/task-lists — a project's lists in order, with todo / done counts, cursor-paginated
- POST /api/v1/projects/{id}/task-lists — new list {name, description?} (project admins / editors)
- GET /api/v1/task-lists/{id} — one list: counts, can {update, delete, manage_items}, watching
- GET /api/v1/task-lists/{id}/items?status=todo|done|all — the tasks in order, cursor-paginated, updated_since
- POST /api/v1/task-lists/{id}/items — add a task {title, notes (Markdown), assignee_id, due_date, due_time, start_date, start_time, recurrence, reminder_minutes}
- PATCH /api/v1/task-lists/{id}/items/{task} — change sent fields; status done on a repeating task moves it to its next date
- DELETE /api/v1/task-lists/{id}/items/{task} → 204 · POST /api/v1/task-lists/{id}/items/reorder {ids}
Scope: app · OpenAPI: docs/api/openapi.json#/Task lists · Registry: docs/ai/API.md#task-lists
Built in: BRAI-397 (desktop) · Mobile scope: Full (renaming / deleting lists: web only for now)
```

**BRAI-217** (captures inbox)
```
## API (ready)
- GET /api/v1/captures?state=inbox|processed|archived&q= — the private inbox, newest first, cursor-paginated
- GET /api/v1/captures/counts — inbox / processed / archived counts (badge)
- POST /api/v1/captures — save an idea {text, url?, intended_type?, source?, request_id?}
- GET|PATCH /api/v1/captures/{uuid} · POST …/archive · POST …/restore · DELETE (archived only) → 204
- GET /api/v1/captures/{uuid}/preview?type=issue|task|page|bookmark|snippet — the fields to edit before converting
- POST /api/v1/captures/{uuid}/convert {type, destination_id, fields} — into an issue, task, doc, bookmark or snippet (idempotent; 402 for issues / docs at the storage limit)
- GET /api/v1/capture-destinations — boards (+ columns), task lists, projects, bookmark groups, snippet collections the user can write to (ETag → 304)
Scope: app · OpenAPI: docs/api/openapi.json#/Captures · Registry: docs/ai/API.md#captures
Built in: BRAI-398 (desktop) · Mobile scope: Full
```

**BRAI-258** (share sheet → capture)
```
## API (ready)
- POST /api/v1/captures — {text, url?, source?, request_id?}: the shared text / link lands in the private inbox (source defaults to "Brainitive Mobile"); send a request_id so a retried share never saves twice
- GET /api/v1/capture-destinations + POST /api/v1/captures/{uuid}/convert — to file it straight into a board, list, doc, bookmark group or snippet collection
Scope: app · OpenAPI: docs/api/openapi.json#/Captures · Registry: docs/ai/API.md#captures
Built in: BRAI-398 (desktop) · Mobile scope: Full (files / images on a capture: later)
```

**BRAI-218** (snippets + bookmark collections)
```
## API (ready)
- GET|POST /api/v1/workspaces/{slug}/snippet-collections — collections (snippets_count) · create (workspace editors / admins)
- GET|PATCH|DELETE /api/v1/snippet-collections/{id} — one collection with can {update, delete, manage_snippets}
- GET|POST /api/v1/snippet-collections/{id}/snippets — snippets with content (copy), cursor-paginated · add one
- GET|PATCH|DELETE /api/v1/snippets/{id}
- GET|POST /api/v1/workspaces/{slug}/bookmarks — the bookmark groups the user may see, with counts · create
- GET|PATCH|DELETE /api/v1/bookmarks/{id} — one group with can {update, delete}
- GET|POST /api/v1/bookmarks/{id}/items — links, to-dos, notes, files (multipart) · PATCH|DELETE /api/v1/bookmarks/{id}/items/{item}
Scope: app · OpenAPI: docs/api/openapi.json#/Snippets, #/Bookmarks · Registry: docs/ai/API.md#snippets, #bookmarks
Built in: BRAI-399 (desktop) · Mobile scope: Full (collaborators, image icons, reordering: web only)
```

**BRAI-241** (timer, log work, personal timesheets)
```
## API (ready)
- GET /api/v1/me/timer — the running timer (issue, elapsed, description) + the activities for the stop dialog + today_logged_seconds (finished logs on the local today, without the running timer) (ETag → 304; elapsed ticks outside the tag)
- POST /api/v1/me/timer/start {id: "issue:<id>", description?} (409 when one runs; description = the default work-log note) · POST /api/v1/me/timer/stop {timer_id, time?, activity_id?, note?, spent_on?} (activity missing → 422 with `activities: [{id, name}]` to pick from) · POST /api/v1/me/timer/discard {timer_id}
- GET /api/v1/work-logs?workspace=&from=&to= — own work logs, newest first, cursor-paginated
- POST /api/v1/work-logs {project_id, issue_id?, spent_on, time, activity_id?, note?} · GET|PATCH|DELETE /api/v1/work-logs/{id} (billed = locked, read-only)
- GET /api/v1/me/timesheet?workspace={slug}&week=Y-m-d — the week: entries, minutes per day, loggable projects, activities
Scope: app · OpenAPI: docs/api/openapi.json#/Time tracking · Registry: docs/ai/API.md#time-tracking
Built in: BRAI-400 (desktop) · Mobile scope: Full for own time (rates, clients, VAT, invoices: web only)
```

**BRAI-242** (billable activities, rates, client mapping) · **BRAI-244** (clients, VAT, invoice configuration) · **BRAI-245** (billing reports, invoices)
```
## API: web only — open in browser
Rates, clients, VAT, billing reports and invoices stay on the web (workspace admins; money is never sent to the apps).
The apps log time with the member-facing activity list (GET /api/v1/me/timer, GET /api/v1/me/timesheet → activities).
Registry: docs/ai/API.md#time-tracking · Decided in: BRAI-400
```

**BRAI-187** (boards + columns)
```
## API (ready)
- GET /api/v1/projects/{id}/boards — a project's boards (kanban / scrum), cursor-paginated
- GET /api/v1/boards/{id} — columns (WIP limit, done, card counts), swimlanes, done retention, running sprint, can {…}
- GET /api/v1/boards/{id}/items?column=&include_old_done= — the cards as the board shows them (Scrum: running sprint), cursor-paginated, updated_since
- POST /api/v1/items/{id}/move {column_id, position?, with_subtasks?} — drag & drop; done column completes it, watchers notified, web boards update live
Scope: app · OpenAPI: docs/api/openapi.json#/Boards · Registry: docs/ai/API.md#boards
Built in: BRAI-403 (desktop) · Mobile scope: Read + move cards (columns, swimlanes, board settings: web only)
```

**BRAI-188** (issues: create, view, edit, assign)
```
## API (ready)
- POST /api/v1/boards/{id}/items — new issue (Markdown description, type, priority, assignee, labels, dates, points, estimate, parent, custom fields; request_id; 402 at the storage limit)
- GET /api/v1/items/{id} · GET /api/v1/items/by-key/{KEY} — the issue view (description as Markdown, fields, parent, sprint, development, can {…})
- PATCH /api/v1/items/{id} — change the sent fields; send updated_at to get 409 instead of overwriting a newer change
- DELETE /api/v1/items/{id} → 204 (with subtasks) · POST /api/v1/items/{id}/move — change the column (Boards)
Scope: app · OpenAPI: docs/api/openapi.json#/Issues · Registry: docs/ai/API.md#issues
Built in: BRAI-404 (desktop) · Mobile scope: Full (duplicate, move to another board: web only)
```

**BRAI-252** (GitHub integration and linked development work)
```
## API (ready) — read only
- GET /api/v1/items/{id} → development: {connected, branches, commits, commit_count, pull_requests (primary, state), deployments, merge_column}
Scope: app · OpenAPI: docs/api/openapi.json#/Issues · Registry: docs/ai/API.md#issues
Built in: BRAI-404 (desktop) · Mobile scope: Read-only (connecting repositories, merge transitions: web only)
```

**BRAI-189** (comments, mentions, watchers, attachments, history)
```
## API (ready)
- GET|POST /api/v1/items/{id}/comments — the discussion (oldest first, replies via parent_id) · comment in Markdown, mentions @[Name](user:<id>) notify; request_id
- GET /api/v1/items/{id}/watchers · POST|DELETE /api/v1/items/{id}/watch — watch / unwatch yourself
- GET|POST /api/v1/items/{id}/attachments (multipart file ≤ 10 MB) · DELETE /api/v1/items/{id}/attachments/{attachment}
- GET /api/v1/items/{id}/activity — the history, newest first, cursor-paginated
Scope: app · OpenAPI: docs/api/openapi.json#/Issues · Registry: docs/ai/API.md#issues
Built in: BRAI-405 (desktop) · Mobile scope: Full (editing / deleting comments and managing other watchers: not on the web yet / web only)
```

**BRAI-190** (subtasks, checklists, issue links)
```
## API (ready)
- GET /api/v1/items/{id}/children — direct subtasks in board order (create one: POST /api/v1/boards/{id}/items with parent_id; 3 levels at most)
- GET|POST /api/v1/items/{id}/links — linked issues (blocks, is blocked by, duplicates, relates to, clones), read from this issue's side; restricted ones without details
- DELETE /api/v1/items/{id}/links/{link} → 204
Scope: app · OpenAPI: docs/api/openapi.json#/Issues · Registry: docs/ai/API.md#issues
Built in: BRAI-406 (desktop) · Mobile scope: Read + light edit (checklists = subtasks or Markdown task lists in the description)
```

**BRAI-191** (custom fields, filters, saved views)
```
## API (ready)
- GET /api/v1/projects/{id}/config — issue types, priorities, labels, custom fields (+ options, boards), fix versions
- GET /api/v1/boards/{id}/views — saved views (shared + own, default), params sanitized
- GET /api/v1/boards/{id}/items?q=&assignee=me|unassigned|user-<id>&status=&labels=&priority=&type=&quick=recent,due,blocked&f<field id>= — the board's filters; a view's params work as they are
- PATCH /api/v1/items/{id} {fields: {"<field id>": value | null}} — set custom field values
Scope: app · OpenAPI: docs/api/openapi.json#/Boards · Registry: docs/ai/API.md#boards
Built in: BRAI-407 (desktop) · Mobile scope: Use views + set field values (field definitions and view editing: web only)
```

**BRAI-192** (Scrum backlog + sprints)
```
## API (ready)
- GET /api/v1/boards/{id}/sprints?status=active,planned,completed — sprints in planning order with issue counts and points
- GET /api/v1/sprints/{id} · GET /api/v1/sprints/{id}/items — one sprint and its issues in backlog order
- GET /api/v1/boards/{id}/backlog — issues in no sprint, in backlog order
- POST /api/v1/sprints/{id}/items {item_ids} · POST /api/v1/boards/{id}/backlog/items {item_ids} — plan issues into a sprint / back to the backlog (subtasks follow)
Scope: app · OpenAPI: docs/api/openapi.json#/Sprints · Registry: docs/ai/API.md#sprints
Built in: BRAI-408 (desktop) · Mobile scope: Read + move items (create / start / complete sprints, ranking: web only)
```

**BRAI-193** (sprint reports: burndown, velocity)
```
## API (ready) — read only
- GET /api/v1/sprints/{id}/burndown — remaining points (or issues) per day + the ideal line
- GET /api/v1/boards/{id}/velocity — committed vs. completed of the last completed sprints
Scope: app · OpenAPI: docs/api/openapi.json#/Sprints · Registry: docs/ai/API.md#sprints
Built in: BRAI-408 (desktop) · Mobile scope: Read-only
```

**BRAI-194** (timeline and calendar issue views)
```
## API (ready)
- GET /api/v1/projects/{id}/items?from=&to=&board=&status= — a project's dated issues (start … due touching the days), sorted by first day, cursor-paginated
- PATCH /api/v1/items/{id} {start_date, due_date, start_time, due_time} — reschedule (send updated_at to avoid overwriting a newer change)
Scope: app · OpenAPI: docs/api/openapi.json#/Issues · Registry: docs/ai/API.md#issues
Built in: BRAI-409 (desktop) · Mobile scope: Read + reschedule
```

**BRAI-195** (recurring issues and templates)
```
## API: web only — open in browser
Recurring issue rules and issue templates are set up on the web (project settings / board). Issues they create are ordinary issues in the API (GET /api/v1/items/{id}, the board's items).
Registry: docs/ai/API.md#issues · Decided in: BRAI-409
```

**BRAI-198** (wiki page tree, folders, drafts and publishing)
```
## API (ready)
- GET /api/v1/projects/{id}/pages?parent=root|<uuid> — the page tree (docs, whiteboards, folders) with parent_uuid, in the web's order; drafts only the author's / admins'
- GET /api/v1/pages/{uuid} — one page: Markdown, revision, breadcrumbs, can {update, delete, publish, create}
- POST /api/v1/projects/{id}/pages {name, kind, parent_uuid?, markdown?, publish?, request_id?} — new doc / whiteboard / folder (402 at the storage limit)
- POST /api/v1/pages/{uuid}/publish — publish a draft or a doc's changes (watchers notified; review-required docs need an approved review → 422)
- POST /api/v1/pages/{uuid}/move {parent_uuid, before_uuid?, after_uuid?}
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#docs
Built in: BRAI-411 (desktop) · Mobile scope: Read + light edit (delete, duplicate, archive, image icons: web only)
```

**BRAI-199** (rich document editor)
```
## API (ready) — Markdown editing
- GET /api/v1/pages/{uuid} — markdown (mentions @[Name](user:<id>)) for editing, sanitized html for rendering, revision
- PATCH /api/v1/pages/{uuid} {markdown, revision, name?, icon?} — replace the text; a stale revision → 409 (someone or a live editor saved since: reload)
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#docs
Built in: BRAI-411 (desktop) · Mobile scope: Light edit in Markdown (the full block editor, tables, embeds: web / desktop webview; live co-editing: BRAI-200)
```

**BRAI-200** (live collaborative editing, cursors and presence)
```
## API (ready) — for a native live editor
- GET /api/v1/pages/{uuid} → live: edit | view | null — whether live co-editing is offered to the user
- POST /api/v1/pages/{uuid}/collab-token — realtime server url, document, short-lived token (ask for every reconnect), edit flag
- POST /api/v1/pages/{uuid}/collab/bootstrap {epoch, claim} · PUT /api/v1/pages/{uuid}/collab/draft {epoch, html, name, icon, vector, baseline?} — the browser's bootstrap + live save; 409 + epoch = document replaced, reconnect
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#docs
Built in: BRAI-412 (desktop) · Mobile scope: Later — v1 reads (GET /pages/{uuid}) and edits Markdown with the revision check (BRAI-199)
```

**BRAI-201** (version history, duplicate, restore, document metadata)
```
## API (ready) — history read part
- GET /api/v1/pages/{uuid}/versions — published versions, newest first (author, note), cursor-paginated
- GET /api/v1/pages/{uuid}/versions/{version} — one version's text (Markdown + html)
- GET /api/v1/pages/{uuid} — metadata: creator, last editor, version, published_at, watching, breadcrumbs
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#docs
Built in: BRAI-413 (desktop) · Mobile scope: Read (restore, duplicate: web only)
```

**BRAI-202** (inline comments, review requests and approvals)
```
## API (ready)
- GET /api/v1/pages/{uuid}/reviews · GET /api/v1/pages/{uuid}/reviews/{review} — reviews the user may see; snapshot as Markdown, reviewers, threads, can {decide, comment}
- POST /api/v1/pages/{uuid}/reviews/{review}/decision {decision: approved|changes_requested, comment?}
- POST /api/v1/pages/{uuid}/reviews/{review}/comments {body, parent_uuid? | quote + prefix + suffix} — new thread on a passage or a reply; request_id
- PATCH /api/v1/pages/{uuid}/review-threads/{thread} {resolved}
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#docs
Built in: BRAI-413 (desktop) · Mobile scope: Read + reply + approve (requesting / cancelling reviews, "review required": web only)
```

**BRAI-203** (smart links, linked issues and backlinks)
```
## API (ready)
- GET /api/v1/pages/{uuid}/links — related content + backlinks (referenced by) of a doc, visible content only
- GET /api/v1/links/resolve?workspace=&refs[]=item:12&urls[]=<brainitive link> — live chips (title, status, assignee, priority); restricted ones without a title
- Issues: GET /api/v1/items/{id}/links (linked issues, BRAI-190)
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#docs
Built in: BRAI-413 (desktop) · Mobile scope: Read (linking content: web only)
```

**BRAI-206** (mobile whiteboard canvas, pan / zoom)
```
## API (ready) — view only
- GET /api/v1/projects/{id}/pages — whiteboards are kind: whiteboard in the page tree
- GET /api/v1/whiteboards/{uuid} — the Excalidraw scene (elements, canvas settings, images) to render read-only; ETag → 304
Scope: app · OpenAPI: docs/api/openapi.json#/Whiteboards · Registry: docs/ai/API.md#whiteboards
Built in: BRAI-414 (desktop) · Mobile scope: View only (drawing: web / desktop)
```

**BRAI-209** (autosave, history, duplicate and export whiteboards)
```
## API (ready) — read part
- GET /api/v1/pages/{uuid}/versions — the whiteboard's history (publishes + 30-minute checkpoints)
- GET /api/v1/whiteboards/{uuid} → preview_image_url — the PNG of the last "Save & exit" (no server-side export; render the scene for an exact image)
Scope: app · OpenAPI: docs/api/openapi.json#/Whiteboards · Registry: docs/ai/API.md#whiteboards
Built in: BRAI-414 (desktop) · Mobile scope: View only (autosave, duplicate, export: web / desktop)
```

**BRAI-207** (shapes, text, stickies, images, templates) · **BRAI-208** (realtime collaboration with named cursors) · **BRAI-210** (sticky notes → issues, live cards)
```
## API: web only — open in browser
Drawing, templates, live cursors and turning sticky notes into issues run in the web's whiteboard editor (the desktop app's webview).
The apps show the scene read-only: GET /api/v1/whiteboards/{uuid}.
Realtime (BRAI-426): live drawing and named cursors are the whiteboard's Yjs document (web only); the apps can show "who's here" on the page.{uuid} live channel (POST /api/v1/realtime/token) — no events there yet, reload the scene on open / pull-to-refresh.
Registry: docs/ai/API.md#whiteboards · Decided in: BRAI-414
```

**BRAI-212** (Gantt plans, rows, timelines and milestones)
```
## API (ready) — read only
- GET /api/v1/projects/{id}/plans — a project's plans (rows count, first / last date), cursor-paginated
- GET /api/v1/plans/{id} — rows in outline order (level, who, status, notes) with their bars (task / milestone, dates, colour, progress)
Scope: app · OpenAPI: docs/api/openapi.json#/Plans · Registry: docs/ai/API.md#plans
Built in: BRAI-415 (desktop) · Mobile scope: Read-only (editing plans: web only)
```

**BRAI-213** (Gantt dependencies, issue links and synchronization)
```
## API (ready) — read only
- GET /api/v1/plans/{id} → dependencies [{from, to}] (bar ids, finish → start) and each row's linked issue {id, key, title} (only issues the user can open)
- GET /api/v1/items/{id} — the linked issue (its dates follow the bar)
Scope: app · OpenAPI: docs/api/openapi.json#/Plans · Registry: docs/ai/API.md#plans
Built in: BRAI-415 (desktop) · Mobile scope: Read-only (linking rows, creating issues from rows: web only)
```

**BRAI-214** (release management and release notes)
```
## API (ready) — read only
- GET /api/v1/projects/{id}/releases?status= — releases in the web's order with progress (done / in progress / to do)
- GET /api/v1/releases/{id} — description, notes (Markdown + html; working copy and published), issues with state, public page link
Scope: app · OpenAPI: docs/api/openapi.json#/Releases · Registry: docs/ai/API.md#releases
Built in: BRAI-415 (desktop) · Mobile scope: Read-only (planning, shipping, notes, publishing: web only)
```

**BRAI-267** (files, downloads, uploads, sharing and mobile attachments)
```
## API (ready)
- POST /api/v1/uploads (multipart file + workspace) — camera image / file for a doc, issue description or comment: public url + a markdown snippet to insert (images ≤ 10 MB, documents ≤ 20 MB; 402 when the storage is full)
- POST /api/v1/items/{id}/attachments (multipart, ≤ 10 MB) · GET /api/v1/items/{id}/attachments — files on an issue (402 when the storage is full)
- POST /api/v1/bookmarks/{id}/items {type: file} (multipart) — files in a bookmark group
- Downloads: every file's url is its public address (no token); no thumbnails — use the image itself
Scope: app · OpenAPI: docs/api/openapi.json#/Files · Registry: docs/ai/API.md#files
Built in: BRAI-416 (desktop) · Mobile scope: Full (resumable uploads, files on captures / tickets: later)
```

**BRAI-226** (booking pages, templates and event types)
```
## API (ready) — read + share
- GET /api/v1/booking-pages — the user's booking pages with their meeting types' public links (share sheet) and upcoming counts
- Creating / editing pages, meeting types, guest questions, templates and embeds: web only (url opens the page's settings)
Scope: app · OpenAPI: docs/api/openapi.json#/Bookings · Registry: docs/ai/API.md#bookings
Built in: BRAI-419 (desktop) · Mobile scope: Read + share links (the builder: web only)
```

**BRAI-228** (guest booking confirmation, cancellation and rescheduling)
```
## API (ready)
- GET /api/v1/bookings?status=upcoming|past|cancelled&page= — the bookings on the user's pages: guest, answers, call link, card; cursor-paginated
- GET /api/v1/bookings/{uuid} · POST /api/v1/bookings/{uuid}/cancel {reason?} — the host cancels; the guest is emailed, the calendar event removed
- Booked times also come in GET /api/v1/calendar/events (type: booking)
Scope: app · OpenAPI: docs/api/openapi.json#/Bookings · Registry: docs/ai/API.md#bookings
Built in: BRAI-419 (desktop) · Mobile scope: View + cancel (rescheduling is the guest's own link; the public booking page: web)
```

**BRAI-227** (availability, time zones, buffers and booking rules)
```
## API: web only — open in browser
Availability, time zones, buffers, notice, daily limits, holidays and busy calendars are set per meeting type in the web's booking page builder.
The apps show the result: bookings (GET /api/v1/bookings) and the pages' share links (GET /api/v1/booking-pages → url opens the settings).
Registry: docs/ai/API.md#bookings · Decided in: BRAI-419
```

**BRAI-231** (helpdesk ticket inbox, comments and customer details)
```
## API (ready)
- GET /api/v1/projects/{id}/helpdesks · GET /api/v1/helpdesks/{id} — helpdesks with statuses (columns), open counts, priorities
- GET /api/v1/helpdesks/{id}/tickets?status=open|done|all&assignee=me|unassigned|<id>&priority=&sla= — the inbox, newest first, cursor-paginated
- GET /api/v1/tickets/{id} — the thread (customer, agents, internal notes), customer name + email, custom fields, files, SLA
- PATCH /api/v1/tickets/{id} {column_id, assignee_id, priority_id, subject, resolution_message?, notify_contact?, updated_at?} — status / assign / resolve
Scope: app · OpenAPI: docs/api/openapi.json#/Helpdesk · Registry: docs/ai/API.md#helpdesk
Built in: BRAI-420 (desktop) · Mobile scope: Full for agents (creating / deleting tickets, attachments upload: web for now)
```

**BRAI-232** (email-to-ticket and threaded replies)
```
## API (ready)
- POST /api/v1/tickets/{id}/replies {body (Markdown), internal?, request_id?} — reply to the customer (emailed, threaded through the helpdesk's mailbox when connected) or an internal note
- GET /api/v1/tickets/{id} → replies[].email_status (queued / sent / failed) and the customer's earlier messages
- Mailbox (IMAP) and forwarding setup, resending failed emails: web only
Scope: app · OpenAPI: docs/api/openapi.json#/Helpdesk · Registry: docs/ai/API.md#helpdesk
Built in: BRAI-420 (desktop) · Mobile scope: Reply composer + history (email setup: web only)
```

**BRAI-233** (helpdesk SLA rules, alerts and reports)
```
## API (ready) — SLA state
- GET /api/v1/helpdesks/{id}/tickets?sla=breached|at_risk|on_track|paused|met|none — the web's SLA filter
- Every ticket: sla {state: respond|resolve|breached|paused|met|none, due_at, at_risk, breached_since, breached_targets}
- Breach alerts arrive as notifications (GET /api/v1/notifications); SLA rules, business hours and reports: web only
Scope: app · OpenAPI: docs/api/openapi.json#/Helpdesk · Registry: docs/ai/API.md#helpdesk
Built in: BRAI-420 (desktop) · Mobile scope: Badges + filter (rules and reports: web only)
```

**BRAI-234** (helpdesk templates, widget and customer portal management)
```
## API: web only — open in browser
Helpdesk templates, the website widget, the public support page, the customer portal and their share controls are set up on the web (helpdesk settings).
The apps work the tickets (GET /api/v1/helpdesks/{id}/tickets, GET|PATCH /api/v1/tickets/{id}, POST /api/v1/tickets/{id}/replies); the helpdesk's url opens its settings.
Registry: docs/ai/API.md#helpdesk · Decided in: BRAI-420
```

**BRAI-236** (leads pipeline and form analytics)
```
## API (ready)
- GET /api/v1/projects/{id}/lead-forms — a project's lead forms with the pipeline columns and lead counts
- GET /api/v1/lead-forms/{id}/leads?column=&assignee= — the leads of one column in board order (or all, newest first): contact, answers, UTM, referrer, consent; cursor-paginated
- GET|PATCH /api/v1/leads/{id} {column_id, position, name, email, phone, company, assignee_id} — move between columns, edit, assign (the assignee is notified)
- GET /api/v1/lead-forms/{id}/analytics?days=7|30|90 — views, starts, submits, conversion, daily series, step drop-off
Scope: app · OpenAPI: docs/api/openapi.json#/Leads · Registry: docs/ai/API.md#leads
Built in: BRAI-421 (desktop) · Mobile scope: See and move lead cards + analytics (new-lead alerts: notifications; webhooks: web only)
```

**BRAI-235** (lead form builder)
```
## API: web only — open in browser
The lead form builder — questions, conditional logic, design, publishing, embeds, webhooks and the inbound API token — runs on the web.
The apps read the forms and work their leads (GET /api/v1/projects/{id}/lead-forms → url opens the builder).
Registry: docs/ai/API.md#leads · Decided in: BRAI-421
```

**BRAI-237** (feedback portal, voting and public roadmap)
```
## API (ready) — moderation + status
- GET /api/v1/projects/{id}/feedback-portal — the portal, public link and ideas per moderation tab (project admins)
- GET /api/v1/feedback-portals/{id}/posts?tab=pending|published|rejected|merged — ideas with votes, author, status, linked card; cursor-paginated
- POST /api/v1/feedback-posts/{id}/moderate {decision: approve|reject} · PATCH /api/v1/feedback-posts/{id} {status: planned|building|released|declined|open, note?} — followers are notified
Scope: app · OpenAPI: docs/api/openapi.json#/Portals · Registry: docs/ai/API.md#portals
Built in: BRAI-422 (desktop) · Mobile scope: Read + moderate + status (merging duplicates, settings, cards: web only; voting is the public portal's)
```

**BRAI-238** (client portal: deliverables and approvals)
```
## API (ready) — read only
- GET /api/v1/projects/{id}/client-portal — the portal, its link and the user's rights
- GET /api/v1/client-portals/{id}/deliverables — shared docs / plans with version and approval state (awaiting, approved, changes requested, superseded, withdrawn)
- GET /api/v1/client-deliverables/{id} — snapshot (Markdown + html), the clients' decisions and comments, internal notes, versions
Scope: app · OpenAPI: docs/api/openapi.json#/Portals · Registry: docs/ai/API.md#portals
Built in: BRAI-422 (desktop) · Mobile scope: Read-only (inviting clients, publishing, answering: web only)
```

**BRAI-239** (share links, embeds, public preview and deep links)
```
## API: web only — open in browser
Share links, embeds and the public pages (feedback portal, client portal, booking pages, lead forms, support page, public plans and release notes) are public web pages: the apps open them in the browser.
The App API returns their addresses (public_url / url fields) for sharing; deep links into the app use the web URLs of issues, docs and boards.
Registry: docs/ai/API.md#portals · Decided in: BRAI-422
```

**BRAI-248** (workspace admin insights dashboards)
```
## API (ready) — read only
- GET /api/v1/workspaces/{slug}/insights/{overview|team|projects|helpdesk|usage}?period=&from=&to=&project= — the web's Insights numbers: throughput, cycle time, team, support, leads, usage (workspace admins; 403 for members)
Scope: app · OpenAPI: docs/api/openapi.json#/Insights · Registry: docs/ai/API.md#insights
Built in: BRAI-423 (desktop) · Mobile scope: Read-only summaries (CSV exports: web only)
```

**BRAI-243** (team time overview, reports and Excel exports)
```
## API (ready) — read only, time without money
- GET /api/v1/workspaces/{slug}/insights/team?period=&project= — hours, completed / open / overdue issues per member (workspace admins)
- Own time: GET /api/v1/me/timesheet, GET /api/v1/work-logs (Time tracking)
- The billing time overview (clients, activities, rates, amounts) and the Excel export: web only
Scope: app · OpenAPI: docs/api/openapi.json#/Insights · Registry: docs/ai/API.md#insights
Built in: BRAI-423 (desktop) · Mobile scope: Read-only summaries (money and exports: web only)
```

**BRAI-246** (workload planning, team capacity and allocations)
```
## API (ready) — read only
- GET /api/v1/projects/{id}/workload?from=&weeks=4|6|8|12 — capacity vs planned hours per member and week, overload, review hints (missing estimates, stale plans)
- Planning (capacity, leave overrides, allocations): web only (can_plan + url)
Scope: app · OpenAPI: docs/api/openapi.json#/Insights · Registry: docs/ai/API.md#insights
Built in: BRAI-423 (desktop) · Mobile scope: Read-only
```

**BRAI-247** (project health reports and status updates)
```
## API (ready) — read only
- GET /api/v1/projects/{id}/health?from=&to= — due / overdue / blocked / unassigned / milestones / late plan rows with the issues behind them, plus the saved status updates (on track / at risk / off track)
- Writing a status update and the AI draft: web only (can_create + url)
Scope: app · OpenAPI: docs/api/openapi.json#/Insights · Registry: docs/ai/API.md#insights
Built in: BRAI-423 (desktop) · Mobile scope: Read-only
```

**BRAI-250** (workflow automation rule builder) · **BRAI-251** (automation test runner, logs and diagnostics)
```
## API: web only — open in browser
Automation rules — triggers, conditions, actions, dry runs, run history and failures — are built and checked on the web (project settings → Automation).
Their results (moved issues, comments, assignments) reach the apps as ordinary changes and notifications.
Registry: docs/ai/API.md#web-only-no-app-api-in-v1-brai-424 · Decided in: BRAI-424
```

**BRAI-253** (workspace outgoing webhooks)
```
## API: web only — open in browser
Webhook endpoints, signing secrets, delivery history, retries and test calls are workspace admin settings on the web.
Registry: docs/ai/API.md#web-only-no-app-api-in-v1-brai-424 · Decided in: BRAI-424
```

**BRAI-254** (AI-assisted writing, summaries, bring-your-own-key) · **BRAI-255** (AI meeting notes → issues)
```
## API: web only — open in browser
AI drafting, summaries, the workspace's AI keys and the meeting-notes → issues review run on the web, where the user reviews every proposal before it is saved.
The apps read what was saved (docs: GET /api/v1/pages/{uuid}, issues: GET /api/v1/items/{id}); a meeting's notes doc: POST /api/v1/meetings/{id}/notes.
Registry: docs/ai/API.md#web-only-no-app-api-in-v1-brai-424 · Decided in: BRAI-424
```

**BRAI-257** (extensions, external entry points and app deep links)
```
## API: web only — open in browser
The browser extension, the CLI and MCP clients have their own sign-in and APIs (/api/ext, /api/cli, /mcp) and are not part of the App API.
Items they create are ordinary issues, captures, bookmarks or docs in the App API; deep links use the web URLs (e.g. /workspaces/{w}/projects/{p}/boards/{b}#item-{id}) — open them in the app when it knows the path, else in the browser. The share sheet: POST /api/v1/captures (BRAI-258).
Registry: docs/ai/API.md#web-only-no-app-api-in-v1-brai-424 · Decided in: BRAI-424
```

**BRAI-260** (Jira, Trello and Notion imports) · **BRAI-261** (project export, restore and portable archives)
```
## API: web only — open in browser
Imports (mapping, preview, progress, errors) and project exports / restores are long-running admin jobs on the web.
The apps offer the user's own data export (POST|GET /api/v1/me/data-export, BRAI-262).
Registry: docs/ai/API.md#web-only-no-app-api-in-v1-brai-424 · Decided in: BRAI-424
```

**BRAI-263** (offline-first cache, local edits and sync queue)
```
## API (ready)
- GET /api/v1/sync/changes?scopes=work,task_lists,my_day,captures&task_lists=mine&captures=inbox — full run (reset: true) without a cursor, then delta runs with the kept next_cursor: upsert / delete per record (work, task_list, task, capture, my_day) + project_ids (drop other projects) + task_lists (the kept lists)
- task_lists=mine = the lists the user works in (pinned, open assigned tasks, recently worked in; all workspaces, ≤ 50); other lists later are additive (new ones in full, dropped ones as deletes); task_list records carry can.manage_items (hide "Add task" for viewers)
- captures=inbox keeps only the inbox (a capture that leaves it comes as a delete); My Day is re-sent after a done change
- POST /api/v1/sync {changes: [{request_id, type, target?, base?, data}]} — the outbox in batches of ≤ 50, one result per change (applied / conflict / deleted / gone / waiting / forbidden / invalid / limit_reached / rate_limited / error); request_id makes retries safe; entity = the record of record_type (work / task / task_list / capture / my_day / comment / timer / notification / unread_count): replace the cached copy
Types: list_task.create|update|reorder, issue.update|comment|create (create: + description Markdown, item_type_id), capture.create, my_day.add|remove, work.done — the web's offline mode (BRAI-82); timer.start {issue_id, description?, started_at} · timer.stop {timer_id, stopped_at, activity_id?} · timer.discard {timer_id} · notification.read {notification_id} · notifications.read_all {before} — device times clamped (start ≥ now−12 h, stop between start and now); a running timer → conflict with it; stop without a required activity → invalid + activities / default_activity_id; timer / notification no longer there → gone
Scope: app · OpenAPI: docs/api/openapi.json#/Sync · Registry: docs/ai/API.md#sync
Built in: BRAI-425, BRAI-441 (desktop) · Mobile scope: Full (My Day, My Work, kept task lists = mine, inbox captures)
```

**BRAI-264** (sync conflict resolution and recovery after interrupted updates)
```
## API (ready)
- POST /api/v1/sync — updates carry base.fields (the values the device saw); a field changed by someone else meanwhile → status conflict (409) with conflicts {field: {base, mine, theirs}}, never overwritten; the other fields are applied (applied: [...]); entity = the server's record in the feed's shape (record_type), so "theirs" replaces the cached copy directly
- Retries: the same request_id answers the stored result (replayed: true), so an interrupted batch is simply sent again; waiting (424) / limit_reached (402) / rate_limited / error → keep and resend, deleted / gone / forbidden / invalid → drop and tell the user
- GET /api/v1/sync/changes — a full run (reset: true) rebuilds a broken cache; a cursor older than 30 days or one that can't be read starts one by itself (v1 cursors keep working)
Scope: app · OpenAPI: docs/api/openapi.json#/Sync · Registry: docs/ai/API.md#sync
Built in: BRAI-425, BRAI-441 (desktop) · Mobile scope: Full (keep mine = a new change with the server value as base; keep theirs = drop it)
```

**BRAI-204** (offline document drafts and conflict resolution)
```
## API (ready) — via the docs endpoints, not the sync feed
- Docs are not in /api/v1/sync: keep a draft locally and save it with PATCH /api/v1/pages/{uuid} {markdown, revision}; a stale revision → 409 conflict (reload, show mine / theirs, save again with the new revision)
- Live co-editing (Yjs) merges offline edits by itself: POST /api/v1/pages/{uuid}/collab-token (BRAI-200)
Scope: app · OpenAPI: docs/api/openapi.json#/Docs · Registry: docs/ai/API.md#sync
Built in: BRAI-411, BRAI-412, BRAI-425 (desktop) · Mobile scope: Light edit offline (Markdown draft + revision check)
```

**BRAI-196** (live card updates, optimistic actions and conflict handling)
```
## API (ready)
- GET /api/v1/realtime — WebSocket url, token path, limits, channel types (enabled: false → poll)
- POST /api/v1/realtime/token {channels: ["board.4", "item.88", "user.12"]} — a token for the channels the user can open on the web (rest in denied); send it as the socket's first message
- Events on board.{id}: item.created|moved|updated|deleted|moved_in|moved_out|restored, items.changed, column.*, columns.*, sprint.changed, board.updated|deleted|refreshed — ids only, refetch GET /boards/{id}/items or GET /items/{id}
- Optimistic writes: send X-Live-Client: <window id> with POST /items/{id}/move, PATCH /items/{id} …; ignore events whose client_id + actor_id are your own. Conflicts: PATCH /items/{id} with updated_at → 409
Scope: app · OpenAPI: docs/api/openapi.json#/Realtime · Registry: docs/ai/API.md#realtime
Built in: BRAI-426 (desktop) · Mobile scope: Full (foreground socket; refetch everything on reconnect)
```

**BRAI-265** (live updates on boards, Gantt, comments, docs and whiteboards)
```
## API (ready)
- GET /api/v1/realtime · POST /api/v1/realtime/token — connection info + channel tokens (the web's channel rules; re-checked every minute, `revoked` when access ends)
- Channels: user.{id} (notification.created, notifications.changed), board.{id} (cards, columns, sprints), item.{id} (item.updated, comment.*, typing signal, presence), plan.{id} (plan.updated|deleted, issues.changed), ticket.{id} (reply.*), page.{uuid} (presence only)
- Presence: subscribe with presence: true → members [{id, name, avatar}]; reconnect = refetch (events while offline are lost)
- Docs: live co-editing through POST /api/v1/pages/{uuid}/collab-token (BRAI-200); whiteboards' live drawing: web only (BRAI-208)
Scope: app · OpenAPI: docs/api/openapi.json#/Realtime · Registry: docs/ai/API.md#realtime (event catalog)
Built in: BRAI-426 (desktop) · Mobile scope: Full (boards, issues, comments, Gantt, notifications); whiteboard drawing web only
```
