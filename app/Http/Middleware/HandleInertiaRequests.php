<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
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
     * Recursively load page children for sidebar tree (avoids N+1, supports Folder -> folder -> document etc.).
     *
     * @param  int  $depth  Remaining nesting levels (e.g. 7 allows 8 levels: root + 7 nested).
     * @return \Closure
     */
    private static function pageTreeRecursive(int $depth = 7): \Closure
    {
        return function ($query) use ($depth) {
            $query->orderBy('type', 'desc')->orderBy('position');
            if ($depth > 0) {
                $query->with(['children' => self::pageTreeRecursive($depth - 1)]);
            }
        };
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
            // Get all user's workspaces
            if ($user->isInstanceAdmin()) {
                $workspaces = Workspace::withCount('projects')->get();
            } else {
                $workspaces = $user->workspaces()->withCount('projects')->get();
            }

            // Determine current workspace from route
            $workspaceSlug = $request->route('workspace');
            if ($workspaceSlug instanceof Workspace) {
                $workspaceSlug = $workspaceSlug->slug;
            }

            if ($workspaceSlug) {
                $currentWorkspace = Workspace::where('slug', $workspaceSlug)
                    ->withCount('projects')
                    ->with(['projects' => function ($query) {
                        $query->with([
                            'boards:id,project_id,name,slug,type',
                            'pages' => function ($q) {
                                $q->with(['children' => self::pageTreeRecursive(7)])
                                    ->orderBy('type', 'desc')
                                    ->orderBy('position');
                            },
                        ]);
                    }])
                    ->first();
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'workspaces' => $workspaces,
            'currentWorkspace' => $currentWorkspace,
            'currentPageUuid' => $request->routeIs('pages.show') && $request->route('page')
                ? (is_object($request->route('page')) ? $request->route('page')->uuid : $request->route('page'))
                : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
