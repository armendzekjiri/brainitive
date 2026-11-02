<?php

namespace App\Http\Resources\AppApi;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project the user can open (directory, "Go to project", capture destinations). Addressed by its
 * `id` in the App API (`/projects/{id}`): slugs are unique only per workspace and change on rename.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $workspace = $this->resource->workspace;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            // an emoji or the URL of an uploaded icon
            'icon' => $this->icon_display,
            'color' => $this->color,
            'workspace' => [
                'slug' => $workspace->slug,
                'name' => $workspace->name,
            ],
            'url' => route('projects.show', [$workspace->slug, $this->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
