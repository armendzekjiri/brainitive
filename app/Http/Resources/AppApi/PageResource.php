<?php

namespace App\Http\Resources\AppApi;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doc, whiteboard or folder in a project's page tree (BRAI-411). `kind` tells them apart (the web stores
 * whiteboards as pages with an Excalidraw scene). An uploaded image icon is never sent as text here
 * (it is a long `data:` URL): `icon` is the emoji, `has_image_icon` says there is an image
 * (`GET /pages/{uuid}` → `icon_image`).
 *
 * @mixin Page
 */
class PageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;
        $icon = (string) $this->icon;
        $image = self::isImageIcon($icon);

        return [
            'id' => (int) $this->id,
            'uuid' => (string) $this->uuid,
            'project_id' => (int) $this->project_id,
            'parent_id' => $this->parent_id === null ? null : (int) $this->parent_id,
            'parent_uuid' => self::parentUuid($this->resource),
            /** @var 'doc'|'whiteboard'|'folder' */
            'kind' => self::kind($this->resource),
            'name' => (string) $this->name,
            'icon' => $image || $icon === '' ? null : $icon,
            'has_image_icon' => $image,
            /** @var 'draft'|'published'|'archived' */
            'status' => (string) $this->status,
            'position' => (int) $this->position,
            'url' => route('pages.show', [$project->workspace->slug, $project->slug, $this->uuid]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /** @return 'doc'|'whiteboard'|'folder' */
    public static function kind(Page $page): string
    {
        $kind = $page->getAttribute('kind'); // the tree query computes it without loading the content

        return match (true) {
            is_string($kind) && in_array($kind, ['doc', 'whiteboard', 'folder'], true) => $kind,
            $page->isFolder() => 'folder',
            $page->is_whiteboard => 'whiteboard',
            default => 'doc',
        };
    }

    /** As the web's `isImageIcon` (components/page/PageIcon.tsx). */
    public static function isImageIcon(string $icon): bool
    {
        return str_starts_with($icon, 'data:') || str_starts_with($icon, 'http') || str_starts_with($icon, '/');
    }

    private static function parentUuid(Page $page): ?string
    {
        if ($page->parent_id === null) {
            return null;
        }
        $uuid = $page->getAttribute('parent_uuid') ?? ($page->relationLoaded('parent') ? $page->parent?->uuid : $page->parent()->value('uuid'));

        return is_string($uuid) ? $uuid : null;
    }
}
