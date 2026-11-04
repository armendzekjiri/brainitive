# Revoke the Apple authorization when an account is deleted

**Branch:** feat/apple-revoke-on-delete (from release/v1.50.0)
**Status:** done
**Started:** 2026-11-04

## Problem / goal
App Store Review Guideline 5.1.1(v): an app that offers account deletion and Sign in with Apple
must revoke the user's Apple tokens when the account is deleted (Apple's REST endpoint
`POST https://appleid.apple.com/auth/revoke`). Until now we kept nothing from Apple's token
response but the id_token's `sub`, so there was nothing to revoke.

## Approach
- **Store the refresh token at sign-in.** Apple's `authorization_code` exchange answers with a
  `refresh_token` (it doesn't expire until revoked). `AppleLogin::identity()` now returns it too;
  `AppleAuthController::finish` saves it on the user (`users.apple_refresh_token`, `encrypted`
  cast, `$hidden`) after the account rules passed — every sign-in overwrites it with the newest one.
  A refused / missing token keeps the stored one.
- **Revoke on deletion.** `UserDeletion::delete()` is the one place both deletion paths go through
  (Profile → Delete account, Admin → Users → Delete). It reads the token before the transaction
  and, after the user row is gone, dispatches `RevokeAppleToken` with it. No token (signed in before
  this change, or never with Apple) → nothing dispatched.
- **The job** (`ShouldBeEncrypted`: the token is in the payload — the user row is already gone, so
  the job can't load it): POST `client_id`, `client_secret` (the existing `AppleLogin::clientSecret()`
  ES256 JWT), `token`, `token_type_hint=refresh_token`. 200 = done. 429 / 5xx / network error →
  thrown, retried (`tries` 5, backoff 1 min / 5 min / 30 min / 2 h). 4xx (e.g. `invalid_grant`,
  already revoked) → logged (status + Apple's error code) and dropped. Apple not configured (keys
  removed) → logged and dropped. Never logs the token or the secret. A queue failure can't block
  the deletion: the dispatch runs after the delete committed and the job is async.
- Alternative rejected: revoking synchronously inside the request — a slow / down Apple would block
  or fail account deletion.

## Changes
- Migration `2026_11_13_100000_add_apple_refresh_token_to_users_table.php` (`text`, nullable).
- `User`: cast `encrypted`, hidden; not fillable (forceFill).
- `AppleLogin`: `REVOKE_URL`, `identity()` returns `refresh_token`.
- `AppleAuthController::finish`: stores it.
- `App\Jobs\RevokeAppleToken` (new); `UserDeletion::delete` dispatches it.
- `PersonalDataArchive`: unaffected (explicit column list, token not in it).

## Test plan
- `tests/Feature/Auth/AppleRevokeOnDeleteTest.php`: sign-in stores the refresh token encrypted
  (raw DB value ≠ token), self-deletion and admin deletion dispatch the job with the token,
  no token → no job, the job POSTs the right form (client secret verifies with the .p8 key),
  4xx is dropped without logging the token, 5xx throws for a retry, unconfigured → no request.
- Existing `AppleLoginTest`, `ProfileTest`, admin user tests stay green.

## Docs to update
- `docs-site/integrations/apple.md` (what's stored, revoke on deletion).
- `docs/ai/ARCHITECTURE.md` (job), `docs/ai/KNOWLEDGE.md` (why we keep the refresh token).

## Open questions
- Native Sign in with Apple in the mobile app (if added later) must also store its refresh token
  (exchange the authorization code server-side with the app's bundle id as `client_id`); the revoke
  must then use that same `client_id`.
