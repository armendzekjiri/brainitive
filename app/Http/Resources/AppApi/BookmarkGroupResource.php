<?php

namespace App\Http\Resources\AppApi;

use App\Models\Bookmark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bookmark group ("collection") of a workspace (BRAI-399), addressed by `id`. Needs `workspace` and
 * the item counts of the list query.
 *
 * @mixin Bookmark
 */
class BookmarkGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $workspace = $this->resource->workspace;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            // an emoji or an image URL ('' when none)
            'icon' => $this->resource->icon_display,
            // private (its creator, collaborators, workspace admins) | public (every member + share_url)
            'visibility' => $this->visibility,
            'counts' => [
                'links' => self::count($this->resource->getAttribute('links_count')),
                'todos' => self::count($this->resource->getAttribute('todos_count')),
                'todos_completed' => self::count($this->resource->getAttribute('todos_completed_count')),
                'notes' => self::count($this->resource->getAttribute('notes_count')),
                'files' => self::count($this->resource->getAttribute('files_count')),
            ],
            'is_owner' => (int) $this->created_by === (int) $request->user()->id,
            'position' => (int) $this->position,
            'share_url' => $this->visibility === 'public' ? route('bookmarks.public.show', $this->uuid) : null,
            'workspace' => [
                'slug' => (string) $workspace->slug,
                'name' => (string) $workspace->name,
            ],
            'url' => route('bookmarks.show', [$workspace->slug, $this->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    private static function count(mixed $value): int
    {
        return (int) $value;
    }
}
