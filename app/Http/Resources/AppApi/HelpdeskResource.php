<?php

namespace App\Http\Resources\AppApi;

use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A helpdesk of a project (the agent side): its columns (= ticket statuses) and open ticket count. Settings,
 * SLA rules, templates, widget, portal and mailbox: web only (`url`).
 *
 * @mixin Helpdesk
 */
class HelpdeskResource extends JsonResource
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
        ];
    }
}
