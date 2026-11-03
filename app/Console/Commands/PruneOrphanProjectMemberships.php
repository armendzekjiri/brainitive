<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Codex review (v1.49.0), finding 6: before the fix, removing someone from a workspace left their project
 * memberships behind. Access no longer follows them (User::projectRole / hasProjectAccess need the workspace
 * membership), and Workspace::removeUser drops both now; this cleans up the rows left from before. Project members
 * are always workspace members — there is no project-only role — so every such row is an orphan.
 *
 * Dry run by default (lists them); `--force` deletes them. Run once after deploying v1.49.0.
 */
class PruneOrphanProjectMemberships extends Command
{
    protected $signature = 'memberships:prune-orphans {--force : delete them (without it: only report)}';

    protected $description = 'Report (or with --force remove) project memberships of people who are no longer in the project\'s workspace';

    public function handle(): int
    {
        $rows = $this->orphans()
            ->join('users', 'users.id', '=', 'pu.user_id')
            ->join('workspaces', 'workspaces.id', '=', 'p.workspace_id')
            ->orderBy('workspaces.slug')->orderBy('p.slug')->orderBy('users.email')
            ->get(['pu.id', 'users.email', 'workspaces.slug as workspace', 'p.slug as project', 'pu.role']);

        if ($rows->isEmpty()) {
            $this->info('No orphaned project memberships.');

            return self::SUCCESS;
        }

        $this->table(['workspace', 'project', 'user', 'role'], $rows->map(fn ($r) => [$r->workspace, $r->project, $r->email, $r->role])->all());

        if (! $this->option('force')) {
            $this->warn("{$rows->count()} orphaned project membership(s). Dry run: nothing changed. Run again with --force to remove them.");

            return self::SUCCESS;
        }

        $deleted = DB::table('project_user')->whereIn('id', $this->orphans()->select('pu.id'))->delete();
        $this->info("Removed {$deleted} orphaned project membership(s).");

        return self::SUCCESS;
    }

    /** project_user rows whose user isn't a member of the project's workspace (trashed projects included). */
    private function orphans(): Builder
    {
        return DB::table('project_user as pu')
            ->join('projects as p', 'p.id', '=', 'pu.project_id')
            ->whereNotExists(fn (Builder $q) => $q->from('workspace_user as wu')
                ->whereColumn('wu.workspace_id', 'p.workspace_id')
                ->whereColumn('wu.user_id', 'pu.user_id'));
    }
}
