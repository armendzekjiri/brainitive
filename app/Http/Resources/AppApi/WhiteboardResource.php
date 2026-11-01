<?php

namespace App\Http\Resources\AppApi;

use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A whiteboard with its Excalidraw scene, read-only (`GET /whiteboards/{uuid}`, BRAI-414): the page fields of
 * the tree, the scene's live elements (deleted ones left out), the canvas settings and the embedded image files,
 * and the preview image of the last "Save & exit" (may be older than the scene).
 *
 * @mixin Page
 */
class WhiteboardResource extends PageResource
{
    /** What of Excalidraw's appState a viewer needs; the rest is the editing session's. */
    private const APP_STATE = ['viewBackgroundColor', 'gridSize', 'gridStep', 'gridModeEnabled', 'scrollX', 'scrollY', 'zoom'];

    /** Changes with every save of the scene (edit revision) or the page. */
    public static function etag(Page $page): string
    {
        return 'wb-'.$page->id.'-'.$page->edit_revision.'-'.$page->updated_at?->getTimestamp();
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setEtag(self::etag($this->resource));
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $content = is_array($this->content) ? $this->content : [];
        $elements = array_values(array_filter(
            is_array($content['elements'] ?? null) ? $content['elements'] : [],
            fn ($element) => is_array($element) && ! ($element['isDeleted'] ?? false),
        ));
        $appState = is_array($content['appState'] ?? null) ? array_intersect_key($content['appState'], array_flip(self::APP_STATE)) : [];
        $files = is_array($content['files'] ?? null) ? $content['files'] : [];
        $preview = $content['previewImageUrl'] ?? null;

        return [
            ...parent::toArray($request),
            'revision' => (int) $this->edit_revision,
            'scene' => [
                /**
                 * Excalidraw elements (type, x, y, width, height, text, …) as the editor saved them
                 *
                 * @var list<array<string, mixed>>
                 */
                'elements' => $elements,
                /**
                 * canvas settings: viewBackgroundColor, gridSize, scrollX / scrollY, zoom
                 *
                 * @var array<string, mixed>
                 */
                'app_state' => (object) $appState,
                /**
                 * images on the board by file id: {mimeType, dataURL, …}
                 *
                 * @var array<string, mixed>
                 */
                'files' => (object) $files,
            ],
            // PNG of the last "Save & exit" on the web; null when there is none
            'preview_image_url' => is_string($preview) && $preview !== '' ? $preview : null,
            'can' => [
                // drawing: the web / desktop webview
                'update' => $user->can('update', $this->resource),
            ],
        ];
    }
}
