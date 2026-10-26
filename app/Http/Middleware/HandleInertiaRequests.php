<?php

namespace App\Http\Middleware;

use App\Models\Board;
use App\Models\Capture;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkTimer;
use App\Support\GithubStars;
use App\Support\Live\LiveConfig;
use App\Support\Plans\PlanTemplates;
use App\Support\Sidebar\SidebarPreferences;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    private const LAST_WORKSPACE_SLUG_SESSION_KEY = 'last_sidebar_workspace_slug';

    private const LAST_PROJECT_ID_SESSION_KEY = 'last_sidebar_project_id';

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Compute onboarding props (welcome state + setup checklist progress).
     *
     * @return array<string, mixed>
     */
    private function onboardingProps(User $user): array
    {
        $state = $user->onboarding_state ?? [];
        $workspaces = $user->workspaces()->pluck('workspaces.id');

        $hasProject = $workspaces->isNotEmpty()
            ? Project::whereIn('workspace_id', $workspaces)->exists()
            : false;
        $hasBoard = $workspaces->isNotEmpty()
            ? Board::whereHas('project', fn ($q) => $q->whereIn('workspace_id', $workspaces))->exists()
            : false;
        $hasPage = $workspaces->isNotEmpty()
            ? Page::whereHas('project', fn ($q) => $q->whereIn('workspace_id', $workspaces))->exists()
            : false;
        $hasTeammate = $workspaces->isNotEmpty()
            ? \DB::table('workspace_user')
                ->whereIn('workspace_id', $workspaces)
                ->where('user_id', '!=', $user->id)
                ->exists()
            : false;

        return [
            'welcome_seen' => (bool) ($state['welcome_seen'] ?? false),
            'checklist_dismissed' => (bool) ($state['checklist_dismissed'] ?? false),
            'focus' => $state['focus'] ?? null,
            'checklist' => [
                'has_workspace' => $workspaces->isNotEmpty(),
                'has_project' => $hasProject,
                'has_board' => $hasBoard,
                'has_page' => $hasPage,
                'has_teammate' => $hasTeammate,
            ],
        ];
    }

    /**
     * Recursively load page children for sidebar tree (avoids N+1, supports Folder -> folder -> document etc.).
     *
     * @param  int  $depth  Remaining nesting levels (e.g. 7 allows 8 levels: root + 7 nested).
     */
    private static function pageTreeRecursive(User $user, int $depth = 7): \Closure
    {
        return function ($query) use ($user, $depth) {
            $query->visibleTo($user)->orderBy('type', 'desc')->orderBy('position');
            if ($depth > 0) {
                $query->with(['children' => self::pageTreeRecursive($user, $depth - 1)]);
            }
        };
    }

    private function workspaceSlugFromRoute(Request $request): ?string
    {
        $workspace = $request->route('workspace');

        if ($workspace instanceof Workspace) {
            return $workspace->slug;
        }

        return is_string($workspace) && $workspace !== '' ? $workspace : null;
    }

    private function shouldUseLastSidebarContext(Request $request): bool
    {
        return $request->routeIs(
            'workspaces.index',
            'my-work.index',
            'calendar.index',
            'notifications.page',
        );
    }

    private function sidebarWorkspace(string $workspaceSlug, User $user): ?Workspace
    {
        return Workspace::where('slug', $workspaceSlug)
            ->withCount('projects')
            ->with(['projects' => function ($query) use ($user) {
                $query->with([
                    'boards' => function ($q) {
                        $q->select('id', 'project_id', 'name', 'slug', 'type')
                            ->with(['columns' => function ($c) {
                                $c->select('id', 'board_id', 'name', 'color', 'position')
                                    ->orderBy('position');
                            }]);
                    },
                    'taskLists' => function ($q) {
                        $q->select('id', 'project_id', 'name', 'slug', 'position')
                            ->orderBy('position');
                    },
                    'plans' => function ($q) {
                        $q->select('id', 'project_id', 'name', 'slug', 'position')
                            ->orderBy('position');
                    },
                    'helpdesks' => function ($q) {
                        $q->select('id', 'project_id', 'name', 'slug', 'description', 'position')
                            ->orderBy('position');
                    },
                    'leadForms' => function ($q) {
                        $q->select('id', 'project_id', 'name', 'slug', 'status', 'position');
                    },
                    'pages' => function ($q) use ($user) {
                        $q->visibleTo($user)
                            ->with(['children' => self::pageTreeRecursive($user, 7)])
                            ->orderBy('type', 'desc')
                            ->orderBy('position');
                    },
                ]);
            }])
            ->first();
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspaces = [];
        $currentWorkspace = null;

        if ($user) {
            $workspaces = $user->workspaces()->withCount('projects')->get();

            $routeWorkspaceSlug = $this->workspaceSlugFromRoute($request);
            $workspaceSlug = $routeWorkspaceSlug;

            if (! $workspaceSlug && $this->shouldUseLastSidebarContext($request)) {
                $workspaceSlug = ($request->hasSession() ? $request->session() : null)?->get(self::LAST_WORKSPACE_SLUG_SESSION_KEY);
            }

            if ($workspaceSlug && ! $workspaces->firstWhere('slug', $workspaceSlug)) {
                $workspaceSlug = null;
                $request->session()->forget([
                    self::LAST_WORKSPACE_SLUG_SESSION_KEY,
                    self::LAST_PROJECT_ID_SESSION_KEY,
                ]);
            }

            if ($workspaceSlug) {
                $currentWorkspace = $this->sidebarWorkspace($workspaceSlug, $user);

                // Only show projects the user has access to (workspace admin sees all; others only assigned projects)
                if ($currentWorkspace && $user) {
                    $accessible = $currentWorkspace->projects->filter(fn ($project) => $user->hasProjectAccess($project))->values();
                    $currentWorkspace->setRelation('projects', $accessible);

                    if ($routeWorkspaceSlug) {
                        $request->session()->put(self::LAST_WORKSPACE_SLUG_SESSION_KEY, $currentWorkspace->slug);
                    }
                }
            }
        }

        $currentProjectId = null;
        $currentBoardId = null;
        if ($currentWorkspace) {
            $projectParam = $request->route('project');
            $boardParam = $request->route('board');
            if ($projectParam) {
                $project = is_object($projectParam)
                    ? $currentWorkspace->projects->firstWhere('id', $projectParam->id)
                    : $currentWorkspace->projects->firstWhere('slug', $projectParam);
                if ($project) {
                    $currentProjectId = $project->id;
                    $request->session()->put(self::LAST_PROJECT_ID_SESSION_KEY, $project->id);
                    if ($boardParam) {
                        $board = is_object($boardParam)
                            ? $boardParam
                            : collect($project->boards ?? [])->firstWhere('slug', $boardParam);
                        if ($board) {
                            $currentBoardId = $board->id;
                        }
                    }
                }
            }

            if (! $currentProjectId) {
                $lastProjectId = ($request->hasSession() ? $request->session() : null)?->get(self::LAST_PROJECT_ID_SESSION_KEY);
                $project = $lastProjectId
                    ? $currentWorkspace->projects->firstWhere('id', (int) $lastProjectId)
                    : null;

                if ($project) {
                    $currentProjectId = $project->id;
                } elseif ($lastProjectId) {
                    $request->session()->forget(self::LAST_PROJECT_ID_SESSION_KEY);
                }
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'isInstanceAdmin' => fn () => (bool) $user?->isInstanceAdmin(),
            ],
            // The running focus timer (BRAI-74), shown as a pill in the top bar on every page.
            'activeTimer' => fn () => $user ? WorkTimer::where('user_id', $user->id)->first()?->payload($user) : null,
            // "Star on GitHub" buttons (homepage + account menu): repository link and cached star count
            'githubRepo' => fn () => GithubStars::shared(),
            'workspaces' => $workspaces,
            'currentWorkspace' => $currentWorkspace,
            // workspace settings are admin-only: hide the link for everyone else (it would end in a 403)
            'canManageWorkspace' => fn () => $currentWorkspace && $user ? $user->can('update', $currentWorkspace) : false,
            'currentProjectId' => $currentProjectId,
            'currentBoardId' => $currentBoardId,
            'itemTypes' => fn () => $user ? ItemType::orderBy('id')->get(['id', 'name', 'slug', 'color', 'icon']) : [],
            'priorities' => fn () => $user ? ItemPriority::orderBy('order')->get(['id', 'name', 'slug', 'color', 'icon']) : [],
            'planTemplates' => fn () => $user ? PlanTemplates::all() : [],
            'currentPageUuid' => $request->routeIs('pages.show') && $request->route('page')
                ? (is_object($request->route('page')) ? $request->route('page')->uuid : $request->route('page'))
                : null,
            'unreadNotificationsCount' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            // Quick capture (BRAI-70): open ideas in the user's private capture inbox (sidebar badge)
            'captureInboxCount' => fn () => $user ? Capture::ownedBy($user)->inState('inbox')->count() : 0,
            // Customisable sidebar (BRAI-106): hidden entries, project groups, pins (normalised)
            'sidebarPreferences' => fn () => $user ? SidebarPreferences::forFrontend($user) : null,
            'push' => [
                'vapidPublicKey' => config('webpush.vapid.public_key'),
                'subscribed' => fn () => $user ? $user->pushSubscriptions()->exists() : false,
            ],
            'onboarding' => fn () => $user ? $this->onboardingProps($user) : null,
            // Live channels (BRAI-151): null = off (no socket, no token request); see lib/live
            'live' => fn () => $user && LiveConfig::enabled($request) ? ['debug' => app()->environment('e2e')] : null,
            'flash' => [
                'success' => fn () => ($request->hasSession() ? $request->session() : null)?->get('success'),
                'error' => fn () => ($request->hasSession() ? $request->session() : null)?->get('error'),
                'warning' => fn () => ($request->hasSession() ? $request->session() : null)?->get('warning'),
                'invite_result' => fn () => ($request->hasSession() ? $request->session() : null)?->get('invite_result'),
                'invite_confirm_required' => fn () => ($request->hasSession() ? $request->session() : null)?->get('invite_confirm_required'),
                'invite_email' => fn () => ($request->hasSession() ? $request->session() : null)?->get('invite_email'),
                'invite_role' => fn () => ($request->hasSession() ? $request->session() : null)?->get('invite_role'),
            ],
        ];
    }
}
