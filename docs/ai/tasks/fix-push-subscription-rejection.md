# Push: getSubscription() rejections no longer escape as uncaught errors

**Branch:** fix/push-subscription-rejection
**Status:** done
**Started:** 2026-10-27

## Problem / goal
The E2E nightly (40440477316) timed out on Firefox and WebKit. 194 journeys failed on the
page-error fixture with `Error retrieving push subscription.`. Since v1.44.0 the e2e app has a VAPID key
(`PUSH_E2E`), so `PushPrompt` runs on every app page and calls `isSubscribed()`. Firefox / WebKit
reject `pushManager.getSubscription()` when no push service is reachable (headless CI, some privacy
settings), and `isSubscribed()` didn't catch it. Real users in those browsers got the same unhandled
rejection.

## Plan
- `isSubscribed()` and `unsubscribeFromPush()` treat a rejected `getSubscription()` as "no
  subscription", like `currentEndpoint()` / `unsubscribeLocally()` already do. `subscribeToPush()`
  keeps throwing: its caller (Profile) shows the error.
