# Sign in with Apple (BRAI-449)

Branch `feat/sign-in-with-apple`.
**Status:** done

## Goal
"Sign in with Apple" on the web login, so it also appears on the native apps' sign-in page
(`/app/authorize`, opened in the system browser). App Store guideline 4.8: an app that offers
third-party sign-in (Google / GitHub / Microsoft) must offer Sign in with Apple too.

## Plan
1. Migration `add_apple_id_to_users_table`: `users.apple_id` string, nullable, unique (Apple's
   `sub`, stable per Apple developer team). User `$fillable`; Admin users list and the data export
   `sign_in_methods` gain `apple`.
2. Config `services.apple`: `APPLE_CLIENT_ID` (Services ID), `APPLE_TEAM_ID`, `APPLE_KEY_ID`,
   `APPLE_PRIVATE_KEY` (PEM, `\n` escapes, or a path to the `.p8`), `APPLE_REDIRECT_URI`
   (default `APP_URL/login/apple/callback`).
3. `App\Support\Auth\AppleLogin` — own client like `MicrosoftLogin` (no `socialiteproviders/*`
   is installed; the repo's other non-Google/GitHub provider is hand-written too):
   authorize URL (`response_type=code`, `response_mode=form_post`, `scope=name email`, state +
   nonce), client secret = ES256 JWT signed with the .p8 key at runtime (`firebase/php-jwt`,
   already in the lock via Socialite / Passport — now a direct requirement), code exchange at
   `appleid.apple.com/auth/token`, id_token **signature verified against Apple's JWKS**
   (cached 1 h, refetched once for an unknown `kid`) + `iss` / `aud` / `exp` / `nonce` checks.
   Email trusted only with `email_verified` = true (private relay addresses included: Apple
   verified them).
4. `AppleAuthController`: `GET login/apple` (guest) → Apple. Apple POSTs the result
   cross-site (`form_post`): the SameSite=Lax session cookie isn't sent, and a session started
   there would overwrite the user's cookie. So `POST login/apple/callback` runs **without the web
   middleware group** (no session, no CSRF; also listed in the CSRF `except`), stashes
   `{code, state, error, name}` in the cache under a random one-time key and 303-redirects to
   `GET login/apple/finish?key=…` (guest, session) which checks the state and runs the shared
   account rules (link by `apple_id`, else by verified email, else sign up when allowed).
   The name comes only in the first callback's `user` form field.
5. `SignInMethods::apple()` → `sign_in.apple` in `GET /api/v1/instance` (+ OpenAPI regen),
   `canLoginWithApple` on Login + Welcome: black / white "Sign in with Apple" button.
6. Tests `tests/Feature/Auth/AppleLoginTest.php` (Http::fake token endpoint + JWKS with a test
   RSA key, real ES256 client secret), InstanceTest, CSRF exclusion only for the callback.
7. Docs: docs-site `integrations/apple.md`, `self-hosting/configuration.md`, integrations index,
   ARCHITECTURE / API / KNOWLEDGE.

## Decisions
- **Own client, not `socialiteproviders/apple`**: same shape as Sign in with Microsoft, no new
  package family, explicit id_token verification.
- **Cache stash + GET finish** instead of `SESSION_SAME_SITE=none`: keeps the session cookie Lax.
- No token revocation on account deletion yet (Apple's revoke endpoint needs a stored refresh
  token; we don't keep Apple tokens at all).
