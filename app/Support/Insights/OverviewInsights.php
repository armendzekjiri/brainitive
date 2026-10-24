<?php

namespace App\Support\Insights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Insights → Overview (BRAI-105). Definitions (also shown in the UI):
 * - created / completed = issues whose `created_at` / `completed_at` falls in the range;
 * - completion rate = share of the issues created in the range that are done now;
 * - median cycle time = median of `completed_at − created_at` over the issues completed in the range;
 * - overdue now = open issues due before today (not range-bound);
 * - hours logged = time entries with `spent_on` in the range.
 *
 * Seven queries, whatever the workspace size.
 */
final class OverviewInsights
{
    public const TYPES_LIMIT = 8;

    /** @return array<string, mixed> */
    public static function compute(InsightsFilters $filters): array
    {
        $start = $filters->startUtc();
        $end = $filters->endUtc();
        $today = $filters->today();
        $done = InsightsQueries::doneSql();
        $open = InsightsQueries::openSql();

        $kpi = InsightsQueries::issues($filters)->selectRaw(implode(', ', [
            'sum(case when items.created_at >= ? and items.created_at < ? then 1 else 0 end) as created',
            "sum(case when items.created_at >= ? and items.created_at < ? and {$done} then 1 else 0 end) as created_done",
            'sum(case when items.completed_at >= ? and items.completed_at < ? then 1 else 0 end) as completed',
            "sum(case when {$open} then 1 else 0 end) as open",
            "sum(case when {$open} and items.due_date < ? then 1 else 0 end) as overdue",
        ]), [$start, $end, $start, $end, $start, $end, $today])->first();

        $created = (int) ($kpi->created ?? 0);
        $cycleHours = InsightsQueries::medianHours(
            InsightsQueries::issues($filters)->where('items.completed_at', '>=', $start)->where('items.completed_at', '<', $end),
            'items.created_at', 'items.completed_at',
        );

        $minutes = DB::table('time_entries')
            ->where('workspace_id', $filters->workspace->id)
            ->whereIn('project_id', $filters->projectIds())
            ->whereBetween('spent_on', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->sum('minutes');

        return [
            'kpis' => [
                'created' => $created,
                'completed' => (int) ($kpi->completed ?? 0),
                'completion_rate' => $created > 0 ? (int) round(((int) $kpi->created_done) / $created * 100) : null,
                'median_cycle_days' => $cycleHours === null ? null : round($cycleHours / 24, 1),
                'open' => (int) ($kpi->open ?? 0),
                'overdue' => (int) ($kpi->overdue ?? 0),
                'hours' => InsightsQueries::hours($minutes),
            ],
            'weeks' => self::weeks($filters),
            'projects' => self::byProject($filters),
            'types' => self::types($filters),
        ];
    }

    /**
     * Created vs completed per week (weeks start on Monday, in the viewer's time zone). Two queries.
     *
     * @return list<array{week: string, created: int, completed: int}>
     */
    private static function weeks(InsightsFilters $filters): array
    {
        $perDay = function (string $column) use ($filters): array {
            [$day, $bindings] = InsightsQueries::localDate("items.{$column}", $filters->timezone);

            return InsightsQueries::issues($filters)
                ->where("items.{$column}", '>=', $filters->startUtc())
                ->where("items.{$column}", '<', $filters->endUtc())
                ->selectRaw("{$day} as day, count(*) as total", $bindings)
                ->groupByRaw('1') // by position: Postgres sees two `?` as different expressions
                ->pluck('total', 'day')
                ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total])
                ->all();
        };
        $created = $perDay('created_at');
        $completed = $perDay('completed_at');

        $weeks = [];
        for ($week = $filters->from->startOfWeek(CarbonImmutable::MONDAY); $week->lte($filters->to); $week = $week->addWeek()) {
            $weeks[$week->toDateString()] = ['week' => $week->toDateString(), 'created' => 0, 'completed' => 0];
        }
        foreach (['created' => $created, 'completed' => $completed] as $series => $days) {
            foreach ($days as $day => $total) {
                $week = CarbonImmutable::createFromFormat('!Y-m-d', $day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                if (isset($weeks[$week])) {
                    $weeks[$week][$series] += $total;
                }
            }
        }

        return array_values($weeks);
    }

    /**
     * Open and overdue issues per project, most open first (projects without open issues are left out).
     *
     * @return list<array{id: int, name: string, slug: string, color: string|null, open: int, overdue: int}>
     */
    private static function byProject(InsightsFilters $filters): array
    {
        $open = InsightsQueries::openSql();
        $rows = InsightsQueries::issues($filters)
            ->whereRaw($open)
            ->selectRaw('boards.project_id, count(*) as open, sum(case when items.due_date < ? then 1 else 0 end) as overdue', [$filters->today()])
            ->groupBy('boards.project_id')
            ->get();

        return $rows->map(fn (object $row) => [
            'id' => (int) $row->project_id,
            'name' => $filters->projects[(int) $row->project_id]['name'] ?? '',
            'slug' => $filters->projects[(int) $row->project_id]['slug'] ?? '',
            'color' => $filters->projects[(int) $row->project_id]['color'] ?? null,
            'open' => (int) $row->open,
            'overdue' => (int) $row->overdue,
        ])->sortBy([['open', 'desc'], ['overdue', 'desc'], ['name', 'asc']])->values()->all();
    }

    /**
     * Issue types (by name, across projects) of the issues created in the range, most first.
     *
     * @return list<array{name: string|null, created: int, completed: int}>
     */
    private static function types(InsightsFilters $filters): array
    {
        $start = $filters->startUtc();
        $end = $filters->endUtc();

        return InsightsQueries::issues($filters)
            ->leftJoin('item_types', 'item_types.id', '=', 'items.item_type_id')
            ->where('items.created_at', '>=', $start)->where('items.created_at', '<', $end)
            ->selectRaw('item_types.name as name, count(*) as created, sum(case when '.InsightsQueries::doneSql().' then 1 else 0 end) as completed')
            ->groupBy('item_types.name')
            ->orderByDesc('created')->orderBy('item_types.name')
            ->limit(self::TYPES_LIMIT)
            ->get()
            ->map(fn (object $row) => ['name' => $row->name, 'created' => (int) $row->created, 'completed' => (int) $row->completed])
            ->all();
    }
}
