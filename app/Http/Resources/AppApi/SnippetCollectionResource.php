<?php

namespace App\Http\Resources\AppApi;

use App\Models\SnippetCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A snippet collection of a workspace (BRAI-399), addressed by `id`. Needs `workspace` and the
 * `snippets_count`.
 *
 * @mixin SnippetCollection
 */
class SnippetCollectionResource extends JsonResource
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
            // an emoji or an image URL
            'icon' => $this->icon,
            // private (members) | public (also anyone with share_url)
            'visibility' => $this->visibility,
            'snippets_count' => (int) $this->resource->getAttribute('snippets_count'),
            'position' => (int) $this->position,
            'share_url' => $this->visibility === 'public' ? route('snippet-collections.public.show', $this->uuid) : null,
            'workspace' => [
                'slug' => (string) $workspace->slug,
                'name' => (string) $workspace->name,
            ],
            'url' => route('snippets.show', [$workspace->slug, $this->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
