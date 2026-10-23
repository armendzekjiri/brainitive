<?php

namespace App\Providers;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\InstanceSettings;
use App\Models\Item;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Release;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Policies\BoardPolicy;
use App\Policies\BookmarkPolicy;
use App\Policies\ItemPolicy;
use App\Policies\LeadFormPolicy;
use App\Policies\PagePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectTemplatePolicy;
use App\Policies\ReleasePolicy;
use App\Policies\SnippetPolicy;
use App\Policies\WorkspacePolicy;
use App\Support\Admin\InstanceMailConfig;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMTP saved in the admin console overrides .env mail config (see InstanceMailConfig).
        $this->app->afterResolving('mail.manager', fn () => InstanceMailConfig::apply());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        InstanceSettings::flushCache();

        // Reset links carry a secret token: build them from APP_URL, never from the request's
        // Host / X-Forwarded-Host (host-header poisoning would send the token elsewhere).
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) (config('app.url') ?: url('/')), '/')
            .route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));

        Vite::prefetch(concurrency: 3);

        // OAuth 2.1 (Passport) — powers the remote MCP server connect flow for
        // ChatGPT / Claude. The consent screen shown during "Add connector" is
        // our branded view; tokens are long-lived so connectors stay linked.
        Passport::authorizationView(fn ($parameters) => view('mcp.authorize', $parameters));
        Passport::tokensExpireIn(now()->addDays(30));
        Passport::refreshTokensExpireIn(now()->addDays(60));
        Passport::personalAccessTokensExpireIn(now()->addYear());
        // `capture` is the browser extension's scope: it only reaches /api/ext/v1, never
        // /mcp or the CLI API (RejectCaptureTokens).
        Passport::tokensCan([
            'mcp:use' => 'Use MCP server',
            'capture' => 'Save pages, links, notes and screenshots to your workspaces and search them',
        ]);

        // Queue workers are long-lived: re-read instance settings (mail, verification) per job.
        Queue::before(function () {
            InstanceSettings::flushCache();

            if ($this->app->resolved('mail.manager')) {
                InstanceMailConfig::apply();
            }
        });

        // Joining invited workspaces needs a proven address (the link in the verification mail)
        Event::listen(Verified::class, function (Verified $event) {
            if ($event->user instanceof User) {
                WorkspaceInvitation::acceptPendingForUser($event->user);
            }
        });

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                User::whereKey($event->user->getAuthIdentifier())->toBase()->update(['last_login_at' => now()]);
            }
        });

        // Register policies
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Board::class, BoardPolicy::class);
        Gate::policy(Release::class, ReleasePolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Bookmark::class, BookmarkPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(SnippetCollection::class, SnippetPolicy::class);
        Gate::policy(ProjectTemplate::class, ProjectTemplatePolicy::class);
        Gate::policy(LeadForm::class, LeadFormPolicy::class);

        // Resolve page from project's allPages() so child pages (e.g. documents inside folders) are found
        // Explicit binders run BEFORE implicit binding, so {workspace} / {project} are still raw
        // slugs here: resolve them ourselves. A page only binds inside the URL's own project —
        // otherwise policies would check the page's project while the controller trusts the URL's.
        Route::bind('page', function (string $value, $route) {
            $project = self::routeProject($route);
            if ($project === false) {
                abort(404);
            }

            return $project
                ? $project->allPages()->where('uuid', $value)->firstOrFail()
                : Page::where('uuid', $value)->firstOrFail();
        });

        // Resolve bookmark from workspace (slug) when in workspace context
        Route::bind('bookmark', function (string $value, $route) {
            $workspace = self::routeWorkspace($route);
            if ($workspace === false) {
                abort(404);
            }

            return $workspace
                ? $workspace->bookmarks()->where('slug', $value)->firstOrFail()
                : Bookmark::where('slug', $value)->firstOrFail();
        });
    }

    /**
     * The {workspace} of a route (model or raw slug): null when the route has none, false when
     * the slug matches no workspace.
     */
    private static function routeWorkspace($route): Workspace|false|null
    {
        $workspace = $route->parameter('workspace');
        if ($workspace === null || $workspace instanceof Workspace) {
            return $workspace;
        }

        return Workspace::where('slug', (string) $workspace)->first() ?? false;
    }

    /**
     * The {project} of a route, scoped to its {workspace}: null when the route has none, false
     * when it doesn't resolve.
     */
    private static function routeProject($route): Project|false|null
    {
        $project = $route->parameter('project');
        if ($project === null || $project instanceof Project) {
            return $project;
        }
        $workspace = self::routeWorkspace($route);
        if ($workspace === false) {
            return false;
        }

        return Project::query()
            ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('slug', (string) $project)
            ->first() ?? false;
    }
}
