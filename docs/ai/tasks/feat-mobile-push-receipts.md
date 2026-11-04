# Mobile push: Expo push receipts + "Send a test notification"

**Branch:** feat/mobile-push-receipts (from release/v1.50.0)
**Status:** done
**Started:** 2026-11-04

## Problem / goal
1. Expo's send answer (the *ticket*) only says the message was accepted. Whether APNs / FCM delivered it — and
   above all `DeviceNotRegistered` for an uninstalled app — comes in the **push receipt**, fetched later from
   `POST https://exp.host/--/api/v2/push/getReceipts`. Without it, dead tokens keep being pushed to.
2. The mobile app's settings need "Send a test notification to this device" to check the whole chain.

## Approach
- **Receipts.** `SendMobilePush` keeps the ids of the `ok` tickets with the device id and a SHA-256 of the
  token sent (never the token) and dispatches `CheckMobilePushReceipts` with a delay
  (`app-api.push.receipt_delay`, 900 s — Expo: receipts are ready after ~15 min, kept 24 h). That job POSTs
  `{ids}` (≤ 1000, our batches are ≤ 100) to `app-api.push.receipts_url` (`EXPO_RECEIPTS_URL`, default Expo's)
  with the same headers / access token as the send. Receipt `DeviceNotRegistered` → that device's
  `push_token` cleared, only while its hash still matches (a re-registered token wins); other receipt errors
  → logged with device id + Expo's code, message redacted. 429 / 5xx / network → released 1 / 5 / 15 min
  (sync: one attempt). Missing ids (not ready / expired) are ignored.
- **Sync queue:** no delay exists there (the job would run at once and find no receipts), so receipts are
  only checked on a real queue; tickets still catch most `DeviceNotRegistered`. Documented.
- **Test push.** `POST /api/v1/devices/current/test-push` (`auth:app`, `throttle:3,1`): the calling device
  must be `client: mobile` with an Expo push token → 202 `{data: {status: "queued"}}` and a `SendMobilePush`
  for that device only (title "Test notification", no url / notification id; after the response on a sync
  queue). No token → 409 `no_push_token`; instance push off → 409 `push_disabled`. TV: not allowed (not in its
  abilities list → 403 `client_not_allowed`).

## Changes
- `config/app-api.php` → `push.receipts_url`, `push.receipt_delay`.
- `App\Jobs\SendMobilePush`: shared `expoRequest()`, receipts dispatch.
- `App\Jobs\CheckMobilePushReceipts` (new).
- `DeviceController::testPush`, route `devices.current.test-push`.
- `docs/ai/API.md` (Devices table + overview, push section), `docs/api/openapi.json` regenerated,
  ARCHITECTURE (push section), KNOWLEDGE (sync queue / receipts).

## Test plan
- `tests/Feature/Notifications/MobilePushReceiptsTest.php`: receipts job scheduled with the ok ticket ids and
  no token in the payload, delay 900; `DeviceNotRegistered` receipt clears the token (not when re-registered);
  other errors logged without token; retry on 5xx; nothing scheduled on the sync queue.
- `tests/Feature/AppApi/DevicesTest.php`: test-push 202 + job for the calling device; 409 `no_push_token`,
  409 `push_disabled`; throttle 3/min; TV refused.
- `OpenApiSpecTest`, `AppClientAbilitiesTest`, `MobilePushTest` stay green.

## Mobile card text (`## API (ready)`, card on the Mobile app board — to paste when the MCP is connected)
```
## API (ready)
- POST /api/v1/devices/current/test-push — sends a test push to the calling device → 202 {data: {status: "queued"}}; 409 `no_push_token` (register a push token first with PATCH /devices/current) or `push_disabled` (the server turned push off); 429 after 3 per minute
Scope: app · OpenAPI: docs/api/openapi.json#/Devices · Registry: docs/ai/API.md#devices
Built in: feat/mobile-push-receipts · Mobile scope: Full
```

## Open questions
- Board card not created / updated (Brainitive MCP not connected in this session).
