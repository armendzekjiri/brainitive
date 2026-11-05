# App API: live whiteboards for native clients

**Branch:** feat/api-whiteboard-live
**Status:** done
**Started:** 2026-11-04
**Board card:** — (MCP not connected in this session)

## Problem / goal
The mobile app (tablets) should draw on whiteboards live, like the browser (BRAI-153). The App API only
has `GET /api/v1/whiteboards/{uuid}` (read-only scene); docs already have the native live trio (BRAI-412:
`collab-token`, `collab/bootstrap`, `collab/draft`). Add the same for whiteboards so a port of
`resources/js/lib/whiteboard/live.ts` works with only the URLs changed.

## Approach
- Same three calls as the web's `WhiteboardCollabController` (`session`, `bootstrap`, `save`), same
  field names, same epoch / conflict semantics, through `Support\Collab\WhiteboardCollab`:
  - `POST /api/v1/whiteboards/{uuid}/collab-token` (`{view?}`) → `{data: {url, document, epoch, token,
    expires_at, edit, vector, user}}` — the web session's fields. The token carries the device (`dev`),
    so the realtime server closes the socket with 4410 once the device is signed out (as the doc token).
  - `POST /api/v1/whiteboards/{uuid}/collab/bootstrap` (`{epoch, claim}`) → `{data: {status, elements, reset}}`.
  - `PUT /api/v1/whiteboards/{uuid}/collab/scene` (`{epoch, scene, vector, baseline?, checkpoint?, image?}`)
    → `{data: {status, edit_revision, version}}`. `scene` / `vector` as JSON strings (the browser's
    FormData) or objects. Multipart (preview `image`) = POST + `_method=PUT` (PHP doesn't parse multipart PUT).
  - 409: `{message, code: conflict, status: stale, reset: true, epoch}` (web fields + App API `code`).
- Differences to the web, all App API conventions: success bodies wrapped in `{data: …}`; invisible page
  → 404 (web: 403); errors carry `code`.
- No duplicated logic: the web controller's save body (decode scene / vector, store the preview, delete it
  on a stale save) moves into `WhiteboardCollab::saveFromClient`, used by both controllers.
  `WhiteboardCollab::session` gets the `$device` parameter (as `PageCollab::session`).
- `live: edit|view|null` on `GET /whiteboards/{uuid}` and `GET /pages/{uuid}` (whiteboards:
  `WhiteboardCollab::access`, docs unchanged); null while live editing is off.
- TV: not in its ability list → 403 `client_not_allowed` automatically.
- Throttles as the web: token 60/min, bootstrap 30/min, scene 120/min (`app.whiteboards.collab.*`).

## Changes
- `app/Support/Collab/WhiteboardCollab.php`: `session(..., ?int $device)`, `saveFromClient()`.
- `app/Http/Controllers/WhiteboardCollabController.php`: save via `saveFromClient` (behaviour unchanged).
- `app/Http/Controllers/AppApi/WhiteboardCollabController.php` (new), requests
  `WhiteboardSceneRequest` (new; bootstrap reuses `CollabBootstrapRequest`), resources
  `WhiteboardCollabSessionResource`, `WhiteboardBootstrapResource`, `WhiteboardSceneSaveResource`.
- `routes/app-api.php`: the three routes.
- `WhiteboardResource` + `PageDetailResource`: `live`.
- docs/ai/API.md, docs/api/openapi.json.

## Test plan
`tests/Feature/AppApi/WhiteboardCollabTest.php`: token fields + device binding + view-only, readers watch,
outsiders 404, docs / folders / realtime off 403, bootstrap + baseline + save + covered, 409 after an
outside change, readers can't save, bad input 422, multipart POST + `_method=PUT` with preview image,
throttle names, revoked device answered by `internal/collab/access`. TV list in `AppClientAbilitiesTest`.
Web `Pages/WhiteboardCollabTest` stays green.

## Docs to update
API.md (Docs + Whiteboards + Realtime + web-only table), OpenAPI spec.

## Open questions
- Image sharing on live boards: the browser uploads through the web's `/upload/image` (`uploadUrl`);
  the App API has `POST /uploads` (different answer shape: `{data: {url, …}}`) — or images go inline
  (≤ the inline limit). Left to the client.
