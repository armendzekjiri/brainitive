<?php

namespace App\Http\Resources\AppApi;

use App\Models\LeadForm;
use App\Models\LeadFormColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead form of a project, read-only: its pipeline columns with lead counts and its public link. The builder
 * (questions, logic, design, publishing, webhooks): web only (`url`).
 *
 * @mixin LeadForm
 */
class LeadFormResource extends JsonResource
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
            // draft | published
            'status' => (string) $this->status,
            // the public form (when published)
            'public_url' => $this->public_slug && $this->status === 'published' ? route('lead-forms.public', $this->public_slug) : null,
            'leads_count' => (int) $this->columns->sum('leads_count'),
            // the pipeline in board order
            'columns' => $this->columns->map(fn (LeadFormColumn $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'color' => $c->color,
                'is_done' => (bool) $c->is_done,
                'leads_count' => (int) ($c->leads_count ?? 0),
            ])->values()->all(),
            'url' => route('lead-forms.show', [$project->workspace->slug, $project->slug, $this->slug]),
            'can' => [
                // move leads, change contact details / assignee (project editors and admins)
                'manage_leads' => $request->user()->can('manageLeads', $this->resource),
            ],
        ];
    }
}
