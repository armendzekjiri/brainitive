<?php

namespace App\Support\Projects;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use App\Support\Templates\TemplateDocument;
use App\Support\Templates\TemplateRuns;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "New project" (web form ProjectController@store and App API `POST /workspaces/{slug}/projects`,
 * BRAI-389): the rules, the plan limits, blank or from a template. A blank project gets its creator
 * as project admin and a "Main Board"; a template run sets up everything the template holds.
 * The caller authorizes (`WorkspacePolicy::createProjects`) and validates with rules() first.
 */
final class ProjectCreator
{
    /**
     * The workspace is only read when validating (unique name / key per workspace).
     *
     * @return array<string, mixed>
     */
    public static function rules(?Workspace $workspace): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')
                    ->where(fn ($q) => $q->where('workspace_id', $workspace?->id)),
            ],
            'key' => [
                'required',
                'string',
                'max:10',
                'alpha',
                Rule::unique('projects', 'key')
                    ->where(fn ($q) => $q->where('workspace_id', $workspace?->id)),
            ],
            'description' => 'nullable|string|max:50000',
            // Start from a template: "builtin:<key>" or "template:<uuid>" (see TemplateRuns::catalog)
            'template' => 'nullable|string|max:80',
            'start_date' => 'nullable|required_with:template|date_format:Y-m-d',
            'include' => 'nullable|array',
            // "none" stands for "nothing extra" (an empty array isn't sent by forms)
            'include.*' => Rule::in([...TemplateDocument::SECTIONS, 'none']),
            'request_id' => 'nullable|uuid',
        ];
    }

    /**
     * Create the project from validated input. Returns the project and, for a blank one, its board.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Project, 1: Board|null}
     *
     * @throws LimitReached the workspace is at its project or storage limit
     * @throws ValidationException the template can't be used / is already being created
     */
    public static function create(Workspace $workspace, User $user, array $validated): array
    {
        // Cloud plans: project count and content storage (also covers "from a template")
        $gate = app(LimitGate::class);
        $gate->ensureCanCreateProject($workspace);
        $gate->ensureContentRoom($workspace);

        if (! empty($validated['template'])) {
            if (isset($validated['include'])) {
                $validated['include'] = array_values(array_diff($validated['include'], ['none']));
            }

            return [TemplateRuns::createProject($workspace, $user, $validated), null];
        }

        $project = $workspace->projects()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'key' => strtoupper($validated['key']),
            'description' => $validated['description'] ?? null,
            'created_by' => $user->id,
        ]);

        // Creator is added as project admin so they have access
        $project->addUser($user, 'admin');

        $board = $project->boards()->create([
            'name' => 'Main Board',
            'slug' => 'main-board',
            'type' => 'kanban',
            'created_by' => $user->id,
        ]);

        return [$project, $board];
    }
}
