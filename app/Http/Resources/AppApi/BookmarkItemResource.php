<?php

namespace App\Http\Resources\AppApi;

use App\Models\BookmarkItem;
use App\Support\Bookmarks\Bookmarks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item of a bookmark group (BRAI-399): a link (title / favicon / description fetched in the
 * background after it is saved), a to-do, a note or a file.
 *
 * @mixin BookmarkItem
 */
class BookmarkItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id' => $this->id,
            'group_id' => (int) $this->bookmark_id,
            // link | todo | note | file
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'content' => $this->content,
            // file items: where to download it
            'file_url' => Bookmarks::fileUrl($this->resource),
            // to-dos
            'is_completed' => (bool) $this->is_completed,
            // links: fetched from the page after saving
            'favicon_url' => self::text($metadata['favicon_url'] ?? null),
            'page_description' => self::text($metadata['description'] ?? null),
            'position' => (int) $this->position,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
