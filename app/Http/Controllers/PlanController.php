<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Plans\PlanDocument;
use App\Support\Plans\PlanTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plans: Tom's Planner–style Gantt planners, several per project. The editor works on the
 * whole document client-side and autosaves it with a revision guard.
 */
class PlanController extends Controller
{
    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('create', [Plan::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'template' => ['nullable', 'string', Rule::in(array_column(PlanTemplates::all(), 'key'))],
        ]);

        $plan = $project->plans()->create([
            'name' => $validated['name'],
            'slug' => Plan::uniqueSlug($project, $validated['name']),
            'description' => $validated['description'] ?? null,
            'data' => PlanTemplates::build($validated['template'] ?? 'blank'),
            'position' => ($project->plans()->max('position') ?? 0) + 1,
            'created_by' => $request->user()->id,
            'last_edited_by' => $request->user()->id,
        ]);
        $plan->recordVersion($request->user(), 'Created');

        return redirect()->route('plans.show', [$workspace->slug, $project->slug, $plan->slug]);
    }

    public function show(Request $request, Workspace $workspace, Project $project, Plan $plan): Response
    {
        $this->authorize('view', $plan);

        $plan->load(['lastEditor:id,name', 'creator:id,name']);

        return Inertia::render('Plans/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'plan' => $this->payload($workspace, $project, $plan),
            'members' => $workspace->users()->orderBy('name')->get(['users.id', 'users.name', 'users.avatar']),
            'can' => [
                'update' => $request->user()->can('update', $plan),
                'delete' => $request->user()->can('delete', $plan),
            ],
        ]);
    }

    /**
     * The current document, for "load latest" after a save conflict.
     */
    public function document(Workspace $workspace, Project $project, Plan $plan): JsonResponse
    {
        $this->authorize('view', $plan);

        return response()->json($this->documentState($plan->load('lastEditor:id,name')));
    }

    /**
     * Rename / describe. The slug stays, so links and shared URLs keep working.
     */
    public function update(Request $request, Workspace $workspace, Project $project, Plan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);

        $plan->update($request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]));

        return back();
    }

    /**
     * Autosave / explicit save of the whole document, guarded by the revision the client saw.
     */
    public function save(Request $request, Workspace $workspace, Project $project, Plan $plan): JsonResponse
    {
        $this->authorize('update', $plan);

        $validated = $request->validate(PlanDocument::rules() + [
            'base_revision' => 'nullable|integer|min:0',
            'force' => 'boolean',
            'checkpoint' => 'boolean',
        ]);

        $baseRevision = $validated['base_revision'] ?? null;
        if ($baseRevision !== null && ! $request->boolean('force') && (int) $baseRevision !== $plan->edit_revision) {
            $plan->load('lastEditor:id,name');

            return response()->json([
                'message' => 'This plan was changed by someone else while you were editing.',
                'conflict' => [
                    'edit_revision' => $plan->edit_revision,
                    'updated_by' => $plan->lastEditor?->only(['id', 'name']),
                    'updated_at' => $plan->updated_at?->toIso8601String(),
                ],
            ], 409);
        }

        $version = $plan->saveDocument(
            $request->user(),
            PlanDocument::normalize($validated['data']),
            PlanDocument::normalizeSettings($validated['settings'] ?? $plan->settings),
            $request->boolean('checkpoint'),
        );

        return response()->json([
            'edit_revision' => $plan->edit_revision,
            'version' => $version?->version,
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ]);
    }

    public function duplicate(Request $request, Workspace $workspace, Project $project, Plan $plan): RedirectResponse
    {
        $this->authorize('view', $plan);
        $this->authorize('create', [Plan::class, $project]);

        $name = $plan->name.' (copy)';
        $copy = $project->plans()->create([
            'name' => $name,
            'slug' => Plan::uniqueSlug($project, $name),
            'description' => $plan->description,
            'data' => $plan->data,
            'settings' => $plan->settings,
            'position' => ($project->plans()->max('position') ?? 0) + 1,
            'created_by' => $request->user()->id,
            'last_edited_by' => $request->user()->id,
        ]);
        $copy->recordVersion($request->user(), "Copied from {$plan->name}");

        return redirect()->route('plans.show', [$workspace->slug, $project->slug, $copy->slug]);
    }

    public function destroy(Workspace $workspace, Project $project, Plan $plan): RedirectResponse
    {
        $this->authorize('delete', $plan);

        $plan->update(['share_token' => null]);
        $plan->delete();

        return redirect()->route('projects.show', [$workspace->slug, $project->slug])
            ->with('success', 'Plan deleted.');
    }

    /**
     * Turn on the read-only share link, or replace it with a new one (old link stops working).
     */
    public function share(Workspace $workspace, Project $project, Plan $plan): JsonResponse
    {
        $this->authorize('update', $plan);

        $plan->update(['share_token' => Str::random(40)]);

        return response()->json(['share_url' => route('plans.public', $plan->share_token)]);
    }

    public function unshare(Workspace $workspace, Project $project, Plan $plan): JsonResponse
    {
        $this->authorize('update', $plan);

        $plan->update(['share_token' => null]);

        return response()->json(['share_url' => null]);
    }

    public function versions(Workspace $workspace, Project $project, Plan $plan): JsonResponse
    {
        $this->authorize('view', $plan);

        $versions = $plan->versions()
            ->with('author:id,name,avatar')
            ->get(['id', 'plan_id', 'version', 'name', 'message', 'created_by', 'created_at']);

        return response()->json([
            'current_version' => $plan->current_version,
            'versions' => $versions->map(fn (PlanVersion $version) => [
                'version' => $version->version,
                'name' => $version->name,
                'message' => $version->message,
                'author' => $version->author?->only(['id', 'name', 'avatar']),
                'created_at' => $version->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function version(Workspace $workspace, Project $project, Plan $plan, int $versionNumber): JsonResponse
    {
        $this->authorize('view', $plan);

        $version = $plan->versions()->where('version', $versionNumber)->firstOrFail();

        return response()->json([
            'version' => $version->version,
            'name' => $version->name,
            'data' => $version->data ?? Plan::emptyData(),
            'settings' => PlanDocument::normalizeSettings($version->settings),
        ]);
    }

    /**
     * Restore an old version; answers with the new document so the editor can swap it in.
     */
    public function restore(Request $request, Workspace $workspace, Project $project, Plan $plan, int $versionNumber): JsonResponse
    {
        $this->authorize('update', $plan);

        $version = $plan->versions()->where('version', $versionNumber)->firstOrFail();
        $restored = $plan->restoreVersion($version, $request->user());

        return response()->json(['restored_as' => $restored->version] + $this->documentState($plan->load('lastEditor:id,name')));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Workspace $workspace, Project $project, Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'uuid' => $plan->uuid,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'current_version' => $plan->current_version,
            'share_url' => $plan->share_token ? route('plans.public', $plan->share_token) : null,
            'created_by' => $plan->creator?->only(['id', 'name']),
            'created_at' => $plan->created_at?->toIso8601String(),
        ] + $this->documentState($plan);
    }

    /**
     * @return array{data: array<string, mixed>, settings: array<string, mixed>, edit_revision: int, updated_at: string|null, last_edited_by: array{id: int, name: string}|null}
     */
    private function documentState(Plan $plan): array
    {
        return [
            'data' => $plan->data ?? Plan::emptyData(),
            'settings' => PlanDocument::normalizeSettings($plan->settings),
            'edit_revision' => $plan->edit_revision,
            'updated_at' => $plan->updated_at?->toIso8601String(),
            'last_edited_by' => $plan->lastEditor?->only(['id', 'name']),
        ];
    }
}
