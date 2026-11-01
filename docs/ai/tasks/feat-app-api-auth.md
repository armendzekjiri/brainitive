# App API: native sign-in + token lifecycle

**Branch:** feat/app-api-auth (from release/v1.49.0, foundation f09a3f03)
**Status:** done
**Started:** 2026-11-01
**Board cards:** desktop-app board — BRAI-383, BRAI-355, BRAI-357, BRAI-428 (lead updates the cards)

## Problem / goal
The desktop app (and later mobile) signs in through the system browser and needs the server side
of the contract in [`release-v1.49.0.md`](./release-v1.49.0.md): consent page, code exchange
(PKCE), refresh rotation with reuse detection, logout, webview session, and revocation when an
account is suspended or its password changes.

## Approach
Follow the contract exactly; tokens/codes come from the foundation (`AppTokens`, `AppAuthCodes`).
- **Consent page = Inertia page** (`Pages/AppAuth/Authorize.tsx`, GuestLayout), not Blade: after a
  password login the Inertia XHR follows `url.intended` to `/app/authorize`, and a Blade page
  would land in Inertia's error modal. The POST answers with `Inertia::location(<redirect>)`
  (409 + `X-Inertia-Location` for Inertia, 302 otherwise), so the browser itself navigates to
  the `brainitive://` URL. Invalid parameters render the same page with an error, status 400 —
  never a redirect to the app.
- **Web session:** `/app/session/{code}` consumes a `web_session` code, checks device + user,
  logs into `web` with remember-me (cookies survive app restarts, BRAI-357) and regenerates the
  session; redirect to a safe relative `?to=` or `/dashboard`.
- **Refresh:** one transaction, `lockForUpdate` on the device row matched by
  `refresh_token_hash` (active + unexpired → `AppTokens::issue`) or by `previous_refresh_hash`
  (= reuse → revoke `refresh_reuse` + email after commit) → else 401 `invalid_grant`.
- **Exchange:** PKCE (`hash_equals(challengeFor(verifier), challenge)`), platform per client,
  max devices (revoke least recently used, reason `limit`), then the token pair + device.
- Errors: `AppApiErrors::json()` gains an optional `$code` override (400/401 `invalid_grant`).

## Changes
- Routes: `routes/app-auth.php` (required from `web.php`): `GET/POST /app/authorize`
  (`app.authorize`, `app.authorize.store`), `GET /app/session/{code}` (`app.session`);
  `routes/app-api.php` Auth sections: `auth/exchange`, `auth/refresh` (public, `throttle:app-auth`),
  `auth/logout`, `auth/web-session` (`auth:app`).
- Controllers: `App\Http\Controllers\AppAuthController` (web), `AppApi\AuthController` (API).
- Form Requests: `AppApi\ExchangeCodeRequest`, `AppApi\RefreshTokenRequest`; Resources:
  `AppApi\TokenResource` (token response + `device`).
- Notification: `AppRefreshReuseNotification` (branded account mail) + EmailPreviews entry.
- Revocation hooks: `Admin\UserController::suspend` (`suspended`), `NewPasswordController` +
  `PasswordController` (`password_changed`).
- Frontend: `Pages/AppAuth/Authorize.tsx`.

## Test plan
- `tests/Feature/AppApi/AppSignInTest.php` (consent page, guest/unverified, validation, approve/deny,
  social + password logins return to url.intended), `AppAuthTokensTest.php` (exchange, PKCE,
  reuse, expiry, platform, max devices, refresh + reuse mail, logout, web-session, Passport → 401,
  suspended), `AppRevocationTest.php` (suspend, password change, reset).
- e2e `e2e/tests/app-sign-in.spec.ts`: Continue → captured `brainitive://auth/callback`,
  exchange + web-session via `request`, fresh context lands signed in; Cancel → `access_denied`.

## Docs to update
`docs/ai/API.md` (Auth table), `KNOWLEDGE.md` (app tokens decision, refresh reuse, serialize
refreshes).
