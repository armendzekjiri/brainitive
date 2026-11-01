<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\NavigationRequest;
use App\Http\Requests\AppApi\UpdatePinsRequest;
use App\Http\Resources\AppApi\NavigationResource;
use App\Http\Resources\AppApi\PinsResource;
use App\Models\Workspace;
use App\Support\Navigation\PinnedContent;
use App\Support\Navigation\RecentDestinations;
use App\Support\Sidebar\SidebarPreferences;
use Dedoc\Scramble\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — navigation (BRAI-391): the web sidebar of a workspace (HandleInertiaRequests +
 * SidebarPreferences) for the apps' menus and jump lists, and saving a project's pins. Same
 * visibility as the web sidebar: workspace admins see every project, others their projects.
 */
#[Group('Navigation', weight: 90)]
class NavigationController extends Controller
{
    use ResolvesVisible;

    /**
     * The sidebar of one workspace: visible entries, projects with their content groups and pins,
     * and the app's recent destinations (`recent[]=board:12`) resolved.
     *
     * @throws NotFoundHttpException the workspace doesn't exist or the user isn't a member (404)
     */
    public function show(NavigationRequest $request): NavigationResource
    {
        $user = $request->user();
        $workspace = Workspace::where('slug', $request->validated('workspace'))->first();
        abort_unless($workspace instanceof Workspace, 404);
        $this->visibleWorkspace($request, $workspace);

        $projects = $workspace->projects()
            // workspace admins see all projects; others only the projects they are members of (as the web sidebar)
            ->when($user->workspaceRole($workspace) !== 'admin', fn ($q) => $q->whereHas('users', fn ($u) => $u->where('user_id', $user->id)))
            ->with([
                'boards:id,project_id,name,slug,type,position',
                'taskLists:id,project_id,name,slug,position',
                'plans:id,project_id,name,slug,position',
                'helpdesks:id,project_id,name,slug,position',
                'leadForms:id,project_id,name,slug,position',
            ])
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->get()
            ->each(fn ($project) => $project->setRelation('workspace', $workspace));

        return new NavigationResource([
            'workspace' => $workspace,
            'projects' => $projects,
            'recent' => RecentDestinations::resolve($user, $request->recent()),
        ]);
    }

    /**
     * Replace one project's sidebar pins (and optionally "pinned only"). Pins the user can't open or
     * that aren't in the project are dropped.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function updatePins(UpdatePinsRequest $request): PinsResource
    {
        $user = $request->user();
        $validated = $request->validated();
        $project = $this->visibleProject($request, (int) $validated['project_id']);

        $changes = ['id' => $project->id, 'pins' => $validated['pins']];
        if (array_key_exists('pinned_only', $validated)) {
            $changes['pinned_only'] = (bool) $validated['pinned_only'];
        }
        SidebarPreferences::apply($user, ['project' => $changes], $project);

        $layout = SidebarPreferences::normalize($user->fresh()->sidebar_preferences)['projects'][(string) $project->id] ?? SidebarPreferences::normalizeProject([]);

        return new PinsResource([
            'project_id' => $project->id,
            'pinned_only' => $layout['pinned_only'],
            'pins' => PinnedContent::resolve($user, $project, $layout['pins']),
        ]);
    }
}
