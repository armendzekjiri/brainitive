<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Workspace;
use App\Support\Templates\TemplateCapture;
use App\Support\Templates\TemplateDocument;
use App\Support\Templates\TemplateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saving projects as workspace templates (BRAI-72). Using a template happens in
 * ProjectController@store via App\Support\Templates\TemplateRuns.
 */
class ProjectTemplateController extends Controller
{
    /**
     * What saving this project as a template would include (for the preview in the dialog).
     */
    public function preview(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        // The project group isn't scope-bound: never capture another workspace's project here.
        abort_unless($project->workspace_id === $workspace->id, 404);
        $this->authorize('create', [ProjectTemplate::class, $workspace]);
        $this->authorize('view', $project);

        $include = $this->include($request);

        try {
            return response()->json(['summary' => TemplateDocument::summary(TemplateCapture::capture($project, $include))]);
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Save the project as a new workspace template, or as a new version of an existing one.
     */
    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        // The project group isn't scope-bound: never capture another workspace's project here.
        abort_unless($project->workspace_id === $workspace->id, 404);
        $this->authorize('create', [ProjectTemplate::class, $workspace]);
        $this->authorize('view', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'template' => ['nullable', 'uuid', Rule::exists('project_templates', 'uuid')->where('workspace_id', $workspace->id)],
        ]);

        try {
            $document = TemplateCapture::capture($project, $this->include($request));
        } catch (TemplateException $e) {
            throw ValidationException::withMessages(['include' => $e->getMessage()]);
        }

        $template = DB::transaction(function () use ($validated, $workspace, $project, $document, $request) {
            $template = isset($validated['template'])
                ? ProjectTemplate::where('workspace_id', $workspace->id)->where('uuid', $validated['template'])->lockForUpdate()->firstOrFail()
                : new ProjectTemplate(['workspace_id' => $workspace->id, 'created_by' => $request->user()->id]);
            $template->fill(['name' => trim($validated['name']), 'description' => $validated['description'] ?? null])->save();

            $template->versions()->create([
                // reorder(): the relation sorts by version, and Postgres refuses ORDER BY next to MAX()
                'version' => ($template->versions()->reorder()->max('version') ?? 0) + 1,
                'format' => TemplateDocument::FORMAT,
                'document' => $document,
                'summary' => TemplateDocument::summary($document),
                'source_project_id' => $project->id,
                'created_by' => $request->user()->id,
            ]);

            return $template;
        });

        return back()->with('success', isset($validated['template'])
            ? "Template “{$template->name}” updated. New projects use this version; existing ones stay as they are."
            : "Saved as template “{$template->name}”. Pick it when you create a project.");
    }

    public function destroy(Workspace $workspace, ProjectTemplate $projectTemplate): RedirectResponse
    {
        abort_unless($projectTemplate->workspace_id === $workspace->id, 404);
        $this->authorize('delete', $projectTemplate);

        $projectTemplate->delete();

        return back()->with('success', 'Template deleted. Projects created from it are not affected.');
    }

    /**
     * @return list<string>
     */
    private function include(Request $request): array
    {
        $validated = $request->validate([
            'include' => 'nullable|array',
            // "none" stands for "nothing extra" (an empty array isn't sent by forms)
            'include.*' => Rule::in([...TemplateDocument::SECTIONS, 'none']),
        ]);

        return isset($validated['include']) ? array_values(array_diff($validated['include'], ['none'])) : TemplateDocument::SECTIONS;
    }
}
