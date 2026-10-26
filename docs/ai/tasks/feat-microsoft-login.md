# Sign in with Microsoft (BRAI-148, board item 322) — release v1.42.0

Branch `feat/microsoft-login` (from `release/v1.42.0`).

## Goal
"we need to login with microsoft, im creating now the app in microsoft so when we working here then
just lets do all together calendar and login and teams". "Continue with Microsoft" next to Google
and GitHub, for personal Microsoft accounts (Outlook.com / Hotmail / Live) and work or school
accounts (Microsoft 365 / Entra ID). ONE Entra app registration serves login, the Outlook calendar
provider (BRAI-140) and Teams meetings (feat/meetings).

## Plan
1. Migration `2026_10_31_200000_add_microsoft_id_to_users_table`: `users.microsoft_id` string,
   nullable, unique. User `$fillable` gains it; Admin users list `sign_in_methods` gains `microsoft`.
2. `config('services.microsoft.login_redirect')` (env `MICROSOFT_LOGIN_REDIRECT_URI`, default
   `APP_URL/login/microsoft/callback`). The calendar `redirect` key is unchanged.
3. `App\Support\Auth\MicrosoftLogin` — the small OAuth / OIDC client (no new dependency; Socialite
   has no Microsoft driver in core and the community provider isn't installed): authorize URL
   (state + nonce + PKCE S256, `openid profile email User.Read`, `prompt=select_account`), code
   exchange at `login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, id_token claim checks.
   OutlookCalendarProvider is NOT touched (another agent works on it): the tenant / token endpoint
   bits are duplicated (a dozen lines).
4. `App\Http\Controllers\Auth\MicrosoftAuthController` redirect / callback, guest routes
   `login.microsoft` / `login.microsoft.callback`, named throttles.
5. `canLoginWithMicrosoft` (client id AND secret set) on Login + Welcome; "Continue with Microsoft"
   button (four-square logo, `aria-hidden`, text label) under Google / GitHub. Register has no
   social buttons today, so none is added there.
6. Tests: `tests/Feature/Auth/MicrosoftLoginTest.php` (Http::fake), e2e
   `e2e/tests/microsoft-login.spec.ts`.

## Decisions

### The account key: `microsoft_id = "{tid}:{oid}"` from the id_token
- `oid` is the user's immutable object id inside a tenant; `tid` the tenant (personal accounts
  all share the consumer tenant `9188040d-6c67-4c5b-b112-36a304b66dad`). Microsoft documents the
  `tid` + `oid` pair as the key to identify a user across apps; it is stable for personal and work
  accounts, and including `tid` means two tenants can never collide.
- Not `sub` (pairwise per app — fine, but changes if the app registration is ever replaced), not
  Graph `/me` id (a different format for personal accounts — a CID, not the oid), never the email
  or `preferred_username` (mutable, reassignable).

### Which email is trusted (account takeover must be impossible)
The email is only used to LINK an existing Brainitive account or to CREATE one (then it is marked
verified). That makes it security-critical ("nOAuth": in Entra ID a tenant admin can put ANY
address in a user's `mail` attribute, so an attacker's own tenant can claim `victim@corp.com`).
- Never trusted: Graph `mail`, `userPrincipalName`, `preferred_username`, or an id_token `email`
  of a work / school account on its own.
- Trusted only from the **id_token `email` claim** AND:
  - personal Microsoft account (`tid` = consumer tenant): Microsoft verifies the sign-in address of
    a personal account (outlook.com / hotmail.com addresses are Microsoft's; other addresses are
    verified with a code at sign-up), or
  - work / school account with **`xms_edov` = true** ("email domain owner verified", an optional
    ID-token claim that has to be switched on in the app registration — see setup below),
  - and never for a B2B guest (`acct` = 1, optional claim): a guest's address is managed by another
    directory.
- Without a trusted email: an account already linked by `microsoft_id` signs in normally; anything
  else (link or sign-up) is refused with "We couldn't confirm the email address of this Microsoft
  account…". This mirrors Google, which refuses when `email_verified` is false.
- Emails are lower-cased (registration only stores lower-case addresses) and matched with
  `lower(email)` (older Google / GitHub sign-ups may have stored mixed case).
- Scopes `openid profile email User.Read`: Graph is not called; `User.Read` is the default
  "sign in and read your profile" permission every Entra app has, and the calendar asks for it
  too, so one consent covers both.
- Errors: `MicrosoftLogin` throws `MicrosoftLoginFailed` (user-facing message); the controller
  catches only that, so no HTTP-client exception text reaches the login page. Logs carry the
  status + Microsoft's short error code only.

### Same rules as Google / GitHub
- Already linked → sign in. Matching (lower-case) email → link `microsoft_id`; a never-verified
  account on an instance that requires verification is reset first (`SignOutEverywhere`, new random
  password: the squatter is locked out), then `email_verified_at` is set; pending invitations are
  accepted. No match → `WorkspaceInvitation::canSignUp` (registration open, or a valid invitation)
  or "Sign-up is by invitation only."
- Suspended users are refused before `Auth::login` with the same message the password login shows
  (Google / GitHub rely on `EnsureUserIsNotSuspended` on the next request).
- `Auth::login($user, true)` (migrates the session id), `redirect()->intended(dashboard)`.

### id_token validation
The id_token comes straight from the token endpoint over TLS, authenticated with the client secret
and bound to the PKCE verifier, so (OIDC Core 3.1.3.7 §6) TLS stands in for the signature check;
the claims are still checked: `aud` = client id, `iss` = `https://login.microsoftonline.com/{tid}/v2.0`,
`exp` not passed (60 s skew), `nonce` = the one stored in the session, `tid` / `oid` GUIDs, and
when `MICROSOFT_TENANT` is a tenant GUID, `tid` must equal it.

### Flow state
`state` (40 chars), `nonce` (40) and the PKCE verifier (96) live in the session under
`auth_microsoft_oauth` and are pulled (one use) on the callback; a missing / different state is
refused before any request to Microsoft.

## Entra (Azure) app registration — one app for login, calendar and Teams

Portal: entra.microsoft.com → Identity → Applications → App registrations → New registration.

1. **Name**: Brainitive. **Supported account types**: "Accounts in any organizational directory
   (Any Microsoft Entra ID tenant - Multitenant) and personal Microsoft accounts (e.g. Skype,
   Xbox)". (`MICROSOFT_TENANT=common`.)
2. **Redirect URIs** (platform **Web**, not SPA):
   - `https://brainitive.app/login/microsoft/callback`
   - `https://brainitive.app/calendar/connections/outlook/callback`
   - `https://dev.brainitive.app/login/microsoft/callback`
   - `https://dev.brainitive.app/calendar/connections/outlook/callback`
   - local (optional): `http://localhost:8000/login/microsoft/callback`,
     `http://localhost:8000/calendar/connections/outlook/callback` (Entra allows http only for
     localhost).
   Front-channel logout URL, implicit grant / hybrid flows: leave empty / unticked.
3. **Certificates & secrets** → New client secret (e.g. 24 months) → copy the **Value** (not the
   Secret ID) into `MICROSOFT_CLIENT_SECRET`. Put a reminder before it expires.
4. **API permissions** → Microsoft Graph → **Delegated**: `openid`, `profile`, `email`,
   `offline_access`, `User.Read`, `Calendars.ReadWrite`. No admin consent needed for these (each
   user consents; a work tenant may require admin consent by its own policy).
   - Teams meetings (feat/meetings) are created as calendar events with
     `isOnlineMeeting: true, onlineMeetingProvider: teamsForBusiness` → covered by
     `Calendars.ReadWrite`; nothing else (no `OnlineMeetings.ReadWrite`). Personal accounts
     usually can't create Teams-for-business meetings ("Teams isn't available for this account").
5. **Token configuration** → Add optional claim → Token type **ID** → tick `email`, `xms_edov`
   and `acct` (accept "Turn on the Microsoft Graph email permission" if asked). Without
   `xms_edov`, work / school accounts can still sign in once linked but cannot link or sign up
   by email (see "Which email is trusted"). Personal accounts work without it.
6. **Branding & properties** (optional): logo, home page `https://brainitive.app`, terms /
   privacy URLs; publisher verification (Microsoft Partner Network id) removes the "unverified"
   label on the consent screen for work accounts.
7. **Overview** → copy **Application (client) ID** → `MICROSOFT_CLIENT_ID`.

### .env
```
MICROSOFT_CLIENT_ID=<Application (client) ID>
MICROSOFT_CLIENT_SECRET=<client secret VALUE>
MICROSOFT_TENANT=common
# optional — the defaults are APP_URL + the paths below
MICROSOFT_REDIRECT_URI=https://brainitive.app/calendar/connections/outlook/callback
MICROSOFT_LOGIN_REDIRECT_URI=https://brainitive.app/login/microsoft/callback
```
Then `php artisan config:cache`. The button and the Outlook calendar entry appear only when both
client id and secret are set.

## Status
- [x] plan
- [x] migration, config, model, admin list, `.env.example`
- [x] MicrosoftLogin + controller + routes
- [x] Login / Welcome buttons, types
- [x] PHPUnit (`tests/Feature/Auth/MicrosoftLoginTest.php`) + e2e (`e2e/tests/microsoft-login.spec.ts`)
- [x] security review: no critical / high / medium; fixed the lows (case-insensitive lookup,
      dedicated exception, guests untrusted). Kept as-is (same as Google / GitHub): an unverified
      pre-registered account is only reset when the instance requires email verification.

## Notes for the lead (docs/ai not edited here)
- KNOWLEDGE "Accounts…": Microsoft needs the id_token `email` of a personal account or `xms_edov`
  (never Graph mail / UPN — nOAuth); key `{tid}:{oid}`.
- ARCHITECTURE auth: `App\Support\Auth\MicrosoftLogin` (OIDC client), `MicrosoftAuthController`,
  `config('services.microsoft.login_redirect')`.
- Changelog: "Sign in with Microsoft — personal Outlook.com / Hotmail and work or school
  Microsoft 365 accounts, next to Google and GitHub."
