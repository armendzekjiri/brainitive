<?php

namespace App\Http\Resources\AppApi;

use App\Models\TaskList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task list (BRAI-397). Addressed by `id` in the App API (`/task-lists/{id}`): slugs are unique
 * only per project. Needs `project.workspace` and the counts of TaskLists::withCounts().
 *
 * @mixin TaskList
 */
class TaskListResource extends JsonResource
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
            'position' => (int) $this->position,
            // open and completed tasks
            'counts' => [
                'todo' => self::count($this->resource->getAttribute('todo_count')),
                'done' => self::count($this->resource->getAttribute('done_count')),
            ],
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'key' => $project->key,
            ],
            'workspace' => [
                'slug' => (string) $project->workspace->slug,
                'name' => (string) $project->workspace->name,
            ],
            'url' => route('task-lists.show', [$project->workspace->slug, $project->slug, $this->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    private static function count(mixed $value): int
    {
        return (int) $value;
    }
}
