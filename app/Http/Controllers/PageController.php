<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PageController extends Controller
{
    /**
     * Store a newly created page or folder.
     */
    public function store(Request $request, Workspace $workspace, Project $project)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:page,folder',
            'parent_id' => 'nullable|exists:pages,id',
            'icon' => 'nullable|string|max:50',
            'content_type' => 'nullable|string|in:doc,excalidraw',
        ]);

        $content = null;
        if ($validated['type'] === 'page') {
            $contentType = $validated['content_type'] ?? 'doc';
            $content = $contentType === 'excalidraw'
                ? ['type' => 'excalidraw', 'elements' => [], 'appState' => []]
                : ['type' => 'doc', 'content' => []];
        }

        $defaultIcon = match (true) {
            $validated['type'] === 'folder' => '📁',
            ($validated['content_type'] ?? '') === 'excalidraw' => '✏️',
            default => '📄',
        };

        $page = $project->allPages()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'parent_id' => $validated['parent_id'] ?? null,
            'icon' => $validated['icon'] ?? $defaultIcon,
            'created_by' => Auth::id(),
            'content' => $content,
        ]);

        if ($request->wantsJson()) {
            return response()->json($page->load('children'));
        }

        return redirect()->route('pages.show', [
            $workspace->slug,
            $project->slug,
            $page->uuid,
        ]);
    }

    /**
     * Display the specified page.
     */
    public function show(Workspace $workspace, Project $project, Page $page)
    {
        $page->load(['parent', 'children' => function ($query) {
            $query->orderBy('type', 'desc')->orderBy('position');
        }, 'creator', 'lastEditor']);

        return Inertia::render('Pages/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'page' => $page,
            'breadcrumbs' => $page->path,
        ]);
    }

    /**
     * Full-page Excalidraw editor (Poznote-style: local IIFE bundle, opens in same tab).
     */
    public function excalidrawEditor(Workspace $workspace, Project $project, Page $page)
    {
        $content = $page->content ?? [];
        $initialData = [
            'elements' => $content['elements'] ?? [],
            'appState' => $content['appState'] ?? [],
            'files' => $content['files'] ?? [],
            'libraryItems' => $content['libraryItems'] ?? [],
        ];

        $excalidrawConfig = [
            'initialData' => $initialData,
            'saveUrl' => route('pages.excalidraw.save', [$workspace->slug, $project->slug, $page->uuid]),
            'returnUrl' => route('pages.show', [$workspace->slug, $project->slug, $page->uuid]) . '?from=excalidraw',
            'theme' => 'light',
        ];

        return view('excalidraw-editor', [
            'page' => $page,
            'workspace' => $workspace,
            'project' => $project,
            'excalidrawConfig' => $excalidrawConfig,
            'returnUrl' => $excalidrawConfig['returnUrl'],
        ]);
    }

    /**
     * Save Excalidraw diagram data for a page.
     */
    public function saveExcalidraw(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $validated = $request->validate([
            'diagram_data' => 'required',
        ]);

        $diagramData = is_string($validated['diagram_data'])
            ? $validated['diagram_data']
            : json_encode($validated['diagram_data']);
        $data = json_decode($diagramData, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return response()->json(['success' => false, 'message' => 'Invalid diagram data'], 400);
        }

        $content = [
            'type' => 'excalidraw',
            'elements' => $data['elements'] ?? [],
            'appState' => $data['appState'] ?? [],
            'files' => $data['files'] ?? [],
            'libraryItems' => $data['libraryItems'] ?? [],
        ];

        $page->update([
            'content' => $content,
            'last_edited_by' => Auth::id(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Update the specified page.
     */
    public function update(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'content' => 'nullable',
            'parent_id' => 'nullable|exists:pages,id',
            'status' => 'sometimes|in:draft,published,archived',
        ]);

        // Handle content - it can be a string (JSON) or an array
        if (isset($validated['content']) && is_string($validated['content'])) {
            $validated['content'] = json_decode($validated['content'], true);
        }

        // Prevent moving a folder into its own descendant
        if (isset($validated['parent_id']) && $validated['parent_id'] !== $page->parent_id) {
            $newParent = Page::find($validated['parent_id']);
            if ($newParent && $this->isDescendantOf($newParent, $page)) {
                return back()->withErrors(['parent_id' => 'Cannot move a folder into its own descendant.']);
            }
        }

        // Track who last edited
        $validated['last_edited_by'] = Auth::id();

        $page->update($validated);

        if ($request->wantsJson()) {
            return response()->json($page);
        }

        return back();
    }

    /**
     * Publish a page.
     */
    public function publish(Workspace $workspace, Project $project, Page $page)
    {
        $page->update([
            'status' => 'published',
            'published_at' => now(),
            'last_edited_by' => Auth::id(),
        ]);

        return back()->with('success', 'Page published successfully.');
    }

    /**
     * Unpublish a page (set to draft).
     */
    public function unpublish(Workspace $workspace, Project $project, Page $page)
    {
        $page->update([
            'status' => 'draft',
            'last_edited_by' => Auth::id(),
        ]);

        return back()->with('success', 'Page unpublished.');
    }

    /**
     * Archive a page.
     */
    public function archive(Workspace $workspace, Project $project, Page $page)
    {
        $page->update([
            'status' => 'archived',
            'last_edited_by' => Auth::id(),
        ]);

        return back()->with('success', 'Page archived.');
    }

    /**
     * Remove the specified page.
     */
    public function destroy(Workspace $workspace, Project $project, Page $page)
    {
        $parentId = $page->parent_id;
        $page->delete();

        // Redirect to parent or project
        if ($parentId) {
            $parent = Page::find($parentId);
            if ($parent) {
                return redirect()->route('pages.show', [$workspace->slug, $project->slug, $parent->uuid]);
            }
        }

        return redirect()->route('projects.show', [$workspace->slug, $project->slug]);
    }

    /**
     * Reorder pages within a parent.
     */
    public function reorder(Request $request, Workspace $workspace, Project $project)
    {
        $validated = $request->validate([
            'pages' => 'required|array',
            'pages.*.id' => 'required|exists:pages,id',
            'pages.*.position' => 'required|integer|min:0',
            'pages.*.parent_id' => 'nullable|exists:pages,id',
        ]);

        foreach ($validated['pages'] as $pageData) {
            Page::where('id', $pageData['id'])
                ->where('project_id', $project->id)
                ->update([
                    'position' => $pageData['position'],
                    'parent_id' => $pageData['parent_id'],
                ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get pages tree for sidebar.
     */
    public function tree(Workspace $workspace, Project $project)
    {
        $pages = $project->pages()
            ->with(['children' => function ($query) {
                $query->orderBy('type', 'desc')->orderBy('position');
            }])
            ->orderBy('type', 'desc')
            ->orderBy('position')
            ->get();

        return response()->json($pages);
    }

    /**
     * Check if a page is a descendant of another page.
     */
    private function isDescendantOf(Page $potentialDescendant, Page $potentialAncestor): bool
    {
        $current = $potentialDescendant;
        while ($current->parent_id) {
            if ($current->parent_id === $potentialAncestor->id) {
                return true;
            }
            $current = $current->parent;
        }
        return false;
    }
}
