<?php

namespace App\Http\Resources\AppApi;

use App\Models\Snippet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A snippet (BRAI-399): the code / text verbatim in `content`, for "copy snippet". Needs
 * `collection.workspace`.
 *
 * @mixin Snippet
 */
class SnippetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $collection = $this->resource->collection;
        $workspace = $collection->workspace;
        $visibility = $this->resource->resolvedVisibility();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'collection_id' => (int) $this->collection_id,
            'title' => $this->title,
            'description' => $this->description,
            // highlight.js name: plaintext, php, typescript, bash …
            'language' => $this->language,
            'content' => $this->content,
            // inherit | private | public
            'visibility' => $this->visibility,
            // what inherit resolves to: private | public
            'effective_visibility' => $visibility,
            'share_url' => $visibility === 'public' ? route('snippets.public.show', $this->uuid) : null,
            'position' => (int) $this->position,
            'url' => route('snippets.show', [$workspace->slug, $collection->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
