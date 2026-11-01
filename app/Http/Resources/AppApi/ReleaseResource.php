<?php

namespace App\Http\Resources\AppApi;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A release (fix version) of a project (BRAI-415), as the web's releases list: status, dates, progress of its issues.
 *
 * @mixin Release
 */
class ReleaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;
        $progress = $this->resource->progress();

        return [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'name' => (string) $this->name,
            /** @var 'unreleased'|'released'|'archived' */
            'status' => (string) $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'release_date' => $this->release_date?->toDateString(),
            'released_at' => $this->released_at?->toIso8601ZuluString(),
            // unreleased and past its release date
            'overdue' => $this->resource->isOverdue(),
            // its notes are on the public release page
            'published' => $this->resource->isPublished(),
            'progress' => [
                'total' => (int) $progress['total'],
                'done' => (int) $progress['done'],
                'in_progress' => (int) $progress['in_progress'],
                'todo' => (int) $progress['todo'],
            ],
            'url' => route('releases.show', [$project->workspace->slug, $project->slug, $this->id]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
