<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\VersionPruner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Deletes doc / Gantt plan versions older than the workspace's plan keeps them (Cloud plans
 * v1.46.0, nightly). Nothing is deleted while limits are not enforced (self-hosted, before
 * launch) or while a workspace is in its grace period after a downgrade. Rules in VersionPruner.
 */
class PruneVersions extends Command
{
    protected $signature = 'versions:prune {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete doc and plan versions past the version history of the workspace\'s plan';

    public function handle(Entitlements $entitlements, VersionPruner $pruner): int
    {
        if (! $entitlements->enforced()) {
            $this->info('Plan limits are not enforced: nothing is pruned.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['pages' => 0, 'plans' => 0];
        $failed = 0;

        Workspace::query()->chunkById(100, function (Collection $workspaces) use ($entitlements, $pruner, $dryRun, &$totals, &$failed) {
            foreach ($workspaces as $workspace) {
                $limits = $entitlements->limits($workspace);
                if ($limits->versions->keepsEverything() || $limits->inGrace()) {
                    continue;
                }
                try {
                    $pruned = $pruner->prune($workspace, $limits->versions, $dryRun);
                } catch (\Throwable $e) {
                    // one broken workspace must not stop the others
                    report($e);
                    $failed++;

                    continue;
                }
                $totals['pages'] += $pruned['pages'];
                $totals['plans'] += $pruned['plans'];
                if ($dryRun && ($pruned['pages'] || $pruned['plans'])) {
                    $this->line("{$workspace->slug}: {$pruned['pages']} doc version(s), {$pruned['plans']} plan version(s)");
                }
            }
        });

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$totals['pages']} doc version(s) and {$totals['plans']} plan version(s).".($failed ? " {$failed} workspace(s) failed." : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
