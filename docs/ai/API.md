# App API v1 — registry (desktop + mobile)

The JSON API of the first-party **desktop** (Tauri) and **mobile** (Expo) apps: `/api/v1`, guard
`app`, routes in `routes/app-api.php`. It is **separate from MCP, the CLI and the browser extension**
(`/mcp`, `/api/cli/*`, `/api/ext/v1/*`): own tokens, own guard, own throttles. Only the business
logic underneath (Support services, policies) is shared.

- Machine-readable spec: [`docs/api/openapi.json`](../api/openapi.json) (Scramble; regenerate with
  `php artisan scramble:export --path=docs/api/openapi.json`, a test fails when it is stale).
- Recipe for a new endpoint: [`skills/add-app-api-endpoint.md`](./skills/add-app-api-endpoint.md).
- Every endpoint also gets an `## API` section on its card on the **Mobile app** board, so the
  mobile work only has to read the card.

## Conventions

| | |
|---|---|
| Auth | `Authorization: Bearer bra_…` (access, 15 min). Refresh `brr_…` (60 days, single use). Tokens never in URLs. |
| Errors | `{"message": "…", "code": "…", "errors"?: {field: [..]}}` — 401 `unauthenticated`, 403 `forbidden`, 404 `not_found`, 405 `method_not_allowed`, 409 `conflict`, 422 `validation_failed`, 429 `rate_limited`, 500 `server_error`. 404 instead of 403 when the user must not learn that something exists. |
| Dates | ISO-8601 UTC (`2026-11-01T09:16:04Z`). |
| Lists | Cursor pagination `?cursor=&limit=` (default 50, max 100) → `{data: [...], next_cursor}`; `updated_since` for sync. |
| Writes | `Idempotency-Key` header accepted; updates carry `revision` / `updated_at` → 409 on conflict. |
| Limits | `throttle:app-api` 240/min per token + 600/min per IP; `throttle:app-auth` 20/min per IP on exchange / refresh. |
| CORS | None. Native clients only; browsers can't call it cross-origin. |

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

### Invitations & onboarding
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|
