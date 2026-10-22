<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\InstanceSettings;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instance health at a glance — counts only, never content.
 */
class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        // Content counts go through Eloquent so soft-deleted rows are left out.
        $count = fn (string $table) => Schema::hasTable($table) ? DB::table($table)->count() : 0;

        return Inertia::render('Admin/Overview', [
            'stats' => [
                'users' => DB::table('users')->count(),
                'users_active_30d' => DB::table('users')->where('last_login_at', '>=', now()->subDays(30))->count(),
                'users_new_30d' => DB::table('users')->where('created_at', '>=', now()->subDays(30))->count(),
                'users_suspended' => DB::table('users')->whereNotNull('suspended_at')->count(),
                'users_unverified' => DB::table('users')->whereNull('email_verified_at')->count(),
                'instance_admins' => $count('instance_admins'),
                'workspaces' => Workspace::count(),
                'projects' => Project::count(),
                'pages' => Page::count(),
                'boards' => Board::count(),
                'items' => Item::count(),
                'helpdesks' => Helpdesk::count(),
                'tickets' => Ticket::count(),
            ],
            'system' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database' => config('database.default'),
                'database_size' => Cache::remember('admin.database_size', now()->addMinutes(10), fn () => $this->databaseSize()),
                'storage_size' => Cache::remember('admin.storage_size', now()->addMinutes(10), fn () => $this->storageSize()),
                'queue' => config('queue.default'),
                'jobs_pending' => config('queue.default') === 'database' ? $count('jobs') : null,
                'jobs_failed' => $count('failed_jobs'),
                'mail' => InstanceSettings::mailSettings() ? 'smtp (admin settings)' : config('mail.default').' (.env)',
                'registration_open' => InstanceSettings::allowsRegistration(),
                'email_verification' => InstanceSettings::requiresEmailVerification(),
            ],
        ]);
    }

    /** Bytes used by the database, or null when the driver doesn't say. */
    private function databaseSize(): ?int
    {
        try {
            return match (DB::getDriverName()) {
                'pgsql' => (int) DB::selectOne('select pg_database_size(current_database()) as size')->size,
                'mysql', 'mariadb' => (int) DB::selectOne('select sum(data_length + index_length) as size from information_schema.tables where table_schema = database()')->size,
                'sqlite' => is_file($path = (string) config('database.connections.sqlite.database')) ? filesize($path) : null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** Bytes of uploaded files on the public disk. */
    private function storageSize(): int
    {
        $root = storage_path('app/public');

        if (! is_dir($root)) {
            return 0;
        }

        return collect(File::allFiles($root))->sum(fn ($file) => $file->getSize());
    }
}
