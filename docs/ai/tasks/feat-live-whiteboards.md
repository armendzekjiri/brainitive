# Live whiteboards — draw together (BRAI-153)

**Branch:** feat/live-whiteboards
**Status:** done (ready for the lead to merge into release/v1.44.0)
**Started:** 2026-10-26
**Board card:** BRAI-153 (board item 329) · release v1.44.0

## Problem / goal
Several people draw on the same whiteboard (Excalidraw) at once, like Miro: shapes, stickies, arrows
and images appear for everyone instantly, everyone sees the others' cursors with their names (and what
they have selected). Works offline (merged on reconnect) and falls back to today's single-user editor
when live drawing isn't available. Laravel stays the source of truth for the whiteboard content (the
same JSON the single-user editor saves), so history checkpoints, issue-card references, search and
everything that reads `pages.content` keep working.

## Approach

### Same realtime server, own document kind
- Reuses the live-docs stack (BRAI-54, `tasks/feat-live-docs.md`): Hocuspocus server in `realtime/`,
  `CollabToken` (2-min HMAC token), `page_collab_states` (one row per page: epoch + Yjs state +
  vector), internal API, 60 s access re-check, `COLLAB_URL` switch, Docker service and `/collab` path.
- Document name **`whiteboard.<uuid>.<epoch>`** (docs: `page.<uuid>.<epoch>`). The realtime server's
  only change is accepting that prefix (`DOCUMENT_NAME` in `realtime/src/token.js`); every hook is
  kind-agnostic. Laravel's `CollabDocument::parse()` returns the kind; the internal API refuses a name
  whose kind doesn't match the page (a doc never opens as a whiteboard and the other way round).
- A page that turns from doc into whiteboard (or back) always changes `content`, which resets the
  epoch (below), so a Y doc of the other kind is never loaded.

### Y document layout
- `elements`: `Y.Map<elementId, ExcalidrawElement>` (plain JSON values). Winner per element =
  Excalidraw's own rule: higher `version`, tie → lower `versionNonce`. Deleted elements stay as
  tombstones (`isDeleted: true`) so a delete wins over an older edit.
- `files`: `Y.Map<fileId, {mimeType, created, url?|dataURL?}>` — only images added during a live
  session (everything already stored comes with the page). The adder uploads the image through the
  existing `upload.image` endpoint (public disk, jpeg/png/gif/webp, 10 MB) and shares the URL; others
  fetch it (same-origin `/storage/…` paths only) and add it to their scene as a data URL. SVG (refused
  by the upload) or a failed upload falls back to an inline data URL ≤ 1.5 MB.
- appState (scroll, zoom, tool, selection) stays local.

### Browser binding (`resources/js/lib/whiteboard/*`, entry `resources/js/whiteboard-live.ts`)
- The Excalidraw bundle (`excalidraw-build/src/main.jsx`) gains small hooks: `on('change'|'pointer')`,
  `raw()` (the Excalidraw API), `setCollaborating`, `setViewMode`, `whenReady()`, and the statics
  `reconcileElements`, `restoreElements`, `CaptureUpdateAction`.
- Local → Y: on every scene change (throttled 50 ms) each element whose local copy beats the Y entry
  is written in one transaction (origin `local`).
- Y → local: remote entries are restored (`restoreElements`, sorted by fractional index) and merged
  with Excalidraw's own `reconcileElements` (keeps the element you're editing / resizing), then
  `updateScene({captureUpdate: NEVER})` — remote changes never land on your undo stack.
- Awareness (same validation as docs: `awarenessData.userId` = token user, `name` = token name,
  `#rrggbb` colour) carries `pointer {x, y, tool}`, `button`, `selectedElementIds` (≤ 200). Remote
  states become Excalidraw `collaborators` (name ≤ 80 chars, colour from our palette by user id, finite
  coordinates only), so Excalidraw draws named cursors + selection outlines and its avatar list.
- Leader + saves like docs: the browser that changed something saves 2 s later (max 10 s while drawing
  continuously), remote changes are saved by the leader (editor with the lowest awareness client id)
  after 4 s, `pagehide` sends a `keepalive` save when it fits in 60 KB.

### Persistence (Laravel)
- `App\Support\Collab\WhiteboardCollab`: `supports` (page + `is_whiteboard`), `access` (`edit` =
  `update`; `view` = anyone who can `view` — a whiteboard has no draft, readers already see the live
  `content`), `props`, `session`, `claimBootstrap`, `save`.
- Routes (ReadOnlySession, throttled): `pages.whiteboard.collab.session` (POST, `view=1` → read-only
  token even for editors: the page preview), `pages.whiteboard.collab.save` (POST, multipart so "Save &
  exit" can attach the preview PNG), `pages.whiteboard.collab.bootstrap` (POST).
- Save = the scene serialized **from the Y doc** (non-deleted elements in z-order) + the vector of the
  same moment. Same state-vector guard as docs (`covered` → the leader saves the merged scene).
  Files: stored ∪ sent (a browser only sends images the server hasn't confirmed), so a leader that
  hasn't downloaded an image yet never drops it. Unchanged elements / files / library → nothing written.
  Otherwise `Page::saveWhiteboard()` with `collabWrite`: `edit_revision`, `last_edited_by`,
  checkpoints, `Page::saved` → derived issue-card references (`ContentReferences::syncWhiteboard`),
  search index observer — exactly the single-user path.
- **Checkpoints:** live saves use the 30-min interval + explicit Save / Save & exit only; the "someone
  else saved last" rule is skipped live (two people drawing would otherwise create a version per save).
- **Bootstrap:** empty Y doc for this epoch → one editor claims (`PageCollab::claim`, the same row lock
  + 20 s lease as docs) and gets the stored elements; it writes them into the Y map and reports the
  vector as `baseline` (nothing written to the page).
- **Reset:** `Page::saved` — a whiteboard whose `content` changed outside a live save (single-user /
  fallback save, version restore, MCP, duplicate…) gets `epoch + 1`, state cleared. Live browsers learn
  it through the store 409 / 60 s access check (server closes the doc) → session has a new document
  name → "This whiteboard was replaced … Load latest" banner (editing stops; reload opens the new one).

### Review gate (BRAI-80)
Whiteboards are outside the review gate today (`saveExcalidraw` refuses scene saves only on
review-required **non-whiteboard** pages). The live save keeps exactly that: it only accepts pages that
are whiteboards right now (`WhiteboardCollab::supports`), so it can never write a scene over a
(review-required) document — no side door. Session / bootstrap refuse non-whiteboards too.

### Fallback & offline
- No `COLLAB_URL` (or e2e without the `X-E2E-Collab` header) → `excalidrawConfig.live` is null →
  today's single-user editor (autosave, 409 banner). CI and the other whiteboard specs run that way.
- Configured but unreachable (no first sync within 5 s and nothing on this device) → single-user
  autosave + "Live drawing unavailable — drawing alone. Try again".
- Offline → Y doc in IndexedDB `brainitive-whiteboards-u<user>-<uuid>-e<epoch>` (older epochs deleted),
  status "Offline — changes kept on this device", merged on reconnect. Removed on lost access, on a
  replaced scene and on sign-out (`clearAllOfflineData` → `forgetLiveWhiteboardCopies`, so the next
  person on a shared browser can't read them). The page preview keeps no copy.

### Viewers
`pages.show` gets a `whiteboardCollab` prop; the read-only preview connects with `view=1` and shows
everyone's drawing + cursors live ("Live" badge + who's drawing). Editors open the full editor.

### Docker
Nothing new: same `realtime` service, same `/collab` path, same internal API; the Vite build gains the
`resources/js/whiteboard-live.ts` entry (built with the rest).

## Test plan
- PHPUnit `tests/Feature/Pages/WhiteboardCollabTest.php`: session for editor / viewer (published) /
  outsider / doc page / disabled, `view=1`; internal API kind check + access; bootstrap claim; save
  round trip (content format, derived issue-card references, files merge, vector guard, unchanged,
  checkpoints: interval only / explicit, preview image), epoch 409, reset on single-user save / restore
  but not on live save; review-required doc refused.
- Node: `realtime/test/token.test.js` accepts `whiteboard.<uuid>.<epoch>`.
- e2e `e2e/tests/live-whiteboards.spec.ts` (own realtime server on E2E_PORT + 100, like live-docs):
  two people draw → the other sees the rectangle and the named cursor; offline drawing merges on
  reconnect; server down → fallback notice + single-user autosave. Plus content / live-docs specs.

## Known limits
- Deleted elements stay in the Y doc as tombstones until the next epoch (any non-live content change).
- Images added live are also stored as files on the public disk (unguessable names, like doc images).
- A single-user (fallback) save while others are live resets their session (banner, reload) — same as
  docs.
- `appState` (background colour, grid) saved with the scene is the saving browser's.
- An image whose upload fails (or an SVG) over 1.5 MB isn't shared live: the others see Excalidraw's
  placeholder until they reload (the adder's save stores it). With the public disk on another host (S3)
  live images only appear after a reload — browsers only fetch `/storage/…` on this site.
- Issue cards keep their element id when redrawn (arrows stay attached); the card gets a newer version
  and the old label a deleted one, so every canvas takes the card over the old shape.
