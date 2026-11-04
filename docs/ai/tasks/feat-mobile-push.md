# Push delivery to the mobile app (Expo push service)

**Branch:** feat/mobile-push
**Status:** done
**Started:** 2026-11-04
**Board card:** BRAI-448

## Problem / goal
`PATCH /api/v1/devices/current` stores the mobile app's `push_token` (encrypted, `app_devices`), but
nothing sends to it. Notifications whose category has `push` on should also reach the user's phones,
exactly like browser push does today.

## Approach
- **Expo push service** (`https://exp.host/--/api/v2/push/send`): the app is Expo, Expo talks to APNs
  and FCM for us; tokens are `ExponentPushToken[…]`. Laravel's Http client, no new dependency.
- **Same place as web push:** `NotificationCatalog::channels()` adds `MobilePushChannel` next to
  `WebPushChannel` when the category's `push` is on (`NotificationCatalog::PUSH_CHANNELS`). Every
  class with `toWebPush()` gets it via `RespectsPreferences::channelsFor()`; the push-only / fixed
  `via()`s (calendar reminder, data export) keep both push channels. The mobile message is built from
  the same `toWebPush()` (title, body, url), so it never shows more than browser push does.
- **Never block the request:** the channel only looks up the eligible device ids (active, client
  `mobile`, has a token) and dispatches `SendMobilePush` jobs (≤ 100 devices each = Expo's batch
  limit). On a sync queue in a web request the job runs after the response; in the console inline.
  The job carries device ids only, never tokens.
- **Job:** loads the devices again, sends one request per job (timeout 10 s, connect 5 s); 429 / 5xx /
  network errors are released with backoff 30 s / 2 min / 10 min on a real queue (4 tries); on a sync
  queue one attempt, and it never throws. Tickets: `DeviceNotRegistered` → that device's
  `push_token` is cleared (only if it is still the token that was sent); other errors are logged with
  the device id and Expo's error code, the message with every push token redacted.
- Payload: `{to, title, body, sound: 'default', badge: <unread inbox count>, data: {url, notification_id}}`;
  `url` is the web path (`/workspaces/…`), `null` for a foreign host.

## Changes
- Config: `config/app-api.php` → `push` (`MOBILE_PUSH_ENABLED`, `EXPO_ACCESS_TOKEN`, `EXPO_PUSH_URL`).
- `App\Notifications\Channels\MobilePushChannel`, `App\Jobs\SendMobilePush`.
- `NotificationCatalog::channels()` / `PUSH_CHANNELS`, `RespectsPreferences`, `CalendarReminderNotification`,
  `DataExportReadyNotification`.
- Tests: `tests/Feature/Notifications/MobilePushTest.php`; channel lists in existing via() asserts.

## Test plan
Http::fake: sends for an opted-in category (payload + batching), skips an opted-out one, skips
revoked / desktop / tv / tokenless devices, clears the token on `DeviceNotRegistered`, never logs a
token, disabled flag sends nothing, 5xx doesn't throw on sync.

## Docs to update
API.md (Devices + Notifications: push delivered, `data` contract), ARCHITECTURE.md (notifications),
docs-site/self-hosting/configuration.md (env).

## Open questions
- Push receipts (`/push/getReceipts`) are not polled yet: a `DeviceNotRegistered` that only shows up in a
  receipt (minutes later) is missed until the next send's ticket says so.
- No mobile "Send test notification" yet (the web Profile test push is browser-only).
