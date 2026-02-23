<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Label;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $projects = $workspace->projects()
            ->withCount('boards')
            ->with('creator:id,name')
            ->latest()
            ->get();

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
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request, Workspace $workspace)
    {
        $this->authorize('createProjects', $workspace);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:10|alpha|unique:projects,key',
            'description' => 'nullable|string|max:1000',
        ]);

        $project = $workspace->projects()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'key' => strtoupper($validated['key']),
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

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

        $project->load([
            'boards' => function ($query) {
                $query->orderBy('position');
            },
            'creator:id,name',
        ]);

        return Inertia::render('Projects/Show', [
            'workspace' => $workspace,
            'project' => $project,
        ]);
    }

    /**
     * Show the form for editing the specified project.
     */
    public function edit(Workspace $workspace, Project $project): Response
    {
        $this->authorize('update', $project);

        $project->load('labels');

        return Inertia::render('Projects/Edit', [
            'workspace' => $workspace,
            'project' => $project,
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
            'description' => 'nullable|string|max:1000',
        ]);

        $project->update($validated);

        return redirect()->route('projects.show', [$workspace, $project])
            ->with('success', 'Project updated successfully.');
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
     * Store a new label for the project.
     */
    public function storeLabel(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'required|string|max:7',
        ]);

        $project->labels()->create($validated);

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
}
