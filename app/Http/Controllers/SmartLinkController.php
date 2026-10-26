<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Workspace;
use App\Support\References\SmartLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Smart links in docs and issue descriptions (BRAI-143): live data for the chips / cards of a
 * page in one batched request, and the "/link" picker search. Only what the viewer can see; the
 * rest answers `restricted` / `not_found` without a title.
 */
class SmartLinkController extends Controller
{
    /**
     * `refs[]=item:12&refs[]=page:<uuid>…` → `{ links: { "<ref>": { state, … } } }`.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($request->user()->hasWorkspaceAccess($workspace), 404);
        $validated = $request->validate([
            'refs' => 'required|array|min:1|max:'.SmartLinks::MAX_REFS,
            'refs.*' => 'required|string|max:300',
        ]);

        return response()->json([
            'links' => (object) SmartLinks::resolve($workspace, $request->user(), array_values($validated['refs'])),
        ]);
    }

    /**
     * Picker: issues, docs, whiteboards, tickets and plans of one project the user can open,
     * plus the workspace's projects they can switch to.
     */
    public function search(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasWorkspaceAccess($workspace), 404);
        $validated = $request->validate([
            'project' => 'required|string|max:255',
            'q' => 'nullable|string|max:100',
        ]);

        $projects = Project::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'workspace_id', 'name', 'slug'])->keyBy('id');
        $accessible = SmartLinks::accessibleProjectIds($workspace, $user, $projects);
        $project = $projects->first(fn (Project $p) => $p->slug === $validated['project']);
        abort_unless($project !== null && isset($accessible[$project->id]), 404);
        $project->setRelation('workspace', $workspace);

        return response()->json([
            'project' => ['slug' => $project->slug, 'name' => $project->name],
            'projects' => $projects->filter(fn (Project $p) => isset($accessible[$p->id]))
                ->map(fn (Project $p) => ['slug' => $p->slug, 'name' => $p->name])
                ->values(),
            'results' => SmartLinks::search($workspace, $user, $project, $validated['q'] ?? null),
        ]);
    }
}
