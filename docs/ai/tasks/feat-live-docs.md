# Live co-editing of documents (BRAI-54)

**Branch:** feat/live-docs
**Status:** in-progress
**Started:** 2026-10-26
**Board card:** BRAI-54 (board item 156) · release v1.43.0

## Problem / goal
Several people edit the same document at once: changes appear instantly, everyone sees the others'
carets (name + colour) and selections. Documents keep working offline and merge when back online.
The draft → publish → (optional review, BRAI-80) workflow stays as it is: collaboration happens on the
**draft**; publishing still snapshots HTML into `content` + a `PageVersion`; review snapshots / hash
keep their meaning. Whiteboards (Excalidraw) and issue descriptions are out of scope.

## Approach

### Real-time server: Hocuspocus in `realtime/` (not Reverb)
- **Hocuspocus 3.4.4** (MIT, Node ≥ 20, `ws`): purpose-built for Yjs — sync protocol, awareness,
  auth hook, read-only connections, debounced persistence hooks. Reverb would only relay opaque
  messages: we'd have to re-implement the Yjs sync handshake, awareness, merging of offline state,
  persistence and read-only filtering on top of Pusher channels, in PHP that can't hold Y docs.
  Hocuspocus 4.x needs Node ≥ 22 (`crossws`); 3.4.4 runs on the Node the servers already have — an
  upgrade is a later, separate step (client and server must match).
- `realtime/` is its own small package (plain ES modules, no build): `src/token.js` (verify),
  `src/laravel.js` (internal API client), `src/server.js` (hooks), `src/index.js` (env → listen).
  Tests with `node --test` (`realtime/test/`).
- Document name `page.<uuid>.<epoch>`. The epoch changes whenever the draft is replaced outside
  live editing (see "Reset"), so an old Y doc can never merge back into a replaced draft.

### Auth
- Browser → `POST pages.collab.session` (session auth, `PagePolicy`): returns `{url, document,
  epoch, token, edit, html_vector, user}`. **edit** = `update`; **view-only** only on never-published
  pages for people who can `view` them (they already see `content` = the draft). Readers of published
  pages get 403: they keep seeing the published `content`.
- Token: `base64url(json).base64url(HMAC-SHA256)`, payload `{v, sub, name, doc, edit, exp}`, 5 min.
  Keys are derived from `COLLAB_SECRET` with HMAC labels (`collab-token`, `collab-internal`), so a
  browser token can never be used on the internal API. The provider asks for a fresh token on every
  (re)connect.
- The server verifies the token (signature, expiry, `doc` == requested document), marks view-only
  connections `readOnly` (Hocuspocus drops their updates) and **re-checks access every 60 s** through
  `POST /api/internal/collab/access` (lost view → closed, lost edit → read-only, stale epoch → all
  closed).

### Persistence
- Table `page_collab_states` (page_id unique, `epoch`, `state` = base64 Yjs update, `state_bytes`,
  `html_vector`, bootstrap claim columns). Internal API (bearer = derived internal key, no session):
  `GET|PUT /api/internal/collab/documents/{document}` — 409 for a stale epoch, 404 for a gone page.
- Hocuspocus loads the state on first open and stores it debounced (2 s, max 10 s). A failed store is
  retried and the document **stays in memory** until a store succeeds (never unload unsaved text).
- **Draft HTML (`draft_content` / `content` of unpublished pages) is written by the browsers**, not
  by the server: rendering Lexical HTML (decorator nodes, polls, smart links, excalidraw embeds)
  needs the real editor. `PUT pages.collab.save` takes `{epoch, content, name, icon, vector}`:
  - The client that made a change saves (1 s debounce); for remote changes only the "leader"
    (editor with the lowest awareness client id) saves (3 s), so a crashed author still gets saved.
  - **State-vector guard:** each save carries the Yjs state vector the HTML was generated from
    (taken in the same `editor.read`). The server rejects a save whose vector is covered by the last
    stored one (`html_vector`), so an older snapshot can never overwrite a newer one.
  - A save that doesn't change name / icon / content (embeds ignored) writes nothing (no empty
    "unpublished changes", no revision bump, review hash unchanged).
  - It goes through `Page::saveDraft()` (mentions, `draft_updated_*`, `edit_revision`), so readers,
    reviews (`draftHash`), MCP, exports, meeting-notes extraction see the same draft as before.
- **Bootstrap** (Y doc empty for this epoch): every client that synced an empty doc asks
  `POST pages.collab.bootstrap {epoch, claim}`; one claim wins (row lock, 20 s lease, refused once a
  state is stored), loads the current draft HTML + name + icon into the doc; the others wait for it
  through the sync. Race-safe without the realtime server knowing anything about Lexical.

### Draft / publish / review interplay
- Publish / Update / Request review / Close first **flush** a collab save from this browser (its HTML
  ⊇ what it saw), then call the existing endpoints *without* content and without `base_revision`
  (concurrent live edits are expected, not conflicts). `ReviewGate` is untouched.
- **Reset:** `Page::saved` compares `draftHash()` before / after; if the editable draft changed
  through anything but a collab save (discard, restore version, MCP `update-page`, a fallback
  autosave, `PageController::update`), the collab state is invalidated: `epoch + 1`, state cleared.
  Publishing / unpublishing don't change the hash (draft → content), so the live doc survives them.
  Live clients notice through the store 409 (server closes the doc), the session (new document
  name), the presence heartbeat (`collab_epoch`) or a 409 on save, and reopen on the new draft.

### Fallback & offline
- Collab is on only when `COLLAB_URL` + `COLLAB_SECRET` are set (prop `collab`). Without them nothing
  changes (no notice) — CI and every existing e2e spec run like today.
- Configured but unreachable (no first sync within 5 s and nothing on this device): the editor falls
  back to today's single-user editing (autosave + 409 conflict banner) and shows "Live editing
  unavailable — editing alone" with "Try again".
- Offline: the Y doc lives in IndexedDB (`y-indexeddb`, db `brainitive-docs-u<user>-<uuid>-e<epoch>`,
  older epochs deleted); edits continue, status "Offline — changes kept on this device", merged on
  reconnect (Yjs CRDT). Status otherwise "Saving…" (server hasn't acknowledged) / "Saved".

### Awareness / UI
- `CollaborationPlugin` (`@lexical/react`, Lexical's official binding) with the Hocuspocus provider
  (thin adapter for Lexical's `Provider` interface: `synced` → `sync`). Carets + names + selection
  highlights render into a container inside the editor's relative wrapper. Colours per user id
  (fixed palette, readable on light + dark).
- Header: avatars of the people in the doc right now (coloured ring), plus the existing presence
  heartbeat for readers. Title + icon live in the Y doc too (`meta` map) so renames appear instantly.
- Node specifics: sticky notes keep their caption in a nested editor that Yjs can't sync through
  Hocuspocus (sub-documents); the node gets a `__captionState` JSON string mirrored from the caption
  editor (the nested editor itself is excluded from collab), and export reads that string.

## Changes
- Migration `2026_11_01_110000_create_page_collab_states_table`; model `PageCollabState`;
  `Page::collabState()`, `Page::editorState()` (moved from `PageController::draftState`),
  `Page::draftWouldChange()`, collab reset hook in `Page::saved`.
- `config/collab.php` (`COLLAB_URL`, `COLLAB_SECRET`, `COLLAB_TOKEN_TTL`, e2e opt-in).
- `app/Support/Collab/` — `CollabConfig`, `CollabToken`, `CollabDocument` (name parse/format),
  `StateVector`, `PageCollab` (session payload, bootstrap claim, save, reset).
- `PageCollabController` (`pages.collab.session|save|bootstrap`), `CollabInternalController`
  (`api/internal/collab/*`) + middleware `VerifyCollabSecret`; `PageController::show` prop `collab`,
  `presence` → `collab_epoch`.
- Frontend: `components/editor/collab/` (provider adapter, `useLiveDocument`, colours, avatars,
  status), `RichLexicalEditor` prop `collaboration`, `Pages/Pages/Show.tsx` wiring, StickyNoteNode.
- `realtime/` service, `deploy/realtime/brainitive-realtime.service` (systemd example),
  `deploy/realtime/nginx-collab.conf` (location `/collab`), `.env.example`, `npm run collab`.

## Test plan
- PHPUnit `tests/Feature/Pages/PageCollabTest.php`: session token for editor / view-only draft
  viewer / reader of a published page (403) / outsider / disabled; token signature + expiry + other
  page; internal API auth (no / wrong bearer, browser token as bearer), state load / save, stale epoch
  409, access check; collab save (vector guard, no-op, revision, review hash semantics, epoch 409);
  bootstrap claim race; reset on discard / restore / fallback save but not on publish; publish +
  review gate still work after collab saves.
- Node `realtime/test/`: token verify, auth hook, load / store via a fake Laravel, read-only.
- e2e `e2e/tests/live-docs.spec.ts` (starts its own realtime server on `E2E_PORT + 100`, opts in with
  `X-E2E-Collab: 1`, skipped on remote targets): two contexts see each other's text + caret name;
  offline edit merges on reconnect; server down → fallback notice + autosave still works.
- Existing doc e2e: content, document-review, smart-links, backlinks, meeting-notes, document-width,
  page-icons.

## Docs to update
Lead updates ARCHITECTURE / CONVENTIONS / KNOWLEDGE from this file (agents only edit their notes).

## Setup

### Dev
```bash
npm run collab:install                 # once: realtime/node_modules (its own package.json)
# .env: COLLAB_URL=ws://127.0.0.1:1234   (COLLAB_SECRET empty = derived from APP_KEY on both sides)
php artisan config:clear
npm run collab                          # next to `composer run dev`; reads .env (node --env-file)
```
`composer run dev` doesn't start it (it would kill the whole dev stack when `realtime/node_modules`
is missing). Without `COLLAB_URL` the app behaves exactly as before.

### Production (classic server: nginx + PHP-FPM + systemd) — nothing installed yet, ask the user first
1. Node ≥ 20 on the server (`node -v`).
2. `cd /var/www/brainitive && npm --prefix realtime ci --omit=dev` (also after every deploy).
3. `.env`: `COLLAB_URL=wss://brainitive.app/collab`; optionally `COLLAB_SECRET=<openssl rand -hex 32>`
   (else derived from APP_KEY; the service reads the same .env). `php artisan config:cache`.
4. systemd: `realtime/deploy/brainitive-realtime.service` → `/etc/systemd/system/` (adjust paths, user,
   `COLLAB_APP_URL`), `systemctl daemon-reload && systemctl enable --now brainitive-realtime`.
   It listens on 127.0.0.1:1234 only.
5. nginx: `realtime/deploy/nginx-collab.conf` into the HTTPS `server {}` (WebSocket upgrade for
   `/collab`, `client_max_body_size 25m` for `/api/internal/collab/`), `nginx -t && systemctl reload nginx`.
6. dev.brainitive.app: same with its own folder / port (e.g. 1235) / `COLLAB_URL`; its basic auth
   doesn't apply to WebSockets from the browser only if nginx lets `/collab` through — add
   `auth_basic off;` in that location (the token is the auth) or keep live editing off on dev.
7. Deploy workflow: add `npm --prefix realtime ci --omit=dev` + `systemctl restart brainitive-realtime`
   (browsers reconnect by themselves; unsaved changes stay in their IndexedDB).

### Docker (self-hosting) — included
`compose.yaml` service `realtime` (same image, `node realtime/src/index.js`, healthcheck), Caddy in the
app container proxies `/collab` to it and serves the internal API on the unpublished `:8081`;
`COLLAB_URL` defaults to `/collab`, the secret is derived from `APP_KEY`. Off: `COLLAB_URL=` + remove
the service. The image gains the Node 22 binary (~122 MB uncompressed) + realtime deps (~15 MB).
Verified with `docker compose build` + `up -d` (ports 8095/8455): two browsers co-editing, caret
names, draft saved on tab close, fallback notice with the service stopped.

## Known limits
- **Mixing a single-user (fallback) editor with a live session:** a fallback save replaces the draft →
  new epoch → live editors reopen on that text; live changes typed within the last ~1 s before it
  (not yet saved as HTML) are dropped from the draft. The 409 conflict check catches the usual case.
- **Offline edits on a replaced draft** (someone discarded / restored meanwhile) are not merged into
  the new draft; the editor says the draft was replaced.
- **Two browsers bootstrapping the same empty document at once** can't both load it (claim + 20 s
  lease), but if the realtime server crashes within its store debounce (2 s) right after a bootstrap
  and another browser bootstraps again, the text can appear twice (rare; fix by hand).
- **Draft HTML vs live document:** the HTML is saved by the browsers (1 s after own changes, 3 s by the
  "leader" for remote ones, keepalive on tab close / editor close). If every browser dies at once the
  Y state is still stored and the HTML catches up the next time someone opens the doc.
- Mention notifications / `draft_updated_by` name whoever saved the HTML (usually the author).
- Sticky-note captions sync as a whole (last writer wins per note), not character by character.
- Whiteboards and issue descriptions are not live (out of scope). Hocuspocus 3.4.4 (4.x needs Node 22).
- Remote carets are repositioned on awareness updates; after a window resize they move on the next one.
