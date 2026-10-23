<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove everything the e2e suite created on a long-lived server (dev.brainitive.app runs the
 * journeys after every deploy, BRAI-96): all @example.test users and — through the database's
 * cascades — their workspaces, projects, issues, comments and sessions. E2eSeeder recreates the
 * two test users afterwards. Real people's data is never touched: refuses in production, and
 * refuses when a test user belongs to a workspace a real person owns.
 */
class E2ePrune extends Command
{
    public const TEST_EMAIL = '%@example.test';

    protected $signature = 'e2e:prune {--force : required, this deletes data}';

    protected $description = 'Delete all e2e test users (@example.test) and what they created (staging / e2e only)';

    public function handle(): int
    {
        if (app()->isProduction() || ! app()->environment(['staging', 'e2e', 'testing'])) {
            $this->error('Refusing: e2e:prune only runs on staging (dev.brainitive.app) or e2e.');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->error('Refusing without --force.');

            return self::FAILURE;
        }

        $ids = User::where('email', 'like', self::TEST_EMAIL)->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('No e2e test users.');

            return self::SUCCESS;
        }

        // a test user inside a real person's workspace: deleting it could take shared work with it
        $shared = DB::table('workspace_user')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->join('users as owners', 'owners.id', '=', 'workspaces.owner_id')
            ->whereIn('workspace_user.user_id', $ids)
            ->where('owners.email', 'not like', self::TEST_EMAIL)
            ->pluck('workspaces.name');
        if ($shared->isNotEmpty()) {
            $this->error('Refusing: test users are members of real workspaces ('.$shared->unique()->join(', ').'). Remove them there first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($ids) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->delete();
            // hard delete: the foreign keys cascade to their workspaces, projects, issues, comments …
            DB::table('users')->whereIn('id', $ids)->delete();
        });

        $this->info("Removed {$ids->count()} e2e test users and their data.");

        return self::SUCCESS;
    }
}
