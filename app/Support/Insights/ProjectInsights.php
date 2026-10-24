<?php

namespace App\Support\Insights;

use Illuminate\Support\Facades\DB;

/**
 * Insights → Projects (BRAI-105): every project in scope. Definitions (as `HealthMetrics`):
 * - health = the status of the project's latest saved health report (chosen by a person; null = none yet);
 * - open / overdue = issues open now / open and due before today;
 * - done in range = issues with `completed_at` in the range;
 * - hours = time entries with `spent_on` in the range;
 * - last activity = the latest change of any of its issues (`items.updated_at`).
 *
 * Three queries for all projects together (no per-project HealthMetrics run).
 */
final class ProjectInsights
{
    /** @return array{projects: list<array<string, mixed>>} */
    public static function compute(InsightsFilters $filters): array
    {
        $start = $filters->startUtc();
        $end = $filters->endUtc();
        $ids = $filters->projectIds();
        $open = InsightsQueries::openSql();

        $issues = InsightsQueries::issues($filters)
            ->selectRaw(implode(', ', [
                'boards.project_id',
                'count(*) as total',
                "sum(case when {$open} then 1 else 0 end) as open",
                "sum(case when {$open} and items.due_date < ? then 1 else 0 end) as overdue",
                'sum(case when items.completed_at >= ? and items.completed_at < ? then 1 else 0 end) as done_in_range',
                'max(items.updated_at) as last_activity_at',
            ]), [$filters->today(), $start, $end])
            ->groupBy('boards.project_id')
            ->get()->keyBy('project_id');

        $minutes = DB::table('time_entries')
            ->where('workspace_id', $filters->workspace->id)
            ->whereIn('project_id', $ids)
            ->whereBetween('spent_on', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->groupBy('project_id')
            ->selectRaw('project_id, sum(minutes) as minutes')
            ->pluck('minutes', 'project_id');

        $health = DB::table('project_health_reports')
            ->whereIn('id', DB::table('project_health_reports')->whereIn('project_id', $ids)->whereNull('deleted_at')
                ->groupBy('project_id')->selectRaw('max(id)'))
            ->get(['project_id', 'status', 'created_at'])
            ->keyBy('project_id');

        $workspace = $filters->workspace->slug;
        $rows = [];
        foreach ($ids as $id) {
            $project = $filters->projects[$id];
            $row = $issues->get($id);
            $report = $health->get($id);
            $rows[] = [
                'id' => $id,
                'name' => $project['name'],
                'key' => $project['key'],
                'color' => $project['color'],
                'health' => $report?->status,
                'health_at' => InsightsQueries::iso($report?->created_at),
                'issues' => (int) ($row->total ?? 0),
                'open' => (int) ($row->open ?? 0),
                'overdue' => (int) ($row->overdue ?? 0),
                'done_in_range' => (int) ($row->done_in_range ?? 0),
                'hours' => InsightsQueries::hours($minutes[$id] ?? 0),
                'last_activity_at' => InsightsQueries::iso($row->last_activity_at ?? null),
                'url' => route('projects.show', [$workspace, $project['slug']]),
                'health_url' => route('projects.health', [$workspace, $project['slug']]),
            ];
        }

        return ['projects' => $rows];
    }
}
