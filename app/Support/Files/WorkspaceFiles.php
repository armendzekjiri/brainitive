<?php

namespace App\Support\Files;

use App\Models\EditorUpload;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Every user-controlled stored file counts against its workspace's file storage, reserved before it is stored
 * (Codex web review C; the design of EditorUploads, Codex review 4 / 8):
 *
 * - files without a size column of their own (logos, headers, icons, bookmark files, lead-form images, whiteboard
 *   previews, temporary icons): store() reserves the bytes under the usage row lock (LimitGate::reserveFiles),
 *   stores the file and records it in `editor_uploads` (with its `kind`), which the meter counts;
 * - files with a size column (issue and ticket attachments): reserving() reserves the bytes around the caller's
 *   own store + row (the meter counts their tables);
 * - forget() / released(): a deleted or superseded file gives its bytes back.
 *
 * A failed store releases the reservation and removes a half-stored file. Nothing is counted twice: a path is in
 * `editor_uploads` once (re-storing the same path replaces its row) and attachments are never recorded there.
 */
final class WorkspaceFiles
{
    public const KINDS = [
        'workspace_logo', 'workspace_header', 'bookmark_icon', 'bookmark_file', 'project_icon', 'item_type_icon',
        'lead_form_image', 'whiteboard_image', 'temp',
    ];

    /** Temporary uploads (icons picked before their bookmark / collection exists) live this long. */
    public const TEMP_HOURS = 24;

    /**
     * Reserve, store on the public disk and record a workspace file.
     *
     * @throws LimitReached the workspace's file storage is full (402)
     */
    public static function store(Workspace $workspace, UploadedFile $file, string $directory, string $name, string $kind, ?User $user = null): string
    {
        return self::reserving($workspace, (int) $file->getSize(), function () use ($workspace, $file, $directory, $name, $kind, $user) {
            $path = $file->storeAs($directory, $name, 'public');
            abort_if($path === false, 500, 'The file could not be stored.');
            try {
                self::record($workspace, $path, (int) $file->getSize(), $kind, $user);
            } catch (Throwable $e) {
                Storage::disk('public')->delete($path);

                throw $e;
            }

            return $path;
        });
    }

    /**
     * Reserve `$bytes` for the duration of `$store` (which stores the file and its own row); released when it fails.
     *
     * @template T
     *
     * @param  Closure(): T  $store
     * @return T
     *
     * @throws LimitReached
     */
    public static function reserving(Workspace $workspace, int $bytes, Closure $store): mixed
    {
        $gate = app(LimitGate::class);
        $gate->reserveFiles($workspace, $bytes);
        try {
            return $store();
        } catch (Throwable $e) {
            $gate->releaseFiles($workspace, $bytes);

            throw $e;
        }
    }

    /** Bytes of a deleted file with its own size column (an attachment) go back to the workspace. */
    public static function released(?Workspace $workspace, int $bytes): void
    {
        if ($workspace) {
            app(LimitGate::class)->releaseFiles($workspace, $bytes);
        }
    }

    /**
     * A recorded file is gone or superseded: delete it from the disk, drop its row, give its bytes back. Only files
     * recorded for this workspace — or, for files stored before the accounting, under `$ownPrefix` (a directory
     * only this owner writes, e.g. `bookmark-icons/12/`) — are deleted, so a path a user typed in never removes
     * someone else's file.
     */
    public static function forget(Workspace $workspace, ?string $path, ?string $ownPrefix = null): void
    {
        if (is_string($path) && str_starts_with($path, 'http')) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }
        if ($path === null || $path === '' || str_contains($path, '..')) {
            return;
        }
        $path = ltrim(str_starts_with($path, '/storage/') ? substr($path, 9) : $path, '/');

        $row = EditorUpload::where('path', $path)->where('workspace_id', $workspace->id)->first();
        if ($row) {
            Storage::disk('public')->delete($path);
            $row->delete();
            app(LimitGate::class)->releaseFiles($workspace, $row->size);
        } elseif ($ownPrefix !== null && str_starts_with($path, $ownPrefix)) {
            Storage::disk('public')->delete($path);
        }
    }

    /** A temporary upload became a real one (moved into place): same bytes, new path and kind. */
    public static function adopt(Workspace $workspace, string $from, string $to, string $kind): void
    {
        EditorUpload::where('path', $from)->where('workspace_id', $workspace->id)->update(['path' => $to, 'kind' => $kind]);
    }

    /** Record (or re-record) a stored file; a path stored again replaces its old row and gives its bytes back. */
    public static function record(Workspace $workspace, string $path, int $bytes, string $kind, ?User $user = null): void
    {
        $existing = EditorUpload::where('path', $path)->first();
        if ($existing) {
            app(LimitGate::class)->releaseFiles($existing->workspace ?? $workspace, $existing->size);
            $existing->forceFill(['workspace_id' => $workspace->id, 'size' => $bytes, 'kind' => $kind, 'user_id' => $user?->id])->save();

            return;
        }

        EditorUpload::create(['workspace_id' => $workspace->id, 'user_id' => $user?->id, 'path' => $path, 'size' => $bytes, 'kind' => $kind]);
    }
}
