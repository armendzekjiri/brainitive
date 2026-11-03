<?php

namespace App\Support\Files;

use App\Models\EditorUpload;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Images and documents put into rich text (docs, issue descriptions, comments): the web editor's uploads
 * (FileController) and the App API's `POST /uploads` (BRAI-416). Stored on the public disk under safe names
 * (Uploads: never the client's name or an active extension), served from /storage.
 *
 * Codex review (findings 4 + 8): every upload belongs to a workspace the uploader is in (`workspace` field, the web
 * editor also its `/workspaces/{slug}/…` referrer; none → 422, not theirs → 404), reserves its bytes atomically
 * (LimitGate::reserveFiles → 402 when full) and is recorded in `editor_uploads`, which WorkspaceUsageMeter counts.
 */
final class EditorUploads
{
    /** 10 MB; no SVG (scripts on our origin). */
    public const IMAGE_RULES = ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:10240'];

    public const IMAGE_MIMES = ['jpeg', 'png', 'gif', 'webp'];

    public const IMAGE_MAX_KB = 10240;

    /** 20 MB, the document types the editor attaches. */
    public const DOCUMENT_RULES = ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar', 'max:20480'];

    public const DOCUMENT_MIMES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar'];

    public const DOCUMENT_MAX_KB = 20480;

    /**
     * Store an editor upload for a workspace: reserve its bytes, store the file, record it.
     *
     * @return array{url: string, path: string, name: string, size: int, extension: string}
     *
     * @throws LimitReached the workspace's file storage is full (402)
     */
    public static function store(UploadedFile $file, bool $image, Workspace $workspace, ?User $user): array
    {
        $bytes = (int) $file->getSize();
        $gate = app(LimitGate::class);
        $gate->reserveFiles($workspace, $bytes);

        $stored = null;
        try {
            $stored = $image ? self::storeImage($file) : self::storeDocument($file);
            EditorUpload::create(['workspace_id' => $workspace->id, 'user_id' => $user?->id, 'path' => $stored['path'], 'size' => $stored['size']]);
        } catch (Throwable $e) {
            $gate->releaseFiles($workspace, $bytes);
            if ($stored !== null) {
                Storage::disk('public')->delete($stored['path']);
            }

            throw $e;
        }

        return $stored;
    }

    /**
     * The workspace a web editor upload counts against (fail closed, Codex review 8): the `workspace` field (slug),
     * else the same-site `/workspaces/{slug}/…` page it was sent from. None → 422; unknown or not the user's → 404.
     *
     * @throws ValidationException
     */
    public static function workspaceFor(Request $request): Workspace
    {
        $slug = $request->input('workspace');
        if (! is_string($slug) || $slug === '') {
            $referer = (string) $request->headers->get('referer');
            $sameHost = parse_url($referer, PHP_URL_HOST) === $request->getHost();
            $slug = $sameHost && preg_match('#^/workspaces/([^/?]+)#', (string) parse_url($referer, PHP_URL_PATH), $m) ? rawurldecode($m[1]) : null;
        }
        if (! is_string($slug) || $slug === '') {
            throw ValidationException::withMessages(['workspace' => 'Choose the workspace this file belongs to.']);
        }

        $workspace = Workspace::where('slug', $slug)->first();
        abort_unless($workspace instanceof Workspace && $request->user()?->hasWorkspaceAccess($workspace), 404);

        return $workspace;
    }

    /** @return array{url: string, path: string, name: string, size: int, extension: string} */
    public static function storeImage(UploadedFile $file): array
    {
        return self::stored($file, $file->storeAs('uploads/images', Uploads::imageName($file), 'public'), Uploads::imageExtension($file));
    }

    /** @return array{url: string, path: string, name: string, size: int, extension: string} */
    public static function storeDocument(UploadedFile $file): array
    {
        return self::stored($file, $file->storeAs('uploads/documents', Uploads::name($file), 'public'), Uploads::extension($file));
    }

    /** @return array{url: string, path: string, name: string, size: int, extension: string} */
    private static function stored(UploadedFile $file, string|false $path, string $extension): array
    {
        abort_if($path === false, 500, 'The file could not be stored.');

        return [
            'url' => Storage::url($path),
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => (int) $file->getSize(),
            'extension' => $extension,
        ];
    }
}
