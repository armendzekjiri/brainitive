<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\StoreProjectRequest;
use App\Http\Resources\AppApi\ProjectCollection;
use App\Http\Resources\AppApi\ProjectDetailResource;
use App\Http\Resources\AppApi\ProjectResource;
use App\Http\Resources\AppApi\ProjectTemplateResource;
use App\Models\Workspace;
use App\Support\Entitlements\LimitReached;
use App\Support\Projects\ProjectCreator;
use App\Support\Templates\TemplateRuns;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — projects (BRAI-389): the directory of a workspace, the project home and "New
 * project" (blank or from a template). Same rules as the web: workspace admins see every project,
 * everybody else the projects they are a member of (ProjectController@index, ProjectPolicy::view);
 * creating needs WorkspacePolicy::createProjects and goes through ProjectCreator, like the web form.
 */
#[Group('Projects', weight: 70)]
class ProjectController extends Controller
{
    use ResolvesVisible;

    /** The projects of a workspace the user can open, by name. */
    public function index(ListRequest $request, Workspace $workspace): ProjectCollection
    {
        $this->visibleWorkspace($request, $workspace);
        $user = $request->user();
        $since = $request->updatedSince();

        $page = $workspace->projects()
            ->with('workspace:id,slug,name')
            // workspace admins see all projects; others only the projects they are members of
            ->when($user->workspaceRole($workspace) !== 'admin', fn ($q) => $q->whereHas('users', fn ($u) => $u->where('user_id', $user->id)))
            ->when($since, fn ($q) => $q->where('projects.updated_at', '>=', $since))
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->cursorPaginate($request->limit(), ['projects.*'], 'cursor', $request->cursor());

        return new ProjectCollection($page);
    }

    /**
     * The project home: boards, docs, task lists, plans, helpdesks, lead forms, recently updated.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function show(Request $request, int $project): ProjectDetailResource
    {
        $project = $this->visibleProject($request, $project);
        $project->load(['boards', 'taskLists', 'plans', 'helpdesks', 'leadForms']);

        return new ProjectDetailResource($project);
    }

    /** The templates a new project can start from (built-in and the workspace's own). */
    public function templates(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->visibleWorkspace($request, $workspace);
        $this->authorize('createProjects', $workspace);

        return ProjectTemplateResource::collection(TemplateRuns::catalog($workspace, $request->user()));
    }

    /**
     * Create a project, blank or from a template (`template` + `start_date`).
     *
     * A blank project gets a "Main Board" and the user as project admin. A template run is
     * idempotent with `request_id` (UUID): a retry answers 200 with the project it created — or 404
     * once the caller can no longer open it (moved to another workspace).
     *
     * @throws LimitReached the workspace is at its project or storage limit (402 limit_reached)
     * @throws ValidationException the template can't be used or is still being created (422)
     */
    public function store(StoreProjectRequest $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        if ($existing = $request->existingProject()) {
            // re-resolved (Codex review): a project transferred meanwhile to a workspace the caller can't open is the
            // same 404 as GET /projects/{id}, never its data
            return (new ProjectResource($this->visibleProject($request, $existing->id)))->response();
        }

        [$project] = ProjectCreator::create($workspace, $user, $request->validated());

        return (new ProjectResource($project->load('workspace')))->response()->setStatusCode(201);
    }
}
