<?php

namespace App\Providers;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;
use App\Policies\BoardPolicy;
use App\Policies\BookmarkPolicy;
use App\Policies\ItemPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Register policies
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Board::class, BoardPolicy::class);
        Gate::policy(Bookmark::class, BookmarkPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);

        // Instance admins bypass all permission checks
        Gate::before(function ($user, $ability) {
            if ($user->isInstanceAdmin()) {
                return true;
            }
        });

        // Resolve page from project's allPages() so child pages (e.g. documents inside folders) are found
        Route::bind('page', function (string $value, $route) {
            $project = $route->parameter('project');
            if ($project instanceof Project) {
                return $project->allPages()->where('uuid', $value)->firstOrFail();
            }
            return Page::where('uuid', $value)->firstOrFail();
        });

        // Resolve bookmark from workspace (slug) when in workspace context
        Route::bind('bookmark', function (string $value, $route) {
            $workspace = $route->parameter('workspace');
            if ($workspace instanceof Workspace) {
                return $workspace->bookmarks()->where('slug', $value)->firstOrFail();
            }
            return Bookmark::where('slug', $value)->firstOrFail();
        });
    }
}
