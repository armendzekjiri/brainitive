<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\CollabBootstrapRequest;
use App\Http\Requests\AppApi\CollabSaveRequest;
use App\Http\Resources\AppApi\CollabBootstrapResource;
use App\Http\Resources\AppApi\CollabSaveResource;
use App\Http\Resources\AppApi\CollabSessionResource;
use App\Models\Page;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\PageCollab;
use App\Support\Collab\StateVector;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — live co-editing of docs for native clients (BRAI-412). The same three calls a browser makes
 * (PageCollabController on the web, Support\Collab\PageCollab): a connection token for the realtime server,
 * the one-time load of the draft into an empty live document, and saving the live document's HTML as the
 * draft. Who may join is the rule the realtime server asks for (`internal/collab/access` → PageCollab::access):
 * editors edit, readers of a never-published doc watch; readers of a published doc keep the published text.
 */
#[Group('Docs', weight: 230)]
class PageCollabController extends Controller
{
    use ResolvesVisible;

    /**
     * A short-lived token to join a doc's live document.
     *
     * Connect to `url` with `token` and open `document` (Yjs over WebSocket). `edit: false` = watch only. The
     * token lasts about two minutes: ask again for every (re)connect. `live` on `GET /pages/{uuid}` says
     * beforehand whether this is offered (403 when not: live editing switched off on the server, a whiteboard
     * or folder, an archived doc, or a reader of a published doc). After a 409 on bootstrap / save the
     * document was replaced (e.g. a text change from outside): ask for a new token.
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     * @throws AccessDeniedHttpException live editing isn't offered for this page and user (403)
     */
    public function token(Request $request, string $uuid): CollabSessionResource
    {
        $page = $this->visiblePage($request, $uuid);
        $access = $this->access($request, $page);
        abort_if($access === null, 403, 'Live editing is not available for this page.');

        return new CollabSessionResource(PageCollab::session($page, $request->user(), $access));
    }

    /**
     * Claim the first load of an empty live document.
     *
     * Call it when the synced document is empty: `granted` → load `html` (+ `name`, `icon`) into it and save
     * once with `baseline: true`; `wait` → another client loads it. 409 `conflict` (+ `epoch`): the document
     * was replaced — reconnect with a new token. Editors only (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function bootstrap(CollabBootstrapRequest $request): CollabBootstrapResource|JsonResponse
    {
        $page = $request->page();
        abort_unless($this->access($request, $page) === 'edit', 403, 'Live editing is not available for this page.');

        $result = PageCollab::claimBootstrap($page, (int) $request->validated('epoch'), (string) $request->validated('claim'));
        if ($result['status'] === 'stale') {
            return self::stale((int) $result['epoch']);
        }

        return new CollabBootstrapResource($result);
    }

    /**
     * Save the live document's HTML as the draft.
     *
     * What a browser sends every few seconds while it leads the document: the text as the editor's HTML, the
     * title, the icon and the Yjs state vector it was made from. An older or concurrent snapshot is `covered`
     * (nothing written). On a published doc this is its unpublished changes. 409 `conflict` (+ `epoch`): the
     * document was replaced — reconnect. Editors only (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function save(CollabSaveRequest $request): CollabSaveResource|JsonResponse
    {
        $page = $request->page();
        abort_unless($this->access($request, $page) === 'edit', 403, 'Live editing is not available for this page.');
        $vector = StateVector::normalize($request->validated('vector'));
        if ($vector === null) {
            throw ValidationException::withMessages(['vector' => 'Not a Yjs state vector.']);
        }

        $result = PageCollab::save($page, $request->user(), (int) $request->validated('epoch'), [
            'content' => ['type' => 'lexical', 'html' => (string) $request->validated('html')],
            'name' => (string) $request->validated('name'),
            'icon' => $request->validated('icon'),
        ], $vector, $request->boolean('baseline'));

        if ($result['status'] === 'stale') {
            return self::stale((int) $result['epoch']);
        }

        return new CollabSaveResource(['status' => $result['status'], 'page' => $result['page']->fresh() ?? $page]);
    }

    /** @return 'edit'|'view'|null */
    private function access(Request $request, Page $page): ?string
    {
        return CollabConfig::enabled($request) ? PageCollab::access($page, $request->user()) : null;
    }

    /** The document was replaced: the error shape + the current epoch. */
    private static function stale(int $epoch): JsonResponse
    {
        return response()->json(['message' => 'This document was replaced. Connect again with a new token.', 'code' => 'conflict', 'epoch' => $epoch], 409);
    }
}
