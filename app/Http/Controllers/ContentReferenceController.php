<?php

namespace App\Http\Controllers;

use App\Models\ContentReference;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\References\ContentReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Linked content and backlinks (BRAI-71) for issues, docs / whiteboards, tickets and plans of
 * one project. JSON endpoints for components/references/RelatedContent.tsx.
 */
class ContentReferenceController extends Controller
{
    /**
     * What a resource links to and what links to it (only what the user can see).
     */
    public function index(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $project->setRelation('workspace', $workspace);
        $validated = $request->validate($this->endpointRules('type', 'id'));
        $model = $this->visibleEndpoint($request->user(), $project, $validated['type'], (int) $validated['id']);
        abort_if($model === null, 404);

        return response()->json(ContentReferences::forEndpoint($workspace, $project, $request->user(), $model));
    }

    /**
     * Picker search: what the resource could link to.
     */
    public function candidates(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $project->setRelation('workspace', $workspace);
        $validated = $request->validate($this->endpointRules('type', 'id') + ['q' => 'nullable|string|max:100']);
        $source = $this->visibleEndpoint($request->user(), $project, $validated['type'], (int) $validated['id']);
        abort_if($source === null, 404);
        $this->authorize('update', $source);

        return response()->json([
            'results' => ContentReferences::candidates($workspace, $project, $request->user(), $source, $validated['q'] ?? null),
        ]);
    }

    /**
     * Link a resource to another one of the same project. Linking twice is a no-op (200).
     */
    public function store(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $project->setRelation('workspace', $workspace);
        $validated = $request->validate($this->endpointRules('source_type', 'source_id') + $this->endpointRules('target_type', 'target_id'));
        $user = $request->user();

        $source = $this->visibleEndpoint($user, $project, $validated['source_type'], (int) $validated['source_id']);
        if ($source === null) {
            throw ValidationException::withMessages(['source_id' => 'This content wasn’t found in the project.']);
        }
        $this->authorize('update', $source);

        // a target the user can't see answers like a missing one: no hint that a private draft exists
        $target = $this->visibleEndpoint($user, $project, $validated['target_type'], (int) $validated['target_id']);
        if ($target === null) {
            throw ValidationException::withMessages(['target_id' => 'This content wasn’t found in the project.']);
        }

        try {
            $reference = ContentReferences::link($project, $user, $source, $target);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['target_id' => $exception->getMessage()]);
        }

        return response()->json([
            'id' => $reference->id,
            'created' => $reference->wasRecentlyCreated,
        ], $reference->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a manual reference. Derived ones follow their plan row / whiteboard card / smart link.
     */
    public function destroy(Request $request, Workspace $workspace, Project $project, ContentReference $contentReference): JsonResponse
    {
        $project->setRelation('workspace', $workspace);
        $user = $request->user();

        $source = $this->visibleEndpoint($user, $project, $contentReference->source_type, $contentReference->source_id);
        abort_if($source === null, 404);
        // the target may be gone (deleted / trashed); when it exists, the user must be able to see it
        $target = ContentReferences::resolve($project, $contentReference->target_type, $contentReference->target_id);
        abort_if($target !== null && ! $user->can('view', $target), 404);
        $this->authorize('update', $source);

        if ($contentReference->isDerived()) {
            throw ValidationException::withMessages([
                'reference' => 'This link comes from a plan row, a whiteboard card or a smart link. Change it there.',
            ]);
        }

        $contentReference->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointRules(string $type, string $id): array
    {
        return [
            $type => ['required', 'string', Rule::in(array_keys(ContentReference::TYPES))],
            $id => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * A live endpoint of this project the user can view, or null.
     */
    private function visibleEndpoint(User $user, Project $project, string $type, int $id): ?Model
    {
        $model = ContentReferences::resolve($project, $type, $id);

        return $model !== null && $user->can('view', $model) ? $model : null;
    }
}
