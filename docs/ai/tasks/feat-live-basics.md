# Live basics — comments, who's here, typing, live issue descriptions (BRAI-151, part 2)

**Branch:** feat/live-basics (from release/v1.44.0, release merged in after live-whiteboards)
**Status:** done (merged into release/v1.44.0)
**Board card:** BRAI-151 (board item 327) · release v1.44.0 "Everything live"

## Problem / goal
On top of the live channel foundation (docs/ai/tasks/feat-live-foundation.md): an open issue shows
new comments at once, shows who else is looking at it (and at the board / plan), says when someone is
typing a comment, and its description can be co-edited like a document. Helpdesk ticket replies
appear live too; the bell / inbox polish what the foundation started.

## What the user gets
- **Live comments:** a colleague's comment / reply (board UI, MCP, anything that writes a `Comment`)
  appears on the open issue (dialog and full page) within a moment, no reload. The draft in your own
  comment / reply box and the scroll position stay; the "Comments n" count and History follow.
- **Who's here:** colleagues on the same issue (dialog header + full page header), board
  (`BoardHeader`: board, backlog, reports) and plan (header) appear as a compact avatar stack with a
  ring in their live colour; hover shows the names, a tap opens the list (phones). You are left out.
- **Typing:** "Max is typing…" (or "Max and Erin are typing…") under an issue's comment box, for top
  comments and replies. Gone 4.5 s after the last keystroke, or at once when the comment is posted /
  cancelled. Never yourself (also not from your other tabs).
- **Live issue descriptions:** double-click the description → when live editing is configured
  (`COLLAB_URL`), everyone editing it shares one document: text both ways, carets with names, an
  "Editing with you" avatar stack, status "Live — changes are saved for everyone" / "Saving…" /
  "Offline — changes kept on this device". "Done" closes the editor (there is nothing to discard: the
  text is saved continuously for everyone). Without live editing (404 / 403 from the session endpoint)
  the single-user editor opens as before (Save / Discard) without a notice; with the realtime server
  down: "Live editing unavailable — editing alone." + "Try again", then the single-user editor.
  People only viewing the issue get the new description / title within ~2–4 s (refetch on
  `item.updated`).
- **Ticket replies:** a reply (colleague, customer portal, inbound email) appears in the open ticket
  panel of the helpdesk board.
- **Bell:** read / unread / archive / "mark all read" in one tab updates the badge and the tab title
  `(3) …` in every other tab at once; live arrivals are merged on top of the bell / inbox list (rows
  loaded with "Load more" no longer drop) and slide in.

## Publishing points + payloads (all pass `Live::KEY`; no content)
| where | channel | event | payload |
|---|---|---|---|
| `Comment` created / updated (body, parent) / deleted (model events → `LiveThreads::comment`) | `item.{item_id}` | `comment.created\|updated\|deleted` | `{id: item_id, comment_id, parent_id}` |
| `TicketReply` created / updated (body, internal flag, email status) / deleted (`LiveThreads::ticketReply`) | `ticket.{ticket_id}` | `reply.created\|updated\|deleted` | `{id: ticket_id, reply_id}` |
| `Item::updated` when title / description changed (any path) | `item.{id}` | `item.updated` | `{id, fields: ['title'?, 'description'?]}` |
| `NotificationController` read / unread / archive / unarchive / bulk / read-all | `user.{id}` | `notifications.changed` | `{unread_count}` |

New channel type **`ticket.{id}`** (`LiveChannels::TYPES` + `load()`): `TicketPolicy::view`, ticket,
helpdesk (soft deletes), project and workspace not trashed.

## Decisions
- **Comments / replies publish from model events**, not controllers: one place covers the board UI,
  MCP (`add-item-comment`), the portal, inbound mail, future edit / delete endpoints. Tests prove it.
- **Issue view refetches the details endpoint silently** (`refreshDetails(true)`: no loading state,
  150 ms coalescing); `itemDetails` now also returns `title` + `description` so a viewer's description
  / title follow edits made elsewhere (skipped while that field's editor is open).
- **Presence = `useLivePresence` in a small component** (`components/live/LiveAvatars.tsx`) placed in
  the headers; only one line per page (Boards/Show item dialog, Items/Show, BoardHeader, Plans/Show).
- **Typing** (`components/live/useTyping.tsx`): signal `typing` `{}` at most every 2 s, `{stop: true}`
  after post / cancel; receivers expire after 4.5 s; the sender comes from the server.
- **Live descriptions reuse the document stack** instead of a new one:
  - Y doc `item.<id>.<epoch>`; own table `item_collab_states` (same columns as `page_collab_states`,
    `item_id` FK cascade) — migration `2026_11_02_140000`.
  - `App\Support\Collab\ItemCollab`: access (ItemPolicy update = `edit`, view = `view`, suspended /
    trashed = none), session token (`CollabToken`, same keys), bootstrap claim (20 s lease; legacy
    Editor.js JSON descriptions converted like the frontend's `parseDescription`), save with the
    state-vector guard, reset, epoch.
  - `ItemCollabController` → `items.collab.session|save|bootstrap` (`ReadOnlySession`, throttled like
    pages). `CollabInternalController` routes `item.*` names to ItemCollab (`CollabDocument::itemName /
    parseItem`; page / whiteboard parsing unchanged).
  - **Persistence through the normal item update:** `$item->update(['description' => …])` +
    `ItemMentions::notify(…, $previous)` — ItemObserver history + webhooks / automations, smart links
    (`Item::saved`), search, "mentioned you" only for newly mentioned people.
  - **History:** consecutive description-only saves of one person within 10 min collapse into one
    "updated description" entry (the previous one is dropped when the next lands).
  - Saves are calmer than documents: 2 s after own changes, 4 s for the leader after remote ones.
  - **Reset:** `Item::updated` — description changed without `$item->collabWrite` (single-user save,
    MCP, checkbox toggle in view mode, automations) → epoch + 1; live editors reopen on the new text.
  - Frontend: `useLiveDocument` got an optional `target` (endpoint URLs, IndexedDB key `item-<id>`,
    document name, save delays, notice texts); pages / documents unchanged when it's absent.
    `useLiveItemDescription` asks `items.collab.session` once per issue when editing starts; 404/403 →
    single-user editor.
  - Read-only (`view`) tokens exist (and are tested) for people who may only view; the UI only opens
    the live document while editing, viewers get the text via `item.updated` refetch.
- **realtime/**: one regex in `src/token.js` (`DOCUMENT_NAME` accepts `item.<1–18 digits>.<epoch>`) +
  `test/item.test.js`. Nothing else in `realtime/src`.
- **Ticket panel:** `ticket.{id}` event → `router.reload({only: ['helpdesk']})` (150 ms coalesced); the
  panel takes the fresh replies when they change (the reply draft is separate state).

## Files
- PHP: `app/Support/Live/LiveThreads.php` (new), `app/Support/Live/LiveChannels.php` (ticket),
  `app/Models/{Comment,TicketReply,Item}.php` (events), `app/Models/ItemCollabState.php` (new),
  `app/Support/Collab/{ItemCollab (new),CollabDocument}.php`, `app/Http/Controllers/{ItemCollabController
  (new),CollabInternalController,NotificationController,BoardController (itemDetails)}.php`,
  `routes/web.php`, migration `2026_11_02_140000_create_item_collab_states_table.php`.
- Frontend: `components/live/{LiveAvatars,useTyping}.tsx` (new), `components/item/useLiveItemDescription.ts`
  (new), `components/item/ItemDetail.tsx`, `components/editor/collab/useLiveDocument.ts` (target),
  `components/board/BoardHeader.tsx`, `Pages/{Items,Plans,Helpdesks}/Show.tsx`, `Pages/Boards/Show.tsx`
  (one line in the item dialog header), `components/notifications/{useNotifications.ts,NotificationRow.tsx}`,
  `components/NotificationsDropdown.tsx`, `Pages/Notifications/Index.tsx`.
- realtime: `src/token.js`, `test/item.test.js`.
- Tests: `tests/Feature/Live/LiveThreadsTest.php`, `tests/Feature/Boards/ItemCollabTest.php`,
  `e2e/tests/live-basics.spec.ts`.

## Test results
- PHPUnit (new): LiveThreadsTest 4, ItemCollabTest 11 — green. Affected suites (Live, Boards, PageCollab,
  WhiteboardCollab, Mcp ItemCommentTools, Helpdesk, Notifications): 329 passed.
- Node `npm --prefix realtime test`: 31 passed (3 new).
- e2e `live-basics.spec.ts`: 5 tests passed (comments + presence + typing + phone width; board / issue
  dialog presence; ticket reply; fallback; co-editing). Also green: live-channel, live-docs,
  live-whiteboards, notifications, notifications-coverage, boards, helpdesk* (58 passed).
- pint, `npx tsc --noEmit`, `npm run build` green.
- After the review fixes: live-basics + live-channel + notifications + boards + live-docs together 38/39 —
  `notifications.spec` "inbox page … bulk actions" timed out once under parallel load (page closed), then
  5/5 with `--repeat-each 3 --workers 1` and green alone; live-docs flaked once in an earlier combined run
  and passed on the rerun.

## Reviews
- security-reviewer: approve, nothing blocking.
- frontend-reviewer: fixed — "Done" keeps the editor open when the last save fails (and refetches on
  success); Cancel in the connecting state, a probe timeout (6 s), only 403 / 404 mean "no live editing"
  (other errors: editing alone with a notice, asked again next time); the bell merge is a functional
  update and waits for read / archive actions in flight; only problems are announced (aria-live) in the
  live status; no details refetch for one's own description saves while editing; detail answers older
  than this tab's last write don't undo it. Not done (low): rows of an open inbox don't change state when
  read in another tab (only the badge / title do); the helpdesk optimistic reply can flicker.

## Known limits
- Events published while a tab was disconnected are lost; every subscriber refetches on `onResync`.
- The helpdesk panel shows the latest 5 replies (as before); a live reply reloads the whole `helpdesk`
  prop (fine for today's board sizes).
- Typing only on issues (not tickets).
- A description save is a normal update: webhooks / automation rules on `item.updated` fire once per
  live save (every few seconds while people type), only the history is merged.
- Viewers see the description after the next save (~2–4 s), not keystroke by keystroke (they don't
  join the Y doc; read-only tokens exist for a future "watch live" mode).
- Mixing a single-user (fallback) save with live editors: the fallback save wins and resets the live
  doc (same as documents).

## Conflict risks (lead)
- `resources/js/Pages/Boards/Show.tsx` (one import + one line in `ItemEditModal`'s header) and
  `resources/js/Pages/Plans/Show.tsx` (one import + one line in the header) — live-boards edits these.
- `app/Models/Item.php` boot (new `static::updated` block before the force-delete hook) and
  `BoardController::itemDetails` (two keys) — live-boards may publish from Item / BoardController.
- `app/Support/Live/LiveChannels.php` (ticket type), `useLiveDocument.ts` (target option),
  `CollabInternalController` (item branches).

## ARCHITECTURE / KNOWLEDGE notes for the lead
- ARCHITECTURE (Live channels): channel `ticket.{id}`; `LiveThreads` (comments / replies publish from
  model events); `components/live/` (LiveAvatars, useTyping); live issue descriptions
  (`ItemCollab`, `ItemCollabController`, `item_collab_states`, `useLiveItemDescription`,
  `useLiveDocument` `target`).
- KNOWLEDGE: description saves from live editing go through `$item->update` with `collabWrite` set —
  any other description write resets the live doc; history merges description-only saves per person
  (10 min); `itemDetails` carries title / description for live refetch; e2e live descriptions run
  their own realtime server on `COLLAB.port + 4` (`X-E2E-Collab-Port`).

## Changelog line
Live basics: comments and ticket replies appear instantly, see who else is on an issue, board or plan,
"… is typing" under the comment box, co-edit issue descriptions together, and the bell / tab title
follow reads in every tab.
