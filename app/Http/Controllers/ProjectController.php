<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Page;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Projects\ProjectTransfer;
use App\Support\Templates\TemplateDocument;
use App\Support\Templates\TemplateRuns;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects in a workspace.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);

        $query = $workspace->projects()
            ->withCount('boards')
            ->with('creator:id,name')
            ->latest();

        // Workspace admins see all projects; others only projects they are assigned to
        if ($request->user()->workspaceRole($workspace) !== 'admin') {
            $query->whereHas('users', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        $projects = $query->get();

        return Inertia::render('Projects/Index', [
            'workspace' => $workspace,
            'projects' => $projects,
        ]);
    }

    /**
     * Show the form for creating a new project.
     */
    public function create(Workspace $workspace): Response
    {
        $this->authorize('createProjects', $workspace);

        return Inertia::render('Projects/Create', [
            'workspace' => $workspace,
            // Built-in and workspace templates to start from (BRAI-72 / BRAI-91)
            'templates' => $templates = TemplateRuns::catalog($workspace, auth()->user()),
            'categories' => TemplateRuns::categories($templates),
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request, Workspace $workspace)
    {
        $this->authorize('createProjects', $workspace);

        // A retried "create from template" submit lands on the project it already created
        if ($request->filled('template') && ($existing = TemplateRuns::alreadyCreated($workspace, $request->user(), $request->input('request_id')))) {
            return redirect()->route('projects.show', [$workspace, $existing]);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')
                    ->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
            'key' => [
                'required',
                'string',
                'max:10',
                'alpha',
                Rule::unique('projects', 'key')
                    ->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
            'description' => 'nullable|string|max:50000',
            // Start from a template: "builtin:<key>" or "template:<uuid>" (see TemplateRuns::catalog)
            'template' => 'nullable|string|max:80',
            'start_date' => 'nullable|required_with:template|date_format:Y-m-d',
            'include' => 'nullable|array',
            // "none" stands for "nothing extra" (an empty array isn't sent by forms)
            'include.*' => Rule::in([...TemplateDocument::SECTIONS, 'none']),
            'request_id' => 'nullable|uuid',
        ]);

        if (! empty($validated['template'])) {
            if (isset($validated['include'])) {
                $validated['include'] = array_values(array_diff($validated['include'], ['none']));
            }
            $project = TemplateRuns::createProject($workspace, $request->user(), $validated);

            return redirect()->route('projects.show', [$workspace, $project])
                ->with('success', 'Project created from the template.');
        }

        $project = $workspace->projects()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'key' => strtoupper($validated['key']),
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Creator is added as project admin so they have access
        $project->addUser($request->user(), 'admin');

        // Create a default board
        $board = $project->boards()->create([
            'name' => 'Main Board',
            'slug' => 'main-board',
            'type' => 'kanban',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('boards.show', [$workspace, $project, $board])
            ->with('success', 'Project created successfully.');
    }

    /**
     * Display the specified project.
     */
    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('view', $project);

        $project->loadCount('users');
        $project->load([
            'boards' => function ($query) {
                $query->orderBy('position');
            },
            'taskLists' => function ($query) {
                $query->orderBy('position');
            },
            'plans' => function ($query) {
                $query->select('id', 'project_id', 'name', 'slug', 'description', 'position', 'updated_at')->orderBy('position');
            },
            'creator:id,name,avatar',
            'pages' => function ($query) use ($request) {
                $query->visibleTo($request->user())->orderByRaw("CASE type WHEN 'folder' THEN 0 ELSE 1 END")->orderBy('position');
            },
            'helpdesks' => function ($query) {
                $query->orderBy('created_at', 'desc');
            },
            'leadForms:id,project_id,name,slug,status,position',
        ]);

        $project->pages->load(['children' => function ($query) use ($request) {
            $query->visibleTo($request->user())->orderByRaw("CASE type WHEN 'folder' THEN 0 ELSE 1 END")->orderBy('position');
        }]);

        $ai = $workspace->settings ?? [];

        return Inertia::render('Projects/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'canUpdate' => $request->user()->can('update', $project),
            'canCreatePages' => $request->user()->can('create', [Page::class, $project]),
            'aiConfigured' => ! empty($ai['ai_endpoint']) && ! empty($ai['ai_api_key']) && ! empty($ai['ai_model']),
        ]);
    }

    /**
     * What moving the project to another workspace will do (who keeps access, etc.).
     */
    public function movePreview(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $target = $this->moveTarget($request, $workspace, $project);

        return response()->json(ProjectTransfer::preview($project, $target));
    }

    /**
     * Move the project with all its content to another workspace the user also administers.
     */
    public function move(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $target = $this->moveTarget($request, $workspace, $project);
        $request->validate(['confirm_name' => ['required', 'string', function ($attribute, $value, $fail) use ($project) {
            if (trim($value) !== $project->name) {
                $fail('Type the project name exactly to confirm.');
            }
        }]]);

        try {
            $moved = ProjectTransfer::move($project, $target);
        } catch (\DomainException $e) {
            return back()->withErrors(['target_workspace_id' => $e->getMessage()]);
        }

        return redirect()->route('projects.show', [$moved->workspace->slug, $moved->slug])
            ->with('success', "Moved to {$moved->workspace->name}.");
    }

    private function moveTarget(Request $request, Workspace $workspace, Project $project): Workspace
    {
        abort_unless(ProjectTransfer::canManage($request->user(), $workspace), 403, 'Only admins of this workspace can move its projects.');

        $validated = $request->validate(['target_workspace_id' => 'required|integer']);
        $target = Workspace::find($validated['target_workspace_id']);
        abort_unless($target && $target->id !== $workspace->id && ProjectTransfer::canManage($request->user(), $target), 403, 'You need to be an admin of the workspace you move the project to.');

        return $target;
    }

    /**
     * Show the form for editing the specified project.
     */
    public function edit(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('update', $project);

        $project->load(['labels', 'users' => fn ($q) => $q->select('users.id', 'name', 'email', 'avatar')->withPivot('role')]);

        $workspaceUsers = $workspace->users()->get(['users.id', 'name', 'email', 'avatar']);
        $moveTargets = ProjectTransfer::canManage($request->user(), $workspace)
            ? ProjectTransfer::targets($request->user(), $project)->map->only(['id', 'name', 'slug'])->values()
            : [];
        $disabledTypes = $project->disabledItemTypeIds();
        $projectItemTypes = ItemType::forProjectSettings($project)->orderBy('id')->get(['id', 'project_id', 'name', 'slug', 'icon', 'color', 'description', 'is_default', 'is_active'])
            // Default types: "active" means active for this project.
            ->each(fn (ItemType $type) => $type->project_id === null && $type->is_active = $type->is_active && ! in_array($type->id, $disabledTypes, true));

        return Inertia::render('Projects/Edit', [
            'workspace' => $workspace,
            'project' => $project,
            'workspaceUsers' => $workspaceUsers,
            'canManageMembers' => $request->user()->can('manageMembers', $project),
            'projectItemTypes' => $projectItemTypes,
            'moveTargets' => $moveTargets,
            'github' => ProjectGithubController::settingsFor($project),
            // Custom fields (archived too) with how many issues use them: type changes and
            // deleting are only allowed while a field is unused.
            'itemFields' => $project->itemFields()->with('options')->withCount('values')->orderBy('position')->orderBy('id')->get(),
            'projectBoards' => $project->boards()->orderBy('name')->get(['id', 'name', 'slug']),
            // "Save as template" (workspace admins): existing templates can get a new version
            'canSaveTemplate' => $request->user()->can('create', [ProjectTemplate::class, $workspace]),
            'workspaceTemplates' => $request->user()->can('create', [ProjectTemplate::class, $workspace])
                ? ProjectTemplate::where('workspace_id', $workspace->id)->orderBy('name')->get(['uuid', 'name', 'description'])
                : [],
        ]);
    }

    /**
     * Update the specified project.
     */
    public function update(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:50000',
            'key' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^[A-Z0-9]*$/',
                Rule::unique('projects', 'key')->where('workspace_id', $workspace->id)->ignore($project->id),
            ],
            'icon' => 'nullable|string|max:500',
            'icon_image' => 'nullable|image|mimes:png,jpeg,jpg|max:2048',
            'color' => 'nullable|string|max:20',
        ]);

        if (array_key_exists('key', $validated)) {
            if (($validated['key'] ?? '') === '') {
                unset($validated['key']);
            } else {
                $validated['key'] = strtoupper($validated['key']);
            }
        }

        if (array_key_exists('icon', $validated) && $validated['icon'] && str_contains($validated['icon'], 'storage/')) {
            $validated['icon'] = Str::after($validated['icon'], 'storage/');
        }

        if ($request->hasFile('icon_image')) {
            if ($project->icon && Storage::disk('public')->exists($project->icon)) {
                Storage::disk('public')->delete($project->icon);
            }
            $file = $request->file('icon_image');
            $ext = Uploads::imageExtension($file);
            $path = $file->storeAs('project-icons/'.$project->id, 'icon.'.$ext, 'public');
            $validated['icon'] = $path;
        }

        unset($validated['icon_image']);
        $project->update($validated);

        return redirect()->route('projects.show', [$workspace, $project])
            ->with('success', 'Project updated successfully.');
    }

    /**
     * Upload project icon (for use in settings form).
     */
    public function uploadIcon(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('update', $project);

        if ($project->workspace_id !== $workspace->id) {
            abort(404);
        }

        $request->validate([
            'icon' => 'required|file|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        $file = $request->file('icon');
        $ext = Uploads::imageExtension($file);
        $path = $file->storeAs('project-icons/'.$project->id, 'icon.'.$ext, 'public');
        $project->update(['icon' => $path]);

        return response()->json(['icon_display' => $project->fresh()->icon_display]);
    }

    /**
     * Upload an icon for an item type (emoji or custom image). Returns storage path to save on the item type.
     */
    public function uploadItemTypeIcon(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        if ($project->workspace_id !== $workspace->id) {
            abort(404);
        }

        $request->validate([
            'icon' => 'required|file|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        $file = $request->file('icon');
        $ext = Uploads::imageExtension($file);
        $path = $file->storeAs('project-item-type-icons/'.$project->id, Str::uuid().'.'.$ext, 'public');

        return response()->json(['icon' => $path, 'icon_display' => asset('storage/'.$path)]);
    }

    /**
     * Remove the specified project.
     */
    public function destroy(Workspace $workspace, Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()->route('workspaces.show', $workspace)
            ->with('success', 'Project deleted successfully.');
    }

    /**
     * Add a workspace member to the project with a role.
     */
    public function inviteMember(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id', Rule::in($workspace->users()->pluck('users.id'))],
            'role' => ['required', 'string', Rule::in(['admin', 'editor', 'viewer'])],
        ]);

        $user = User::findOrFail($validated['user_id']);
        if ($project->hasUser($user)) {
            return back()->with('error', 'That user is already a member of this project.');
        }

        $project->addUser($user, $validated['role'], $request->user());

        return back()->with('success', "{$user->name} has been added to the project.");
    }

    /**
     * Update a project member's role.
     */
    public function updateMemberRole(Request $request, Workspace $workspace, Project $project, User $user)
    {
        $this->authorize('manageMembers', $project);

        if (! $project->hasUser($user)) {
            abort(404);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['admin', 'editor', 'viewer'])],
        ]);

        $project->updateUserRole($user, $validated['role']);

        return back()->with('success', 'Role updated.');
    }

    /**
     * Remove a user from the project.
     */
    public function removeMember(Request $request, Workspace $workspace, Project $project, User $user)
    {
        $this->authorize('manageMembers', $project);

        if (! $project->hasUser($user)) {
            abort(404);
        }

        $project->removeUser($user);

        return back()->with('success', 'Member removed from project.');
    }

    /**
     * Search project members by name (JSON, for @mention autocomplete).
     */
    public function searchMembers(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $query = $request->input('query', '');
        $limit = min((int) $request->input('limit', 10), 30);

        $members = $project->users()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar')
            ->when($query, fn ($q) => $q->whereRaw('LOWER(users.name) LIKE ?', ['%'.strtolower($query).'%']))
            ->withPivot('role')
            ->limit($limit)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_display' => $u->avatar_display,
                'role' => $u->pivot->role,
            ]);

        return response()->json($members);
    }

    /**
     * Return project labels as JSON (used by global create modal).
     */
    public function labelsJson(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $labels = $project->labels()->orderBy('name')->get(['id', 'name', 'color', 'slug']);

        return response()->json($labels);
    }

    /**
     * Store a new label for the project.
     */
    public function storeLabel(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'required|string|max:7',
        ]);

        $label = $project->labels()->create($validated);

        if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json($label, 201);
        }

        return back()->with('success', 'Label created successfully.');
    }

    /**
     * Update an existing label.
     */
    public function updateLabel(Request $request, Workspace $workspace, Project $project, Label $label)
    {
        $this->authorize('update', $project);

        // Ensure label belongs to project
        if ($label->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'required|string|max:7',
        ]);

        $label->update($validated);

        return back()->with('success', 'Label updated successfully.');
    }

    /**
     * Delete a label.
     */
    public function destroyLabel(Workspace $workspace, Project $project, Label $label)
    {
        $this->authorize('update', $project);

        // Ensure label belongs to project
        if ($label->project_id !== $project->id) {
            abort(404);
        }

        $label->delete();

        return back()->with('success', 'Label deleted successfully.');
    }

    /**
     * Store a new item type for the project.
     */
    public function storeItemType(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $validated['project_id'] = $project->id;
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $type = $project->itemTypes()->create($validated);

        if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json($type->only(['id', 'project_id', 'name', 'slug', 'icon', 'color', 'description', 'is_default', 'is_active']), 201);
        }

        return back()->with('success', 'Item type created.');
    }

    /**
     * Update an existing item type (project-owned or global default types).
     */
    public function updateItemType(Request $request, Workspace $workspace, Project $project, ItemType $itemType)
    {
        $this->authorize('update', $project);

        $isGlobal = $itemType->project_id === null;
        $isProjectOwned = $itemType->project_id === $project->id;
        if (! $isGlobal && ! $isProjectOwned) {
            abort(404, 'Item type not found for this project.');
        }

        // Default types are shared by every workspace on the instance: a project can only
        // switch one off or on for itself, never rename or restyle it for everyone.
        if ($isGlobal) {
            $validated = $request->validate(['is_active' => 'required|boolean']);
            $disabled = collect($project->disabledItemTypeIds())->reject(fn (int $id) => $id === $itemType->id);
            if (! $validated['is_active']) {
                $disabled->push($itemType->id);
            }
            $project->update(['settings' => array_merge($project->settings ?? [], ['disabled_item_types' => $disabled->values()->all()])]);

            return back()->with('success', $validated['is_active'] ? 'Item type enabled for this project.' : 'Item type disabled for this project.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('slug', $validated) && $validated['slug'] === '') {
            $validated['slug'] = Str::slug($validated['name']);
        }

        unset($validated['is_active']);

        $itemType->update($validated);

        return back()->with('success', 'Item type updated.');
    }

    /**
     * Delete an item type (project-owned only). Fails if items use this type.
     */
    public function destroyItemType(Workspace $workspace, Project $project, ItemType $itemType)
    {
        $this->authorize('update', $project);

        if ($itemType->project_id !== $project->id) {
            abort(404, 'Cannot delete default item types.');
        }

        if ($itemType->items()->exists()) {
            return back()->with('error', 'Cannot delete this type because some items use it. Reassign or remove those items first.');
        }

        $itemType->delete();

        return back()->with('success', 'Item type deleted.');
    }
}
