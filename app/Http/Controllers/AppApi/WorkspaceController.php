<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Resources\AppApi\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * App API v1 — first-run "create a workspace" (BRAI-386). Same validation, defaults and plan
 * limits as the web form (StoreWorkspaceRequest + Workspace::createOwnedBy).
 */
class WorkspaceController extends Controller
{
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);
        $validated = $request->validated();

        $workspace = Workspace::createOwnedBy($request->user(), $validated['name'], $validated['description'] ?? null);

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }
}
