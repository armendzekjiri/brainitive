<?php

namespace App\Support\Insights;

use Illuminate\Support\Facades\DB;

/**
 * Insights → Helpdesk & leads (BRAI-105). Definitions:
 * - tickets created / resolved = tickets whose `created_at` / `completed_at` falls in the range;
 * - median resolution time = median of `completed_at − created_at` over the tickets resolved in the range;
 * - open = tickets not resolved now (not range-bound);
 * - leads = leads received in the range; closed = of those, the ones in a done column now;
 * - funnel = unique visit sessions that viewed / started / submitted the form in the range
 *   (`lead_form_events`, kept for 90 days);
 * - SLA (BRAI-125): breaches = tickets whose first missed target was due in the range (`sla_breached_at`);
 *   met % = of the tickets with an SLA resolved in the range, the share that missed no target.
 *
 * Six queries, whatever the number of helpdesks and forms.
 */
final class HelpdeskInsights
{
    /** @return array<string, mixed> */
    public static function compute(InsightsFilters $filters): array
    {
        $start = $filters->startUtc();
        $end = $filters->endUtc();
        $ids = $filters->projectIds();
        $workspace = $filters->workspace->slug;

        $helpdesks = DB::table('helpdesks')->whereIn('project_id', $ids)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'slug', 'project_id']);

        $tickets = fn () => DB::table('tickets')
            ->join('helpdesks', 'helpdesks.id', '=', 'tickets.helpdesk_id')
            ->whereIn('helpdesks.project_id', $ids)
            ->whereNull('helpdesks.deleted_at')
            ->whereNull('tickets.deleted_at');

        $hasSla = '(tickets.sla_first_response_due_at is not null or tickets.sla_resolution_due_at is not null)';
        $perHelpdesk = $tickets()
            ->selectRaw(implode(', ', [
                'tickets.helpdesk_id',
                'sum(case when tickets.created_at >= ? and tickets.created_at < ? then 1 else 0 end) as created',
                'sum(case when tickets.completed_at >= ? and tickets.completed_at < ? then 1 else 0 end) as resolved',
                'sum(case when tickets.completed_at is null then 1 else 0 end) as open',
                'sum(case when tickets.sla_breached_at >= ? and tickets.sla_breached_at < ? then 1 else 0 end) as sla_breaches',
                "sum(case when tickets.completed_at >= ? and tickets.completed_at < ? and {$hasSla} then 1 else 0 end) as sla_resolved",
                "sum(case when tickets.completed_at >= ? and tickets.completed_at < ? and {$hasSla} and tickets.sla_breached_at is null then 1 else 0 end) as sla_met",
            ]), [$start, $end, $start, $end, $start, $end, $start, $end, $start, $end])
            ->groupBy('tickets.helpdesk_id')
            ->get()->keyBy('helpdesk_id');

        $resolutionHours = InsightsQueries::medianHours(
            $tickets()->where('tickets.completed_at', '>=', $start)->where('tickets.completed_at', '<', $end),
            'tickets.created_at', 'tickets.completed_at',
        );

        $helpdeskRows = $helpdesks->map(function (object $helpdesk) use ($filters, $perHelpdesk, $workspace) {
            $row = $perHelpdesk->get($helpdesk->id);

            return [
                'id' => (int) $helpdesk->id,
                'name' => (string) $helpdesk->name,
                'project' => $filters->projects[(int) $helpdesk->project_id]['name'] ?? '',
                'created' => (int) ($row->created ?? 0),
                'resolved' => (int) ($row->resolved ?? 0),
                'open' => (int) ($row->open ?? 0),
                'sla_breaches' => (int) ($row->sla_breaches ?? 0),
                'sla_resolved' => (int) ($row->sla_resolved ?? 0),
                'sla_met' => (int) ($row->sla_met ?? 0),
                'sla_met_percent' => self::percent((int) ($row->sla_met ?? 0), (int) ($row->sla_resolved ?? 0)),
                'url' => route('helpdesks.show', [$workspace, $filters->projects[(int) $helpdesk->project_id]['slug'] ?? '', $helpdesk->slug]),
            ];
        })->values()->all();

        $forms = DB::table('lead_forms')->whereIn('project_id', $ids)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'slug', 'project_id', 'status']);
        $formIds = $forms->pluck('id')->all();

        $leads = DB::table('leads')
            ->leftJoin('lead_form_columns', 'lead_form_columns.id', '=', 'leads.column_id')
            ->whereIn('leads.lead_form_id', $formIds)
            ->whereNull('leads.deleted_at')
            ->where('leads.created_at', '>=', $start)->where('leads.created_at', '<', $end)
            ->groupBy('leads.lead_form_id')
            ->selectRaw('leads.lead_form_id, count(*) as total, sum(case when lead_form_columns.is_done = true then 1 else 0 end) as closed')
            ->get()->keyBy('lead_form_id');

        $funnel = [];
        DB::table('lead_form_events')
            ->whereIn('lead_form_id', $formIds)
            ->whereIn('type', ['view', 'start', 'submit'])
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->groupBy('lead_form_id', 'type')
            ->selectRaw('lead_form_id, type, count(distinct session_id) as sessions')
            ->get()
            ->each(function (object $row) use (&$funnel) {
                $funnel[(int) $row->lead_form_id][$row->type] = (int) $row->sessions;
            });

        $formRows = $forms->map(function (object $form) use ($filters, $leads, $funnel, $workspace) {
            $views = $funnel[$form->id]['view'] ?? 0;
            $submits = $funnel[$form->id]['submit'] ?? 0;

            return [
                'id' => (int) $form->id,
                'name' => (string) $form->name,
                'project' => $filters->projects[(int) $form->project_id]['name'] ?? '',
                'status' => (string) $form->status,
                'leads' => (int) ($leads->get($form->id)->total ?? 0),
                'closed' => (int) ($leads->get($form->id)->closed ?? 0),
                'views' => $views,
                'starts' => $funnel[$form->id]['start'] ?? 0,
                'submits' => $submits,
                'conversion' => $views > 0 ? round($submits / $views * 100, 1) : null,
                'url' => route('lead-forms.show', [$workspace, $filters->projects[(int) $form->project_id]['slug'] ?? '', $form->slug]),
            ];
        })->values()->all();

        return [
            'tickets' => [
                'created' => array_sum(array_column($helpdeskRows, 'created')),
                'resolved' => array_sum(array_column($helpdeskRows, 'resolved')),
                'open' => array_sum(array_column($helpdeskRows, 'open')),
                'median_resolution_hours' => $resolutionHours,
            ],
            'sla' => [
                'breaches' => array_sum(array_column($helpdeskRows, 'sla_breaches')),
                'resolved' => $slaResolved = array_sum(array_column($helpdeskRows, 'sla_resolved')),
                'met' => $slaMet = array_sum(array_column($helpdeskRows, 'sla_met')),
                'met_percent' => self::percent($slaMet, $slaResolved),
            ],
            'helpdesks' => $helpdeskRows,
            'leads' => [
                'total' => array_sum(array_column($formRows, 'leads')),
                'views' => array_sum(array_column($formRows, 'views')),
                'starts' => array_sum(array_column($formRows, 'starts')),
                'submits' => array_sum(array_column($formRows, 'submits')),
            ],
            'forms' => $formRows,
        ];
    }

    private static function percent(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}
