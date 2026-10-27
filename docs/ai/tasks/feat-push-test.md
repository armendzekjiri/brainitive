# Web push devices + test notification (BRAI-154)

**Branch:** feat/push-test (from `release/v1.44.0`)
**Status:** done (merged into release/v1.44.0)
**Started:** 2026-10-26
**Board card:** BRAI-154 (item 330)

## Problem / goal
"I get the notification on this MacBook but not on the PC for the same account — I enabled
everything in Profile." Web push gave no way to see which browsers are registered or why one of
them stays silent. Goal: make push diagnosable from Profile → Notifications — list the registered
devices, send a test push to one of them and show what the push service answered, with plain next
steps per platform.

## What the user gets
Profile → Notifications, below the push toggle:
- **This device** row: "on for this device" + **Send test notification** + Turn off; "This device
  isn't registered" + **Register this device** when the browser has permission but the server has
  no live subscription for it (expired, removed from another device, never saved); "off" + Turn on;
  blocked → how to allow it in the site settings (no button: only the site settings can lift it).
- **Your devices**: one row per subscription — "Chrome on Windows", push service ("Chrome/Edge
  (Google push)", "Firefox", "Safari / iPhone", "Edge (Windows push)"), registered date, last
  delivered, last error, *This device* / *Expired* badges, **Send test**, **Remove** (removing this
  device also unsubscribes the browser).
- The test result appears under the row: *delivered* (accepted by the push service — if nothing
  shows up, platform steps: Windows → Chrome running / "Continue running background apps", Windows
  notification settings, Focus; macOS → System Settings → Notifications; iPhone → Home Screen app
  only, iOS 16.4+; Android → app notification settings; all → site permission), *expired* (410 /
  404: re-register there, remove the entry), *rejected* (status + the push service's reason, e.g.
  VAPID keys changed → re-register), *network*, *unconfigured* (no VAPID keys), *invalid* (keys
  can't encrypt).
- Help line: "Install Brainitive as an app to see its icon on notifications".

## Decisions
- **Expired subscriptions are flagged, not deleted** (`push_subscriptions.expired_at`). The package's
  `ReportHandler` deletes on 404 / 410, so a device silently vanished. Our
  `App\Support\Push\PushReportHandler` (bound for `WebPushChannel` in `AppServiceProvider`) records
  every answer instead: success → `last_success_at`; failure → `last_error` (one line, ≤ 255, no
  endpoint / URL) + `last_error_at`; 404 / 410 → `expired_at`. `User::routeNotificationForWebPush()`
  skips flagged rows; `model:prune` removes them after 30 days (`routes/console.php`, 04:40).
  Re-registering the same endpoint clears the flag; "Register this device" subscribes **fresh**
  (drops the browser's old subscription first) because re-sending a dead endpoint doesn't revive it.
- **One WebPush client** (`App\Support\Push\WebPushClient::make()`, mirrors the package's
  `webPushAuth()`) for real notifications and the test push. Same VAPID, padding, Guzzle options.
- **The test push is synchronous** (`PushTester`, not queued): one subscription, same message
  shape (`SendsWebPush::webPush()`, tag `brainitive-test`, opens `/profile`).
- **Endpoints never reach the page**: rows carry `endpoint_hash` (SHA-256); `lib/push.ts`
  `endpointHash()` hashes this browser's endpoint with `crypto.subtle` to find "This device".
- **Device label from the User-Agent at subscribe time** (`DeviceLabel`, stored truncated:
  `user_agent` 255, `device_label` 100). Old rows have no UA → "Unknown browser".
- Ids are resolved through `$user->pushSubscriptions()` → another user's id is a 404.
  Test push throttled `throttle:10,1,push-subscriptions.test`.
- Notification icon → `/android-chrome-192x192.png` (192 px; `apple-touch-icon.png` is 180 px).
  Badge stays `/favicon-32x32.png` (exists; Android draws badges as a mask — a dedicated
  monochrome 96 px badge would look better, follow-up).
- `lib/push.ts` `getRegistration()` gives up after 10 s: `serviceWorker.ready` never resolves when
  registration failed (private windows), which used to hang the settings.

## Changes
- Migration `2026_11_02_120000_add_device_columns_to_push_subscriptions.php` (nullable `user_agent`,
  `device_label`, `last_used_at`, `last_success_at`, `last_error`, `last_error_at`, `expired_at` + index)
- `app/Models/PushSubscription.php` (extends the package model, Prunable; `config/webpush.php` `model`)
- `app/Support/Push/`: `WebPushClient`, `PushOutcome`, `PushReportHandler`, `PushTester`, `DeviceLabel`,
  `FakePushService` (e2e)
- `PushSubscriptionController`: `store` captures UA; new `index`, `remove`, `test`
  (`push-subscriptions.index|remove|test`)
- `User::routeNotificationForWebPush()`; `AppServiceProvider` contextual bindings for `WebPushChannel`
- Frontend: `lib/push.ts`, `Profile/Partials/NotificationSettingsForm.tsx`, new `Profile/Partials/PushDevices.tsx`,
  `types/index.d.ts` (`PushDevice`, `PushTestResult`, appended)
- `SendsWebPush` + `public/sw.js` (push handler default icon only)
- e2e: `e2e/env.ts` (test-only VAPID pair + `WEBPUSH_FAKE`), `e2e/tests/push-devices.spec.ts`

## Tests
- PHPUnit `tests/Feature/Notifications/PushDevicesTest.php` (12): UA capture + flag reset, own-only
  list without endpoints / keys, own-only remove, test push 201 / 410 / 400 + 403 JSON reason /
  connect error / no VAPID, owner-only + throttle, real notification pushes record success and flag
  404 (then skipped), prune after 30 days, labels.
- e2e `push-devices.spec.ts` (2): headless Chromium has no push service, so the page's
  `Notification.permission` and `PushManager` are stubbed with real P-256 keys; the server encrypts
  + signs for real and `FakePushService` answers by endpoint (`e2e-expired` → 410, `e2e-rejected` →
  403, else 201). Covers turn on, "This device", test results (delivered / expired / rejected), remove,
  re-register after removing this device and after the server lost the row, phone width.

## Notes for the lead (docs to update)
- **ARCHITECTURE** (Notifications): "Push subscriptions via `PushSubscriptionController`
  (`store` / `destroy` by endpoint; `index` / `remove` / `test` by id for Profile → Notifications
  device list, BRAI-154). Model `App\Models\PushSubscription` (`config('webpush.model')`). Sending:
  `App\Support\Push\WebPushClient` (one client, bound for `WebPushChannel`), answers recorded by
  `PushReportHandler` / `PushOutcome`, test push `PushTester`, labels `DeviceLabel`."
- **KNOWLEDGE** (web push): "Expired subscriptions (push service 404 / 410) are **flagged**
  (`expired_at`), not deleted — they stay in the device list with the reason, get no more pushes and
  are pruned after 30 days. A device that 'gets nothing' although the test says *delivered*: the push
  service accepted it, so the OS / browser hides it (Windows: Chrome not running / background apps
  off, Focus; macOS: Notifications settings; iPhone: only the Home Screen app, iOS 16.4+)." and
  "`ChannelManager` keeps the `WebPushChannel` (and its WebPush client) once built — tests that
  change `webpush.client_options` between notifications must queue all mock answers up front;
  queue workers need a restart after VAPID changes."
- **KNOWLEDGE** (e2e): "Push in e2e: the local server has a test-only VAPID pair and
  `WEBPUSH_FAKE` (`FakePushService`, APP_ENV=e2e only); headless Chromium reports notification
  permission `denied`, so specs stub `Notification` / `PushManager` (see `push-devices.spec.ts`)."
- **CONVENTIONS** (Notifications): "Push is sent only through `WebPushChannel` / `PushTester`
  (both use `WebPushClient`); never build a `Minishlink\WebPush\WebPush` elsewhere."

## Changelog line
- **Notification devices + test push** — Profile → Notifications lists every browser that gets your
  push notifications (browser, system, push service, last delivered, last error). Send a test
  notification to any of them and see what the push service answered, with the next steps for
  Windows, macOS, iPhone and Android; remove old devices or register this browser again.
