<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\UnlimitedEntitlements;
use App\Support\Entitlements\WorkspaceUsageMeter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes members, projects, content and file bytes of every workspace to `workspace_usages`
 * (Cloud plans v1.46.0, nightly). LimitGate reads content / files from there. Self-hosted installs
 * (UnlimitedEntitlements) have nothing to measure, unless one workspace is asked for.
 */
class MeasureWorkspaceUsage extends Command
{
    protected $signature = 'usage:measure {--workspace= : Only this workspace (id or slug)}';

    protected $description = 'Measure the members, projects, content and file storage of workspaces';

    public function handle(Entitlements $entitlements, WorkspaceUsageMeter $meter): int
    {
        if ($only = $this->option('workspace')) {
            $workspace = Workspace::query()
                ->where('slug', $only)
                ->when(ctype_digit((string) $only), fn ($q) => $q->orWhere('id', (int) $only))
                ->first();
            if (! $workspace) {
                $this->error("Workspace {$only} not found.");

                return self::FAILURE;
            }
            $usage = $meter->measure($workspace);
            $this->info("{$workspace->slug}: {$usage->members} member(s), {$usage->projects} project(s), {$usage->content_bytes} content bytes, {$usage->file_bytes} file bytes.");

            return self::SUCCESS;
        }

        if ($entitlements instanceof UnlimitedEntitlements) {
            $this->info('Every workspace is unlimited: nothing to measure.');

            return self::SUCCESS;
        }

        $measured = 0;
        $failed = 0;
        Workspace::query()->chunkById(100, function (Collection $workspaces) use ($meter, &$measured, &$failed) {
            foreach ($workspaces as $workspace) {
                try {
                    $meter->measure($workspace);
                    $measured++;
                } catch (\Throwable $e) {
                    // one broken workspace must not stop the others
                    report($e);
                    $failed++;
                }
            }
        });

        $this->info("Measured {$measured} workspace(s).".($failed ? " {$failed} failed." : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
