# E2E on dev.brainitive.app: v1.44.0 journeys that assume the local e2e app

**Branch:** fix/e2e-on-dev
**Status:** done
**Started:** 2026-10-27

## Problem / goal
The v1.44.0 pre-release run (40428593959) hit the 30-min limit on "E2E on dev.brainitive.app":
14 journeys failed, each burning 2 × 90 s. v1.43.0 was green on the same server. Causes:

- **Document autosave** (content, document-review): dev now runs a realtime server, so pages are
  live and save through `PUT …/pages/{uuid}/collab/draft`; `draftSaved` only matched `…/draft`.
- **analytics-consent.spec.ts** (new): relies on the `X-E2E-Analytics` opt-in, honoured only with
  `APP_ENV=e2e` — dev is `staging`, so the notice never appears.
- **legal.spec.ts** "no third-party loads" (new): counted every host but localhost as external, so
  dev's own assets failed it.
- **calendar-providers**: `getByRole('button', { name: 'Lists' })` also hit the sidebar's "Lists"
  section once the run had created task lists.
- **import-jira**: dev's shared data has a global "Improvement" item type, so the preview maps to
  it instead of offering "Create “Improvement”".

## Plan
- `draftSaved` accepts both endpoints.
- `test.skip(REMOTE, …)` for analytics-consent (same pattern as other local-only specs).
- legal: treat `BASE_URL`'s host as our own.
- calendar-providers: scope the filter buttons to the popover.
- import-jira: accept either mapping (both are correct "matched by name" behaviour).
