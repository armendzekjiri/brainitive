<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Files on an issue, the same way from the issue dialog (BoardController) and the App API (BRAI-405):
 * stored on the public disk under `attachments/<item id>/`, with a history entry. Callers authorize first
 * (ItemPolicy::update) and validate the file with RULES.
 */
final class ItemAttachments
{
    /** The issue dialog's limit: 10 MB. */
    public const RULES = ['required', 'file', 'max:10240'];

    public static function add(Item $item, User $user, UploadedFile $file): Attachment
    {
        $filename = Uploads::name($file);
        $path = $file->storeAs('attachments/'.$item->id, $filename, 'public');

        $attachment = $item->attachments()->create([
            'user_id' => $user->id,
            'filename' => $filename,
            'original_filename' => Uploads::displayName($file),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        ActivityLog::log($item, 'attachment_added', null, ['name' => $attachment->original_filename]);

        return $attachment;
    }

    public static function remove(Item $item, Attachment $attachment): void
    {
        Storage::disk('public')->delete($attachment->path);

        ActivityLog::log($item, 'attachment_removed', null, ['name' => $attachment->original_filename]);

        $attachment->delete();
    }

    /** The file's address (the public disk, as on the web). */
    public static function url(Attachment $attachment): string
    {
        return asset('storage/'.$attachment->path);
    }
}
