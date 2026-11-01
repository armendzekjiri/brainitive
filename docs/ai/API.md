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

### Auth
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|

### Account
| Method + path | Auth | Desktop use | Mobile | Card | Status |
|---|---|---|---|---|---|

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
| `POST /api/v1/workspaces` | app + verified email | First run "create a workspace": `{name, description?}` (name unique per owner, ≤ 255; description ≤ 1000) → 201 `{data: {id, slug, name, role: "admin"}}`; 402 `limit_reached` (free workspaces per owner). | Full | BRAI-175 | ready |

Plan limits under `/api/v1`: 402 `{message, code: "limit_reached", limit: {kind, limit, usage, plan,
workspace, billing_url}}` (`billing_url` null for people who can't upgrade; the message then says to
ask an admin) — `AppApiErrors::limitReached`, called from `LimitReached::render`.
