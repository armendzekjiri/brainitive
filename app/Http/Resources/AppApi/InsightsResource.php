<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tab of the workspace admins' Insights (the web's numbers, App\Support\Insights\*Insights::compute, which
 * list their fields themselves: aggregates, member names / avatars, never addresses). `insights` per tab:
 * overview {kpis, weeks, projects, types}, team {members, …}, projects {projects, …}, helpdesk {helpdesks, lead_forms,
 * …}, usage {members, roles, pending_invites, projects, storage, storage_bytes, webhooks, …}.
 *
 * @property-read array{tab: string, filters: array{period: string, from: string, to: string, project: int|null, timezone: string}, projects: list<array{id: int, name: string}>, insights: array<string, mixed>} $resource
 */
class InsightsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            // overview | team | projects | helpdesk | usage
            'tab' => (string) $r['tab'],
            // what was computed: period, from / to (local days), project, timezone
            'filters' => [
                'period' => (string) $r['filters']['period'],
                'from' => (string) $r['filters']['from'],
                'to' => (string) $r['filters']['to'],
                'project' => $r['filters']['project'],
                'timezone' => (string) $r['filters']['timezone'],
            ],
            // the projects the `project` filter can pick
            'projects' => $r['projects'],
            // the tab's numbers, as the web page gets them
            'insights' => $r['insights'],
        ];
    }
}
