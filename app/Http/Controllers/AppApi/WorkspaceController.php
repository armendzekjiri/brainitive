<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Resources\AppApi\WorkspaceResource;
use App\Models\Workspace;
use App\Support\Entitlements\LimitReached;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * App API v1 — first-run "create a workspace" (BRAI-386). Same validation, defaults and plan
 * limits as the web form (StoreWorkspaceRequest + Workspace::createOwnedBy).
 */
#[Group('Invitations', weight: 50)]
class WorkspaceController extends Controller
{
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
