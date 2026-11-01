<?php

namespace App\Http\Resources\AppApi;

use App\Models\Board;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board of a project (BRAI-403), in `GET /projects/{id}/boards`. Needs `project.workspace`.
 *
 * @mixin Board
 */
class BoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            // kanban | scrum | list
            'type' => (string) $this->type,
            'position' => (int) $this->position,
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'key' => $project->key,
            ],
            'workspace' => [
                'slug' => (string) $project->workspace->slug,
                'name' => (string) $project->workspace->name,
            ],
            'url' => route('boards.show', [$project->workspace->slug, $project->slug, $this->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
