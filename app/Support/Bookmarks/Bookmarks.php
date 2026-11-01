<?php

namespace App\Support\Bookmarks;

use App\Jobs\FetchLinkMetadataJob;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bookmark groups ("collections") and their items — links, to-dos, notes, files — the same way for the
 * web (`BookmarkController`) and the App API (`/api/v1/bookmarks`): rules, create, file uploads and
 * deletes (stored files go with them). Who may do what is BookmarkPolicy (visibleTo for lists); callers
 * authorize. Icon uploads and collaborators stay in the web controller.
 */
final class Bookmarks
{
    /** Never stored, whatever the browser claims the file is. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'phpt', 'phps',
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'cpl', 'msc', 'jar',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'psd1', 'vbs', 'vbe', 'wsh', 'wsf',
        'py', 'pyc', 'pyo', 'rb', 'pl', 'cgi', 'dll', 'so', 'dylib',
        'app', 'deb', 'rpm', 'run', 'out',
    ];

    private const FILE_MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z,rtf,md,json,xml,jpg,jpeg,png,gif,webp,bmp,ico,tiff,tif,svg,heic,heif,avif,bin';

    private const FILE_MIMETYPES = 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,text/plain,text/csv,text/markdown,application/zip,application/x-rar-compressed,application/x-7z-compressed,application/rtf,application/json,application/xml,text/xml,image/jpeg,image/png,image/gif,image/webp,image/bmp,image/x-icon,image/tiff,image/svg+xml,image/heic,image/heif,image/avif,application/octet-stream';

    /** http(s) only, and no characters a browser would strip to smuggle another scheme in. */
    public const URL_RULE = ['nullable', 'string', 'max:2048', 'regex:#^https?://[^\s\x00-\x1f]+$#i'];

    /**
     * A group's name, description and visibility (the icon's format is the caller's: the web also takes
     * uploads, the App API an emoji).
     *
     * @return array<string, mixed>
     */
    public static function groupRules(bool $update = false): array
    {
        return [
            'name' => $update ? 'sometimes|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            // private (its creator, collaborators and workspace admins) | public (every member + the share link)
            'visibility' => $update ? 'sometimes|in:private,public' : 'nullable|in:private,public',
        ];
    }

    /** @return array<string, mixed> */
    public static function itemRules(): array
    {
        return [
            'type' => 'required|in:link,todo,note,file',
            'title' => 'nullable|string|max:500',
            'url' => self::URL_RULE,
            'content' => 'nullable|string|max:10000',
        ];
    }

    /** @return array<string, mixed> */
    public static function itemUpdateRules(): array
    {
        return [
            'title' => 'nullable|string|max:500',
            'url' => self::URL_RULE,
            'content' => 'nullable|string|max:10000',
            'is_completed' => 'boolean',
        ];
    }

    /** A slug free in the workspace (also among deleted groups, which keep theirs). */
    public static function uniqueSlug(Workspace $workspace, string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name) ?: 'bookmarks';
        $slug = $base;
        for ($n = 1; Bookmark::withTrashed()->where('workspace_id', $workspace->id)->where('slug', $slug)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /**
     * A new group at the end of the workspace.
     *
     * @param  array{name: string, description?: string|null, icon?: string|null, visibility?: string|null}  $data
     */
    public static function createGroup(Workspace $workspace, User $user, array $data): Bookmark
    {
        return $workspace->bookmarks()->create([
            'name' => $data['name'],
            'slug' => self::uniqueSlug($workspace, $data['name']),
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'visibility' => $data['visibility'] ?? 'private',
            'position' => ($workspace->bookmarks()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);
    }

    /** Delete a group with its stored files. */
    public static function deleteGroup(Bookmark $bookmark): void
    {
        foreach ($bookmark->items()->where('type', 'file')->whereNotNull('file_path')->get() as $item) {
            self::deleteFile($item);
        }

        $bookmark->delete();
    }

    /**
     * A new item at the end of the group. A file is checked (blocked extensions, allowed types, 200 MB)
     * and stored; a link gets its title / favicon fetched in the background.
     *
     * @param  array<string, mixed>  $data  validated itemRules()
     *
     * @throws ValidationException
     */
    public static function createItem(Bookmark $bookmark, User $user, array $data, ?UploadedFile $file = null): BookmarkItem
    {
        $filePath = $file ? self::storeFile($bookmark, $file) : null;

        $item = $bookmark->items()->create([
            'type' => $data['type'],
            'title' => $data['title'] ?? $file?->getClientOriginalName(),
            'url' => $data['url'] ?? null,
            'content' => $data['content'] ?? null,
            'file_path' => $filePath,
            'position' => ($bookmark->items()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        if ($item->type === 'link' && $item->url) {
            FetchLinkMetadataJob::dispatch($item->id);
        }

        return $item;
    }

    /** Delete an item and its stored file. */
    public static function deleteItem(BookmarkItem $item): void
    {
        self::deleteFile($item);
        $item->delete();
    }

    /** The public URL of a file item, or null. */
    public static function fileUrl(BookmarkItem $item): ?string
    {
        return $item->type === 'file' && $item->file_path ? asset('storage/'.$item->file_path) : null;
    }

    /** @throws ValidationException */
    private static function storeFile(Bookmark $bookmark, UploadedFile $file): string
    {
        if (in_array(strtolower($file->getClientOriginalExtension() ?: ''), self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed for security reasons.']);
        }

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:204800', 'mimes:'.self::FILE_MIMES, 'mimetypes:'.self::FILE_MIMETYPES],
        ], [], ['file' => 'file'])->validate();

        return $file->storeAs('bookmark-files/'.$bookmark->id, Uploads::name($file), 'public');
    }

    private static function deleteFile(BookmarkItem $item): void
    {
        if ($item->type === 'file' && $item->file_path && Storage::disk('public')->exists($item->file_path)) {
            Storage::disk('public')->delete($item->file_path);
        }
    }
}
