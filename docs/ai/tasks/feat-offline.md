# Offline notes and tasks: opt-in local drafts with explicit sync conflicts (BRAI-82)

**Branch:** feat/offline (into release/v1.43.0)
**Status:** in-progress
**Started:** 2026-10-26
**Board card:** BRAI-82 (board item 184)

## Problem / goal
Tasks, quick capture, My Day and issue edits should keep working without internet: saved on the
device, sent when back online, and every conflict shown explicitly ("someone changed this
meanwhile: keep mine / keep theirs / keep both"). Never a silent overwrite.

Documents are **not** part of this: live co-editing (Yjs + y-indexeddb, its own
`brainitive-docs-*` IndexedDB) is built on its own branch. Document routes stay network-only in
the service worker.

## Approach
- **Opt-in per device** ("Work offline on this device", Profile → Offline). Off by default; a
  `localStorage` flag per user id. Turning it off, "Clear offline data", signing out and a
  different user signing in delete the IndexedDB + the shell cache.
- **App shell** (`GET /offline`, public, no session data): an Inertia page `Offline/Shell` cached
  by the page (Cache API, `brainitive-offline-shell-v1`) together with the JS/CSS it needs
  (closure over Vite's `manifest.json`). `public/sw.js` answers a failed navigation with that
  shell and a failed Inertia GET with `409 + X-Inertia-Location` (→ full navigation → shell).
  Everything else goes straight to the network (no `respondWith`), so documents / realtime are
  untouched.
- **Local store** (IndexedDB `brainitive-offline-<userId>`, no dependency): `snapshots`
  (My Day, the last 20 task lists, the last 50 issues opened online), `outbox` (ordered
  mutations, each with a client uuid + the base values it was made against), `conflicts`.
- **Sync** (`POST /offline/sync`, one mutation per request, in order; on `online`, focus, every
  30 s): idempotent by `(user, mutation uuid)` in `offline_mutations` (pruned after 30 days).
  Conflict detection is a **field-level three-way check**: a field is written only when the
  server value still equals the base the device saw (or already equals mine); otherwise 409 with
  `{base, mine, theirs}` per field. Non-conflicting fields of the same mutation are applied.
  Deleted → 410 (offer "Recreate"). Mutations on something created offline reference it by the
  create's uuid (`target.ref`); a missing reference → 424 (retry later).
- **Same policies as the app**: `TaskListPolicy::manageItems`, `ItemPolicy::update`,
  `BoardPolicy::createItems`, My Day = `view`, captures = owner.

## Changes
- Migration `2026_11_01_120000_create_offline_mutations_table`
- Model `OfflineMutation` (Prunable 30 days)
- `app/Support/Offline/*` (sync dispatcher + appliers per entity), `OfflineSyncController`
- Routes `offline.shell` (public GET `/offline`), `offline.sync` (POST, auth, throttled)
- Frontend: `lib/offline/*` (db, settings, store, sync, shell cache), `components/offline/*`
  (top-bar indicator, conflicts panel, setting card), `Pages/Offline/Shell.tsx`, snapshot hooks
  in My Work / task list / issue pages, quick capture queues while offline.

## Test plan
- PHPUnit `tests/Feature/Offline/OfflineSyncTest.php`: policies, idempotency, conflicts per
  entity, deleted entities, refs / ordering, shell route.
- e2e `e2e/tests/offline.spec.ts`: offline create task + capture + issue edit → online synced;
  conflicting edit from a second session → conflict panel → keep mine / keep theirs; sign out
  clears local data.

## Open questions
- Description editing offline (rich text) — read-only offline for now.
