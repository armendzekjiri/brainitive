# Live boards + plans (BRAI-152)

**Branch:** feat/live-boards (from release/v1.44.0, on top of the live channel foundation)
**Status:** done (ready for the lead to merge into release/v1.44.0)
**Board card:** BRAI-152 (board item 328) · release v1.44.0 "Everything live"

## Goal
Card moves, new cards, edits and Gantt changes appear for everyone who has the board / plan open,
without a reload — on top of the live channel contract (`docs/ai/tasks/feat-live-foundation.md`).

## Approach

### Publishing — `App\Support\Live\BoardLive`
- **Hooks where every write path passes:** model events registered once from
  `LiveServiceProvider::boot()` (`BoardLive::register()`): `Item` created / updated / deleted /
  restored, `BoardColumn`, `Sprint`, `Board`, `Plan`, plus what a card shows besides its own columns
  (`Comment` / `Attachment` / `ItemLink` counts, `TimeEntry` → `time_spent`). UI, MCP tools,
  automations, offline sync, API, GitHub transitions, recurring issues all count.
- **Explicit touches** where a write bypasses model events: column reorder
  (`BoardLive::columnsReordered`), timeline item reorder, backlog rank (`backlog_position`),
  `Item::moveToSprint` subtree, `moveItemToBoard` subtree (`BoardLive::itemsLeft`), label pivots
  (issue dialog, bulk edit, automation "add label"), watcher pivots (`Item::addWatcher/removeWatcher`),
  custom field values (`ItemFieldValues::apply`).
- **Collected after commit** (`DB::afterCommit`, at once outside a transaction): a rolled-back
  change — delete, move away, board delete included — publishes nothing.
- **Coalesced per request / queued job / command** (deferred, `defer(..., 'live.board')->always()`,
  then `Live::flush()` directly — it runs among the deferred callbacks). Per board: one card → a
  precise event; several cards → one `items.changed` (≤ 100 ids per message). Several changes of one
  card in one request merge (created > moved > updated; deleted wins; delete + restore = restored).
- **Imports:** inside `ImportScope` nothing is collected per record; `ImportScope::run` calls
  `BoardLive::importFinished()` when the outermost scope ends → one `board.refreshed` per board and
  one `plan.updated` per plan the import touched.
- **No-op when publishing is off** (`LiveConfig::publishUrl() === null`): returns before any work, so
  tests / installs without live pay nothing.
- **Echo suppression:** the browser sends `X-Live-Client: <tab id>` (`lib/liveClientId.ts`, axios
  request interceptor, same-origin only); the server echoes it as `client_id` (validated
  `[A-Za-z0-9_-]{8,40}`, else omitted) together with `actor_id` (the signed-in user). A tab ignores
  an event only when both match — another member reusing a seen tab id can't hide changes from it.
- Cards touched by id only (comments, attachments, links, time entries) get their boards resolved
  with one query per flush (skipped during imports).

### Events (payloads pass `Live::KEY`; never content)
| channel | event | payload |
|---|---|---|
| `board.{id}` | `item.created` / `item.restored` | `id, column_id, client_id?` |
| | `item.updated` | `id, fields[] (attribute names), client_id?` |
| | `item.moved` | `id, column_id, from_column_id, position, fields[], client_id?` |
| | `item.deleted` | `id, client_id?` |
| | `item.moved_out` / `item.moved_in` | `id` / `id, column_id` (no ids of the other board) |
| | `items.changed` (2+ cards: bulk, subtrees, sprint moves, rank) | `ids[], deleted_ids[], count, fields[], client_id?` |
| | `column.created|updated|deleted`, `columns.reordered`, `columns.changed` | `id` (+`fields`) / `ids[]` |
| | `sprint.changed` | `id, action (created/updated/active/completed/…), status` |
| | `board.updated` / `board.deleted` / `board.refreshed` | `fields[]` / – / – |
| `item.{id}` | `item.updated` (the issue view's event; ≤ 10 cards per flush, not for created; title / description stay with Item's own live-basics publish, so no duplicates) | `id, fields[]` (+ `deleted` / `moved_out` / `restored`) |
| `plan.{id}` | `plan.updated` | `revision, fields[], client_id?` |
| | `plan.deleted` | – |
| | `issues.changed` (a linked issue changed a field a plan row shows — title, dates, status, assignee, type, priority, parent, board — or was created / deleted / moved; found via `content_references` plan → item, one query per flush) | `ids[]` |

Field names are pseudo-fields where no column changed: `labels`, `custom_fields`, `comments`,
`attachments`, `links`, `watchers`, `time_spent`.

### Refetch endpoint
`GET boards/{board}/cards?ids[]=` (`boards.cards`, `BoardController::cards`): `BoardPolicy::view`,
≤ 100 ids, the same cards and shape as `show()` (`withCardData`, cards in this board's columns,
Scrum boards: the running sprint only). An id that isn't returned left the board → the client drops
it. Eager loads only (query count independent of the number of cards — tested).

### Client — boards (`hooks/useLiveBoard.ts`)
- `useLiveBoard(boardProp, {cardsUrl, reloadProps, onGone, onBoardDeleted})` → `{board, status,
  setPaused}`. `Boards/Show.tsx` shadows its `board` prop with the returned one, so kanban, swimlanes,
  list, timeline and calendar all read the live columns; filters / search / saved views apply to them
  as before (a card that stops matching disappears, a newly matching one appears).
- Card events → ids collected, debounced 250 ms, refetched in chunks of 100, merged (`mergeCards`:
  in place when column + position are unchanged, else inserted before the first card with a higher
  position). Column / sprint / board / refresh events and `onResync` → one partial
  `router.reload({only: board, activeSprint, plannedSprints, projectItems})`.
- Never fights the user: own echoes ignored; nothing applied while the user drags a card or a
  timeline bar / row (`setPaused`) or any Inertia visit is in flight (applied right after); a fetch
  that started on older props is asked again, and a late answer never overwrites a newer request for
  the same card (per-card request sequence). Dialog, scroll and filters
  stay; an open card deleted / moved away by someone else gets a toast; a deleted board → toast +
  back to the project.
- Moved cards slide (FLIP via Web Animations), changed cards flash (primary at 18 %), skipped with
  `prefers-reduced-motion`. Cards / list rows carry `data-live-card`.
- `useLiveBoardReload(boardId, props, paused)` — the backlog page: any foreign event → debounced
  partial reload (not mid-drag).

### Client — plans (`hooks/useLivePlan.ts`, `components/plan/usePlanEditor.ts`)
- `plan.updated {revision}` → `editor.remoteSaved(revision)`: ignored when ≤ the editor's revision
  (its own save). Otherwise followed when idle: clean → fetch `plans.document` and swap it in (undo
  history cleared — undoing into the old document would revert their change; status shows "Updated
  by <name>"); mid-gesture or saving → wait; unsaved local changes → the pending autosave goes out
  and the existing revision guard answers: 409 → the existing banner "<name> changed this plan while
  you were editing … Load latest / Overwrite with mine" (never overwritten silently).
- Viewers follow too (their zoom / view settings stay theirs).
- `issues.changed` → `refreshIssues()` (debounced 400 ms): viewers see it at once (display sync),
  editors apply the issue sync and autosave it.
- **Idempotent save:** `PlanController::save` answers 200 (current revision, nothing written) when a
  stale `base_revision` posts exactly the stored document (canonical JSON, keys sorted) — two open
  editors applying the same issue sync don't conflict. `checkpoint` saves still conflict.
- Rename / share by someone else → `router.reload({only: ['plan']})` (a name being typed and the share
  button follow); deleted → toast + back to the project. A failed document fetch is not retried in
  a loop (the next event / resync tries again).
- Presence avatars in the board / plan headers belong to the live basics branch (not built here).

## Changes
- PHP: `app/Support/Live/BoardLive.php` (new), `LiveServiceProvider` (register), `ImportScope`
  (importFinished), `BoardController` (`cards` + touches), `SprintController` (rank touch),
  `PlanController` (idempotent stale save), `Item` (moveToSprint subtree, watchers),
  `BulkItemEdit`, `AutomationActions`, `ItemFieldValues` (touches), `routes/web.php` (`boards.cards`).
- JS: `lib/liveClientId.ts`, `hooks/useLiveBoard.ts`, `hooks/useLivePlan.ts` (new);
  `Pages/Boards/Show.tsx` (shadowed board, pause while dragging, `data-live-card`),
  `Pages/Boards/Backlog.tsx`, `Pages/Plans/Show.tsx` (live hook, "Updated by" status),
  `components/plan/usePlanEditor.ts` (`remoteSaved`, follow logic, explicit autosave timer state).
- Tests: `tests/Feature/Live/LiveBoardsTest.php`, `tests/Feature/Live/LivePlansTest.php`,
  `e2e/tests/live-boards.spec.ts`.

## Test plan
- PHPUnit `tests/Feature/Live/LiveBoardsTest.php` (15): move (column / position / client id, item
  channel, no title in any body), create / update / labels / comments / subtree delete, bulk edit =
  one `items.changed`, column create / rename / reorder / delete, move to another board (out + in),
  sprint + backlog rank, MCP move + offline sync, ImportScope → one `board.refreshed` per board, off
  → nothing, rolled-back update / delete / move away / board delete → nothing, bypass paths (reorder,
  sprint subtree, watchers, links, restore), away-and-back in one request → `item.moved`; `boards.cards` matrix (member / outsider / guest / other
  board / foreign / deleted / validation), Scrum = running sprint, constant query count.
- PHPUnit `tests/Feature/Live/LivePlansTest.php` (4): save → `plan.updated` {revision} with client id
  and no content, rename / MCP `update_plan` / restore / delete, linked issue → `issues.changed`
  (unlinked → nothing), idempotent stale save vs. real conflict vs. checkpoint.
- e2e `e2e/tests/live-boards.spec.ts` (3, both contexts opted in): kanban create (UI) / drag (UI) /
  edit / filter-respecting create + rename / delete / new column → Max's board follows without reload;
  list view rename / status / delete; Gantt row + bar + bar move reach Max, his held pending save gets
  the conflict banner and keeps his row, "Load latest" takes Erin's version.

## Known limits
- Events of a disconnect gap are lost (foundation): `onResync` reloads the board / plan state.
- The open issue dialog doesn't update its fields live (only gone → toast); live issue descriptions
  / comments are the live basics branch (`item.{id}` gets `item.updated` for the other fields from here, which the issue view already refetches on).
- A remote plan change clears the local undo history of an idle editor.
- `issues.changed` reaches plans through `content_references` (derived rows are rewritten on every
  plan save, so links are current).

## ARCHITECTURE / KNOWLEDGE notes for the lead
- ARCHITECTURE → Kanban: "Live (BRAI-152): `Support/Live/BoardLive` publishes card / column / sprint
  / board changes to `board.{id}` (model events + explicit touches, coalesced per request, imports →
  `board.refreshed`); `boards.cards` refetch endpoint; `hooks/useLiveBoard.ts` patches the open board."
  Plans: "`plan.{id}` `plan.updated {revision}` / `issues.changed`; `usePlanEditor.remoteSaved`;
  identical stale saves are accepted."
- KNOWLEDGE: (1) a write that bypasses model events (query-builder update, pivot sync, mass delete)
  must call `BoardLive::itemIds()` / `columnsReordered()` or open boards miss it; (2) `X-Live-Client`
  is how a tab recognizes its own echo; (3) publishing tests: `Http::fake` + `defer()->invoke()` (no
  `withoutDefer()`, which defeats the per-request coalescing); (4) in this sandbox the e2e app server's *first* publish
  after start sometimes fails (cURL 7, connection refused) and Live pauses publishing for 15 s; run
  alone, the live specs (and the foundation's bell spec) then time out — run together with other
  specs they pass (`boards` + `live-boards`: 25/25).

## Changelog
- **Live boards and plans:** cards created, moved, edited or deleted by teammates (and by
  automations, the AI connector or imports) appear on your open board, list and backlog right away —
  your filters, drag and open card stay as they are; Gantt plans follow others' changes and never
  overwrite your unsaved edit.
