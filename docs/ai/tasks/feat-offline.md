# Offline notes and tasks: opt-in local drafts with explicit sync conflicts (BRAI-82)

**Branch:** feat/offline (into release/v1.43.0)
**Status:** done
**Started:** 2026-10-26
**Board card:** BRAI-82 (board item 184)

## Problem / goal
Tasks, quick capture, My Day and issue edits should keep working without internet: saved on the
device, sent when back online, and every conflict shown explicitly ("someone changed this
meanwhile: keep mine / keep theirs / keep both"). Never a silent overwrite.

Documents are **not** part of this: live co-editing (Yjs + y-indexeddb, its own
`brainitive-docs-*` IndexedDB) is built on its own branch. The service worker never intercepts
document requests (see "Service worker" below).

## How it works for the user
1. Profile → **Offline** → "Work offline on this device" (off by default). The card says what is
   stored, shows "Ready to work offline on this device" once the app is cached, and has
   **Clear offline data**.
2. While online, the device keeps a copy of what the user opens: today's My Day (My Work), the
   last 20 task lists, the last 50 issues (full page or the board popup).
3. Offline, any address opens the offline app: My Day (complete / reopen / remove), a task list
   (add, complete / reopen, rename, move up / down, add to My Day), an issue (title, status,
   priority, assignee, due date, comments; description read-only), quick capture (also the
   regular **C** dialog on any page). Anything else shows "This page isn't available offline"
   with links to what works. Rows changed on the device carry **Not synced yet**.
4. Top bar: **Offline · 3 changes waiting to sync** / **3 changes waiting to sync** /
   **1 sync conflict**; quiet cloud icon when everything is synced. Toast "3 changes synced".
5. A conflict opens the **Sync conflicts** panel: per field *Mine* vs *Theirs* with
   **Keep mine** / **Keep theirs** (+ **Keep both** for issue titles: theirs stays, mine is
   posted as a comment). Deleted meanwhile → **Recreate** / **Discard my change**. Refused
   (403 / 422) → the reason + **Discard my change**.
6. Sign-out (with a confirm when changes are still waiting), turning the setting off, or another
   account signing in on the device deletes the account's IndexedDB and the cached shell.

## Decisions
- **Opt-in per device, no server column.** `localStorage` flag per user id; the server never
  needs to know. Data lives in IndexedDB `brainitive-offline-<userId>` (stores `snapshots`,
  `outbox`, `conflicts`), no dependency (small promise wrapper in `lib/offline/db.ts`).
- **The shell is a session-free page.** `GET /offline` (`offline.shell`, `throttle:60,1`) runs
  without `EncryptCookies` (so not even a "remember me" cookie signs anyone in) /
  `StartSession` / `ShareErrorsFromSession` / `ValidateCsrfToken` and calls
  `Inertia::flushShared()`, so the cached HTML has no account, workspaces or counters. The page
  knows the account from `localStorage` (`brainitive-offline:user`). Cached by the page itself
  (Cache API `brainitive-offline-shell-v1`) together with the closure of `app.tsx` +
  `Pages/Offline/Shell.tsx` over `public/build/manifest.json` (~1 MB, ~40 files); rebuilt when
  the manifest changes (checked once per page load) into a staging cache that replaces the
  working one only when complete.
- **The URL survives the shell.** Inertia rewrites the address to the page's own (`/offline`);
  `lib/offline/bootLocation.ts` (imported first in `app.tsx`) captures it before Inertia boots
  and drops the history entry's Inertia state on the shell, so Back / Forward render the shell
  instead of an online page whose chunks / props aren't on the device.
- **Service worker** (`public/sw.js`): only GET, same origin. Failed navigation → cached shell
  (or a static "you're offline" page when offline mode is off). Failed *full* Inertia visit →
  `409 + X-Inertia-Location` (Inertia does a hard navigation → shell); partial reloads, polls and
  prefetches are left alone. `/build/assets/*` → shell cache first, else network. Everything else
  (documents, realtime, JSON, POST/PUT) gets no `respondWith`, i.e. behaves as without a worker.
- **One mutation per request, in order.** `POST /offline/sync` (`offline.sync`,
  `ReadOnlySession` — a burst after reconnecting must not wipe a form's flash —,
  `throttle:240,1,offline.sync`) with `{id: uuid, type, target: {id | ref}, base: {updated_at,
  fields}, data, user_id}`. `user_id` ≠ the session's user → 401 `wrong_account`, never applied
  (an outbox is never sent as another account). Captures keep their own 60 / min limit. Types: `list_task.create|update|reorder`, `issue.update|comment|create`,
  `capture.create`, `my_day.add|remove`.
- **Idempotent per (user, uuid)** in `offline_mutations`: the row is inserted first inside the
  transaction, so a concurrent retry waits on the unique index and then replays the stored
  answer; the same uuid with a different payload → 422. Only final outcomes are stored
  (200 applied, 409 conflict); 410 / 424 / 403 / 422 wrote nothing. Pruned after 30 days.
- **Field-level three-way check** (`FieldMerge`): for each field the device changed, write only
  if the server value still equals the base the device saw (or already equals mine), otherwise
  report `{base, mine, theirs}` → 409. Non-conflicting fields of the same mutation are applied
  (`applied` in the 409). Fields the device didn't touch are never written. `updated_at` is
  carried for information; the check is per field so two offline edits of different fields never
  conflict with each other. "Keep mine" = a new mutation made against *their* value — the server
  re-checks it, so a third edit meanwhile is a new conflict, never an overwrite.
- **Offline creates are referenced by uuid** (`target.ref`, `work_ref`, reorder `items[].ref`),
  resolved only against the same user's `offline_mutations`. Not there yet → 424 (the device
  retries later); created but failed → 410.
- **Same policies as the app**: `TaskListPolicy::manageItems`, `ItemPolicy::update`
  (`view` for My Day), `BoardPolicy::createItems`, captures owner-only. Something deleted *or no
  longer visible* answers 410 either way, so ids can't be probed. Validation mirrors the
  controllers (columns scoped to the board, priorities to the project, assignees
  `ProjectMember`). A trashed board / list / project / workspace is checked before any policy
  (the policies type-hint non-null parents) and answers 410. History / notifications come from
  the observers, `ItemComments::add` and the watcher loop of `moveItem` (here
  `DB::afterCommit`, so a rolled-back or replayed sync tells nobody twice); a column move lands at
  the end of the column.
- **Outbox coalescing** (client): edits of something not yet sent are folded in (rename of an
  offline-created task → part of the create; two renames → one with the oldest base; a newer
  reorder replaces an unsent one at the end of the line). Never into a change that was already
  sent: `enqueue` and the engine's `takeNext` share one lock (Web Locks, so across tabs too).
  "Keep mine" folds in as a *rebase*: a newer pending edit of the field wins, based on theirs.
- **Writes count when committed**: IndexedDB writes resolve on `transaction.oncomplete`;
  actions queue the change before touching the local copy and toast when storage fails.
- **Other tabs**: every read / write checks the device-wide owner (`brainitive-offline:user`)
  and a `storage` listener drops the state, so a tab that missed a sign-out never recreates the
  deleted database.
- **Sync triggers**: `online`, window focus / visible, every 30 s, right after a change when
  online. 401 / 419 → waits for a sign-in; network / 429 / 5xx → retry later.
- **Expired session ≠ sign-out.** Guest pages keep the data (the same person signs in and it
  syncs); an explicit sign-out (Inertia `before` on `POST /logout`) or another account signing in
  clears it. Deleting the account (`DELETE profile.destroy`) clears it too.

## Limits
- Documents, whiteboards, plans, boards (the kanban view itself), search: online only.
- Issue description is read-only offline (rich text); comments are plain text.
- Only things opened online are available offline (bounded: My Day, 20 lists, 50 issues).
- My Day adds land on the user's "today" at sync time (not the day they were made).
- Reorder has no conflict check (order isn't content; missing tasks are skipped).
- No JS unit runner in the project: the client is covered by `e2e/tests/offline.spec.ts`.
- Safari evicts IndexedDB after ~7 days without a visit; Firefox < 126 can't list databases (the
  account list in `localStorage` covers clearing there).

## Changes
- `ProjectIssues::create` accepts an optional `priority_id` per issue (recreate writes it in the
  insert, no extra history entry).
- Migration `2026_11_01_120000_create_offline_mutations_table`; model `OfflineMutation`
  (Prunable, 30 days, scheduled `model:prune` 04:35).
- `app/Support/Offline/` — `OfflineSync` (dispatcher, idempotency, `TYPES`), `MutationContext`
  (target / ref resolution, base check), `FieldMerge`, `SyncOutcome`, `SyncAbort`, appliers
  `Appliers/{ListTask,Issue,MyDay,Capture}Mutations`.
- `OfflineSyncController` (`shell`, `store`); routes `offline.shell`, `offline.sync`.
- Frontend: `lib/offline/` (`db`, `settings`, `shell`, `store`, `sync`, `session`, `actions`,
  `resolve`, `snapshots`, `bootLocation`), `components/offline/` (`OfflineIndicator`,
  `ConflictsPanel`, `OfflineSettingCard`, views for list / issue / My Day / capture,
  `NotSyncedBadge`, `useSnapshot`), `Pages/Offline/Shell.tsx`; snapshot hooks in
  `Pages/Lists/Show`, `Pages/MyWork/Index`, `components/item/ItemDetail` (`useItemDetail`);
  `QuickCaptureDialog` queues while offline; indicator in `AppLayout`'s top bar; card in
  `Profile/Edit`; types appended to `types/index.d.ts`.
- `public/sw.js` rewritten (push handlers unchanged).

## Tests
- PHPUnit `tests/Feature/Offline/OfflineSyncTest.php` (35): shell public + no account data,
  guests, idempotent replay, reused id, ids per user, rename / conflict / keep-mine flow, partial
  apply, same change twice, recurring completion, base required, deleted → 410 not stored,
  policies (viewer 403, outsider 410), reorder with refs, out-of-order ref → 424, refs per user,
  issue fields + history, status conflict, date normalisation, issue validation, deleted issue +
  recreate, escaped comments once, captures, My Day add / remove / by ref, pruning; review
  follow-ups: remember-me cookie on the shell, wrong account, capture limit, trashed project /
  workspace → 410 for every type, watcher notification once after commit, recreate / reorder
  policies and scoping, priority written in the insert.
- e2e `e2e/tests/offline.spec.ts` (5): task + capture + issue edit offline → synced; conflict
  from a second session → keep mine / keep theirs; My Day completion offline; unavailable page →
  offline home; sign-out deletes IndexedDB + shell cache.

## Notes for the lead (docs to update on the release branch)
- **ARCHITECTURE** — new module row "Offline mode (BRAI-82)": `OfflineSyncController`
  (`offline.shell` public session-free, `offline.sync`), `app/Support/Offline/*`,
  `OfflineMutation` (`offline_mutations`), `public/sw.js`, `resources/js/lib/offline/*`,
  `components/offline/*`, `Pages/Offline/Shell.tsx`; snapshot hooks in Lists/Show, MyWork/Index,
  `useItemDetail`; tests `tests/Feature/Offline/`, e2e `offline.spec.ts`.
- **KNOWLEDGE** — "Offline mode: field-level conflicts, idempotency, session-free shell":
  - never add a cached page that renders with the session or shared props; the shell is
    `withoutMiddleware(StartSession…)` + `Inertia::flushShared()`;
  - the service worker only `respondWith`s GET navigations, full Inertia visits and
    `/build/assets`; a new feature that needs caching adds its own branch there, documents stay
    network-only;
  - a new offline mutation type = an applier method + a line in `OfflineSync::TYPES` + an action
    in `lib/offline/actions.ts` (+ `applyLocally` in `sync.ts`), always with base values per field;
  - appliers load the whole parent chain (`board.project.workspace`, `taskList.project.workspace`)
    and answer 410 when a link is missing: policies type-hint non-null `Project` / `Workspace`;
  - `validated()` regroups wildcard keys (`items.*.id` / `items.*.ref` came back out of order):
    `ksort` before relying on the index.
- **CONVENTIONS** — "Writes from the offline outbox go through `OfflineSync`, with the same policy
  as the online endpoint; conflicts are reported per field, never resolved on the server."

## Changelog line
Offline mode (opt-in per device): My Day, task lists, issues and quick capture keep working
without internet, sync when you're back, and anything someone else changed meanwhile asks you —
keep mine, keep theirs or keep both.
