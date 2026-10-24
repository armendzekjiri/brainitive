<?php

namespace App\Support\Insights;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SQL building blocks shared by the Insights tabs (BRAI-105). The definitions follow
 * `HealthMetrics` (project health), so both pages count the same way:
 * - issue = a non-deleted issue or subtask on a non-deleted board of a project in scope;
 * - done = `completed_at` set or in a done column; open = not done;
 * - overdue = open with a due date before the viewer's today.
 */
final class InsightsQueries
{
    /** `items` alias `items`, joined with its live board and (left) its column `ic`. */
    public static function issues(InsightsFilters $filters): Builder
    {
        return DB::table('items')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->leftJoin('board_columns as ic', 'ic.id', '=', 'items.column_id')
            ->whereNull('items.deleted_at')
            ->whereNull('boards.deleted_at')
            ->whereIn('boards.project_id', $filters->projectIds());
    }

    public static function doneSql(): string
    {
        return '(items.completed_at is not null or ic.is_done_column = true)';
    }

    public static function openSql(): string
    {
        return '(items.completed_at is null and (ic.is_done_column is null or ic.is_done_column = false))';
    }

    /**
     * The local calendar date of a UTC timestamp column, in the viewer's time zone (Postgres; other
     * drivers use the UTC date).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function localDate(string $column, string $timezone): array
    {
        if (DB::getDriverName() === 'pgsql') {
            return ["cast(({$column} at time zone 'UTC') at time zone ? as date)", [$timezone]];
        }

        return ["date({$column})", []];
    }

    /**
     * Median of `$end - $start` in hours over the rows of `$query` (one query). Postgres computes it
     * with `percentile_cont`; other drivers read the two timestamps and take the median in PHP.
     */
    public static function medianHours(Builder $query, string $start, string $end): ?float
    {
        if (DB::getDriverName() === 'pgsql') {
            $value = (clone $query)->selectRaw("percentile_cont(0.5) within group (order by extract(epoch from ({$end} - {$start}))) as median")->value('median');

            return $value === null ? null : round(max(0, (float) $value) / 3600, 1);
        }

        $seconds = (clone $query)->get([DB::raw("{$start} as started"), DB::raw("{$end} as ended")])
            ->map(fn (object $row) => max(0, CarbonImmutable::parse($row->ended)->getTimestamp() - CarbonImmutable::parse($row->started)->getTimestamp()))
            ->sort()->values()->all();

        $count = count($seconds);
        if ($count === 0) {
            return null;
        }
        $mid = intdiv($count, 2);
        $median = $count % 2 ? $seconds[$mid] : ($seconds[$mid - 1] + $seconds[$mid]) / 2;

        return round($median / 3600, 1);
    }

    /** Minutes → hours, two decimal places. */
    public static function hours(int|float|string|null $minutes): float
    {
        return round(((float) $minutes) / 60, 2);
    }

    /** A `Y-m-d` (or timestamp) value from the database, or null. */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->toIso8601String();
    }
}
