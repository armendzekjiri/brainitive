<?php

namespace App\Http\Controllers\AppApi\Concerns;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * App API v1: a workspace or project the user can't open is a 404, never a 403, so nobody learns
 * that it exists (docs/ai/API.md → Errors). Same rules as the web policies (WorkspacePolicy::view,
 * ProjectPolicy::view); finer checks (update, invite …) follow with authorize() and are 403.
 */
trait ResolvesVisible
{
    protected function visibleWorkspace(Request $request, Workspace $workspace): Workspace
    {
        abort_unless($request->user()->can('view', $workspace), 404);

        return $workspace;
    }

    protected function visibleProject(Request $request, int $id): Project
    {
        $project = Project::with('workspace')->find($id);
        abort_unless($project instanceof Project && $project->workspace !== null && $request->user()->can('view', $project), 404);

        return $project;
    }
}
