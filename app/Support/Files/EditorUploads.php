<?php

namespace App\Support\Files;

use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Images and documents put into rich text (docs, issue descriptions, comments): the web editor's uploads
 * (FileController) and the App API's `POST /uploads` (BRAI-416). Stored on the public disk under safe names
 * (Uploads: never the client's name or an active extension), served from /storage. Callers check the
 * workspace's file room first (FileRoom / `file.room`).
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
