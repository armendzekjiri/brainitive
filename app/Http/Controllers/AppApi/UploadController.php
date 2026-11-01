<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\StoreUploadRequest;
use App\Http\Resources\AppApi\UploadResource;
use App\Support\Entitlements\LimitReached;
use App\Support\Files\EditorUploads;
use App\Support\Files\FileRoom;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — uploads for rich text (BRAI-416): the web editor's image / document upload (EditorUploads, shared
 * with FileController), the plan's file storage (FileRoom → 402). Files that belong to something have their own
 * upload: issue attachments (`POST /items/{id}/attachments`), bookmark files (`POST /bookmarks/{id}/items`).
 */
#[Group('Files', weight: 270)]
class UploadController extends Controller
{
    /**
     * Upload an image or document to use in a doc, an issue description or a comment.
     *
     * Multipart: `file` + `workspace` (slug). Answers 201 with the file's public `url` and a `markdown` snippet
     * (`![name](url)` / `[name](url)`) to insert into the Markdown you send. The file is stored on the workspace's
     * storage like the web editor's uploads; the same rules: images jpeg / png / gif / webp ≤ 10 MB (no SVG),
     * documents pdf / office / txt / csv / zip / rar ≤ 20 MB, never stored under the client's name.
     *
     * @throws NotFoundHttpException the workspace doesn't exist or the user isn't a member (404)
     * @throws LimitReached the workspace's file storage is full (402 limit_reached)
     */
    public function store(StoreUploadRequest $request): JsonResponse
    {
        $file = $request->upload();
        FileRoom::ensure($request->workspace(), $file);

        $image = $request->isImage();
        $mime = $file->getMimeType();
        $stored = $image ? EditorUploads::storeImage($file) : EditorUploads::storeDocument($file);

        return (new UploadResource([
            'url' => $stored['url'],
            'name' => $stored['name'],
            'size' => $stored['size'],
            'mime_type' => $mime,
            'kind' => $image ? 'image' : 'file',
        ]))->response()->setStatusCode(201);
    }
}
