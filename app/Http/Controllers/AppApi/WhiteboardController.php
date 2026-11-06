<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppApi\WhiteboardResource;
use App\Models\Page;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — whiteboards (BRAI-414). A whiteboard is a page with an Excalidraw scene (PagePolicy: an unpublished
 * one is its author's and the admins'). The apps show the scene and draw live through WhiteboardCollabController;
 * templates and turning stickies into issues run in the web's editor (the desktop app's webview).
 */
#[Group('Whiteboards', weight: 240)]
class WhiteboardController extends Controller
{
    use ResolvesVisible;

    /**
     * A whiteboard's scene (Excalidraw elements, canvas settings, images) to show it.
     *
     * Render `scene.elements` with Excalidraw (or as a picture: `preview_image_url`, the last "Save & exit" —
     * it can be older than the scene). Answers an `ETag`; send it back as `If-None-Match` to get 304 while nothing
     * changed (live drawing saves every few seconds). The tree and other pages: `GET /projects/{id}/pages`
     * (`kind: whiteboard`). Its history: `GET /pages/{uuid}/versions`. `live: edit|view` → draw or watch live
     * (`POST /whiteboards/{uuid}/collab-token`).
     *
     * @throws NotFoundHttpException not a whiteboard, or the user can't see it (404)
     */
    public function show(Request $request, string $uuid): WhiteboardResource|Response
    {
        $page = $this->visiblePage($request, $uuid);
        abort_unless($page->isPage() && $page->is_whiteboard, 404);

        $etag = WhiteboardResource::etag($page);
        if (in_array('"'.$etag.'"', $request->getETags(), true)) {
            return response()->noContent(304)->setEtag($etag);
        }

        return new WhiteboardResource($page);
    }
}
