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
                                $q->with(['children' => function ($c) {
                                    $c->orderBy('type', 'desc')->orderBy('position');
                                }])->orderBy('type', 'desc')->orderBy('position');
                            }
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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
