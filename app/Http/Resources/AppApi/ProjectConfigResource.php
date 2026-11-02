<?php

namespace App\Http\Resources\AppApi;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an issue form and the board filters offer in a project (`GET /projects/{id}/config`, BRAI-407): the
 * same data as the MCP's get_project_config / the board page. The controller sets the relations
 * `configTypes`, `configPriorities`, `configLabels`, `configFields`, `configReleases`.
 *
 * @mixin Project
 */
class ProjectConfigResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        return [
            'project_id' => (int) $project->id,
            // the enabled types (an issue may keep a switched-off type it already has)
            'types' => ItemTypeResource::collection($project->getRelation('configTypes')),
            'priorities' => PriorityResource::collection($project->getRelation('configPriorities')),
            'labels' => LabelResource::collection($project->getRelation('configLabels')),
            'fields' => ItemFieldResource::collection($project->getRelation('configFields')),
            'releases' => ReleaseOptionResource::collection($project->getRelation('configReleases')),
            'can' => [
                // labels, types, priorities, fields, releases are set up on the web (project settings)
                'manage' => $request->user()->can('update', $project),
            ],
        ];
    }
}
