# Outlook / Microsoft 365 calendar provider

**Branch:** feat/calendar-outlook (from `release/v1.42.0`)
**Status:** done (not merged)
**Started:** 2026-10-26
**Board card:** BRAI-140 (board item 314)

## Goal
Connect Outlook.com and Microsoft 365 (work / school) calendars, on the provider layer of
`feat-calendar-providers.md` ("How to add a provider"). Read + write, per-calendar read-only.

## Approach
- `App\Support\Calendar\Providers\OutlookCalendarProvider` (key `outlook`, connect mode `oauth`),
  one line in `CalendarProviders::PROVIDERS`; `available()` only with client id + secret.
  Microsoft Graph over the Http client (no SDK): `/me/calendars` (canEdit → read_only),
  `/me/calendars/{id}/calendarView` (recurrences expanded, `@odata.nextLink` followed up to 20
  pages, only to graph.microsoft.com), get / create / PATCH / delete under
  `/me/calendars/{id}/events/{eventId}` (scoped to the connected calendar).
  `Prefer: outlook.timezone="UTC", outlook.body-content-type="text"`.
- Tokens: encrypted columns; refreshed 60 s before expiry (and once on a 401); rotated refresh
  tokens saved with `forceFill()->save()`. A refused refresh → `CalendarSyncFailed("Outlook access
  expired. Reconnect Outlook.")`, the connection stays (no deactivation).
- OAuth: `OutlookCalendarConnectController` — authorization code + PKCE (S256), `state` and the
  verifier in the session (one-time), tenant from config (default `common`). The callback reads
  `/me`, lists the calendars and hands over to the shared picker (`PendingConnection`).
- Errors: 429 / 503 → friendly `CalendarSyncFailed` (one short retry when Retry-After ≤ 2 s);
  logs carry connection id + status + Graph error code only.

## Production setup (Microsoft Entra)
1. <https://entra.microsoft.com> → **Identity → Applications → App registrations → New registration**.
   - Name: `Brainitive`.
   - Supported account types: **Accounts in any organizational directory (Any Microsoft Entra ID
     tenant - Multitenant) and personal Microsoft accounts (e.g. Skype, Xbox)**.
   - Redirect URI: platform **Web**, `<APP_URL>/calendar/connections/outlook/callback`
     (production: `https://brainitive.app/calendar/connections/outlook/callback`; add the dev
     server's URL as a second Web redirect URI under **Authentication** if dev uses the same app).
   - Register. Copy **Application (client) ID** from Overview.
2. **Certificates & secrets → Client secrets → New client secret** (e.g. 24 months). Copy the
   **Value** (not the Secret ID) right away; put a reminder in the calendar before it expires.
3. **API permissions → Add a permission → Microsoft Graph → Delegated permissions**:
   `offline_access`, `User.Read`, `Calendars.ReadWrite` → Add permissions. (No admin consent
   needed for these; a company tenant may still require its admin to approve the app.)
4. `.env` on the server, then `php artisan config:cache`:
   ```
   MICROSOFT_CLIENT_ID=<Application (client) ID>
   MICROSOFT_CLIENT_SECRET=<client secret Value>
   # optional: MICROSOFT_TENANT=common   (default; a tenant id limits sign-in to one organisation)
   # optional: MICROSOFT_REDIRECT_URI=   (default: APP_URL + /calendar/connections/outlook/callback)
   ```
   With both values set, "Add calendar" lists **Outlook / Microsoft 365**.

## Test plan / results
- PHPUnit (`TEST_DB_SUFFIX=outlook`): `OutlookCalendarProviderTest` 16 passed (140 assertions);
  `tests/Feature/Calendar` + `tests/Feature/Mcp` + `RouteThrottleTest` + CalendarDeletedBoard /
  CalendarViewPreference → 87 passed (932 assertions).
- e2e (`E2E_PORT=8037`): `calendar-outlook.spec.ts` 1 passed (menu entry, authorize redirect
  with state + PKCE read without following it, wrong state + cancel refused) and
  `calendar-providers.spec.ts` 3 passed. Microsoft is never called (fake client id in `e2e/env.ts`).
- pint, `npx tsc --noEmit`, `npm run build`: green.

## Notes
- Shared-layer touch: `routes/web.php` `manual/{provider}` throttle now has its own counter
  (`throttle:10,1,calendar.connections.manual`) — `RouteThrottleTest` failed on it since the
  provider-layer merge.
- A refused refresh does not deactivate the connection (unlike Google): every page load retries
  the refresh once and shows "Outlook access expired. Reconnect Outlook." until the user reconnects
  (the picker `store` copies the new tokens onto all rows of the account).
- Recurring series: calendarView instances each have their own id; editing one changes that
  occurrence only.
