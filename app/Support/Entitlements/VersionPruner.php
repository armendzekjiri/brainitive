<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes old doc / Gantt plan versions per the workspace's VersionRetention (versions:prune,
 * Cloud plans v1.46.0). Per page / plan the newest `keepLatest` versions stay forever (always at
 * least the newest one), older ones go once they are older than `keepDays` days. Never pruned:
 * the version the page / plan is at (`current_version`) and a page version shared with a client
 * portal (`client_deliverables.page_version_id`). Restores copy the old version into a new one, so
 * nothing else points at a version. Whether a workspace is pruned at all (enforced, grace) is the
 * command's call.
 */
final class VersionPruner
{
    private const CHUNK = 500;

    /** @return array{pages: int, plans: int} versions deleted (or, dry run, that would be) */
    public function prune(Workspace $workspace, VersionRetention $retention, bool $dryRun = false): array
    {
        if ($retention->keepsEverything()) {
            return ['pages' => 0, 'plans' => 0];
        }

        $pages = $this->prunable('page_versions', 'page_id', 'pages', $workspace, $retention)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('client_deliverables')
                ->whereColumn('client_deliverables.page_version_id', 'ranked.id'));
        $plans = $this->prunable('plan_versions', 'plan_id', 'plans', $workspace, $retention);

        return [
            'pages' => $dryRun ? $pages->count() : $this->deleteInChunks($pages, 'page_versions'),
            'plans' => $dryRun ? $plans->count() : $this->deleteInChunks($plans, 'plan_versions'),
        ];
    }

    /** Ids (as `ranked.id`) of the workspace's versions past the retention. */
    private function prunable(string $table, string $foreignKey, string $parentTable, Workspace $workspace, VersionRetention $retention): Builder
    {
        // recency 1 = the newest version of its page / plan
        $ranked = DB::table("{$table} as v")
            ->join("{$parentTable} as o", 'o.id', '=', "v.{$foreignKey}")
            ->join('projects as p', 'p.id', '=', 'o.project_id')
            ->where('p.workspace_id', $workspace->id)
            ->selectRaw("v.id, v.version, v.created_at, o.current_version, row_number() over (partition by v.{$foreignKey} order by v.version desc, v.id desc) as recency");

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('ranked.recency', '>', max(1, $retention->keepLatest))
            ->where('ranked.created_at', '<', now()->subDays((int) $retention->keepDays))
            ->whereColumn('ranked.version', '!=', 'ranked.current_version')
            ->select('ranked.id');
    }

    private function deleteInChunks(Builder $prunable, string $table): int
    {
        $deleted = 0;
        do {
            $ids = (clone $prunable)->orderBy('ranked.id')->limit(self::CHUNK)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += DB::table($table)->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
