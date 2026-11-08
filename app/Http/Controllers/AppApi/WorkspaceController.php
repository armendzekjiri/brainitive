<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Resources\AppApi\WorkspaceCollection;
use App\Http\Resources\AppApi\WorkspaceDetailResource;
use App\Http\Resources\AppApi\WorkspaceResource;
use App\Models\Workspace;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\Entitlements\LimitReached;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App API v1 — workspaces (BRAI-388, BRAI-386): the switcher list, one workspace, and the first-run
 * "create a workspace". Same visibility as the web (members only; anyone else gets a 404), the
 * same validation, defaults and plan limits as the web form (StoreWorkspaceRequest +
 * Workspace::createOwnedBy). Settings stay on the web.
 */
#[Group('Workspaces', weight: 60)]
class WorkspaceController extends Controller
{
    use ResolvesVisible;

    /** The user's workspaces by name (workspace switcher). */
    public function index(ListRequest $request): WorkspaceCollection
    {
        $since = $request->updatedSince();

        $key = ApiKeyAccess::forUser($request->user());

        $page = $request->user()->workspaces()
            // a personal API key: the workspaces in its scope that allow API keys
            ->when($key !== null, fn ($q) => $q->whereIn('workspaces.id', $key->workspaceIds()))
            ->when($since, fn ($q) => $q->where('workspaces.updated_at', '>=', $since))
            ->orderBy('workspaces.name')
            ->orderBy('workspaces.id')
            ->cursorPaginate($request->limit(), ['workspaces.*'], 'cursor', $request->cursor());

        return new WorkspaceCollection($page);
    }

    /** One workspace: the user's role and permissions, counts, plan limits and usage. */
    public function show(Request $request, Workspace $workspace): WorkspaceDetailResource
    {
        $this->visibleWorkspace($request, $workspace);

        return new WorkspaceDetailResource($workspace->load('usage'));
    }

    /**
     * Create a workspace owned by the user (first run).
     *
     * @throws LimitReached the owner is at the free-workspace limit (402 limit_reached)
     */
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);
        $validated = $request->validated();

        $workspace = Workspace::createOwnedBy($request->user(), $validated['name'], $validated['description'] ?? null);

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }
}
