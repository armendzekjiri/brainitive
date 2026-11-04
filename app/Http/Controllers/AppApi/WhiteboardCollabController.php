<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\CollabBootstrapRequest;
use App\Http\Requests\AppApi\WhiteboardSceneRequest;
use App\Http\Resources\AppApi\WhiteboardBootstrapResource;
use App\Http\Resources\AppApi\WhiteboardCollabSessionResource;
use App\Http\Resources\AppApi\WhiteboardSceneSaveResource;
use App\Models\Page;
use App\Support\AppApi\AppTokens;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\WhiteboardCollab;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — live whiteboards for native clients (tablets). The browser's three calls (the web's
 * WhiteboardCollabController, Support\Collab\WhiteboardCollab) with the web's field names, so a port of
 * `resources/js/lib/whiteboard/live.ts` only changes the URLs (and reads `data`): a connection token for the
 * realtime server, the one-time load of the stored elements into an empty live document, and storing the live
 * scene as the whiteboard. Who may join is the rule the realtime server asks for (`internal/collab/access` →
 * WhiteboardCollab::access): editors draw, everyone else who can open the whiteboard watches.
 */
#[Group('Whiteboards', weight: 240)]
class WhiteboardCollabController extends Controller
{
    use ResolvesVisible;

    /**
     * A short-lived token to join a whiteboard's live document.
     *
     * Connect to `url` with `token` and open `document` (Yjs over WebSocket, the web's live whiteboard format).
     * `edit: false` = watch only; send `view: true` for a read-only connection even as an editor (a preview).
     * The token lasts about two minutes: ask again for every (re)connect. `live` on `GET /whiteboards/{uuid}`
     * says beforehand whether this is offered (403 when not: live editing switched off on the server, not a
     * whiteboard, archived). The token is bound to this device: once it is signed out, the socket closes with
     * 4410. After a 409 on bootstrap / scene the document was replaced: ask for a new token.
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     * @throws AccessDeniedHttpException live drawing isn't offered for this page and user (403)
     */
    public function token(Request $request, string $uuid): WhiteboardCollabSessionResource
    {
        $page = $this->visiblePage($request, $uuid);
        $request->validate([
            // a read-only connection (e.g. a preview), even for an editor
            'view' => ['sometimes', 'boolean'],
        ]);
        $access = $this->access($request, $page);
        abort_if($access === null, 403, 'Live drawing is not available for this page.');

        // bound to this device: once it is signed out, the realtime server closes its sockets (4410)
        return new WhiteboardCollabSessionResource(WhiteboardCollab::session($page, $request->user(), $access, $request->boolean('view'), AppTokens::currentDevice($request)?->id));
    }

    /**
     * Claim the first load of an empty live whiteboard.
     *
     * Call it when the synced document has no scene yet: `granted` → write `elements` into it and save once
     * with `baseline: true`; `wait` → another client loads them. 409 `conflict` (+ `epoch`, `reset: true`): the
     * document was replaced — reconnect with a new token. Editors only (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function bootstrap(CollabBootstrapRequest $request): WhiteboardBootstrapResource|JsonResponse
    {
        $page = $request->page();
        abort_unless($this->access($request, $page) === 'edit', 403, 'Live drawing is not available for this page.');

        $result = WhiteboardCollab::claimBootstrap($page, (int) $request->validated('epoch'), (string) $request->validated('claim'));
        if ($result['status'] === 'stale') {
            return self::stale((int) $result['epoch']);
        }

        return new WhiteboardBootstrapResource($result);
    }

    /**
     * Store the live scene as the whiteboard.
     *
     * What a browser sends every few seconds while it leads the document (and on Save / Save & exit with
     * `checkpoint`): the scene (elements in order, canvas settings, the images the server hasn't confirmed) and
     * the Yjs state vector it was made from — as JSON strings, like the browser's form, or as objects. An older or
     * concurrent snapshot is `covered` (nothing written). With a preview `image` (PNG) send multipart as POST with
     * `_method=PUT`. 409 `conflict` (+ `epoch`, `reset: true`): the document was replaced — reconnect. Editors only
     * (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function scene(WhiteboardSceneRequest $request): WhiteboardSceneSaveResource|JsonResponse
    {
        $page = $request->page();
        abort_unless($this->access($request, $page) === 'edit', 403, 'Live drawing is not available for this page.');

        $result = WhiteboardCollab::saveFromClient(
            $page,
            $request->user(),
            (int) $request->validated('epoch'),
            $request->validated('scene'),
            $request->validated('vector'),
            $request->boolean('baseline'),
            $request->boolean('checkpoint'),
            $request->file('image'),
        );
        if ($result['status'] === 'invalid') {
            throw ValidationException::withMessages(['scene' => 'Invalid whiteboard state.']);
        }
        if ($result['status'] === 'stale') {
            return self::stale((int) $result['epoch']);
        }

        return new WhiteboardSceneSaveResource($result);
    }

    /** @return 'edit'|'view'|null */
    private function access(Request $request, Page $page): ?string
    {
        return CollabConfig::enabled($request) ? WhiteboardCollab::access($page, $request->user()) : null;
    }

    /** The document was replaced: the error shape + the web's `reset` / `epoch`. */
    private static function stale(int $epoch): JsonResponse
    {
        return response()->json(['message' => 'This whiteboard was replaced. Connect again with a new token.', 'code' => 'conflict', 'status' => 'stale', 'reset' => true, 'epoch' => $epoch], 409);
    }
}
