<?php

namespace App\Http\Resources\AppApi;

use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\ItemPriority;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One helpdesk (HelpdeskResource's fields, listed again for the OpenAPI schema) + the project's priorities.
 * Expects the controller to set the `priorities` relation.
 *
 * @mixin Helpdesk
 */
class HelpdeskDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->project;

        return [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'slug' => (string) $this->slug,
            'name' => (string) $this->name,
            'description' => $this->description,
            // the statuses in board order; is_done = resolved
            'columns' => $this->columns->map(fn (HelpdeskColumn $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'color' => $c->color,
                'is_done' => (bool) $c->is_done_column,
            ])->values()->all(),
            // tickets not in a done column
            'open_count' => (int) ($this->open_tickets_count ?? 0),
            'url' => route('helpdesks.show', [$project->workspace->slug, $project->slug, $this->slug]),
            'can' => [
                // reply, change status / assignee / priority (project editors and admins)
                'manage_tickets' => $request->user()->can('createTickets', $this->resource),
            ],
            // the project's priorities, most important first (priority_id of PATCH /tickets/{id})
            'priorities' => $this->getRelation('priorities')->map(fn (ItemPriority $p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'color' => $p->color])->values()->all(),
        ];
    }
}
