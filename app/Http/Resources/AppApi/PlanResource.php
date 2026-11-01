<?php

namespace App\Http\Resources\AppApi;

use App\Models\Plan;
use App\Support\Plans\PlanReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Gantt plan of a project (BRAI-415): name, how many rows, the span of its bars.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;
        $range = PlanReader::range($this->resource);

        return [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'slug' => (string) $this->slug,
            'name' => (string) $this->name,
            'description' => $this->description,
            'rows_count' => count($this->data['rows'] ?? []),
            // the first bar's start and the last bar's end (Y-m-d); null without bars
            'start' => $range['start'] === null ? null : (string) $range['start'],
            'end' => $range['end'] === null ? null : (string) $range['end'],
            // bumped by every save of the plan
            'revision' => (int) $this->edit_revision,
            'url' => route('plans.show', [$project->workspace->slug, $project->slug, $this->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
