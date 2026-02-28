<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MentionNotification;
use App\Notifications\PageUpdatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
            'parent_id' => [
                'nullable',
                Rule::exists('pages', 'id')->where('project_id', $project->id),
            ],
            'icon' => 'nullable|string|max:2000000',
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

        $pageShowUrl = route('pages.show', [$workspace->slug, $project->slug, $page->uuid]);

        // Add ?new=1 for new whiteboards so the client can redirect to the Excalidraw editor
        // (we cannot redirect directly to pages.excalidraw here because it is a non-Inertia
        // blade view and Inertia's form handler would try to render it inside the React app).
        if (($validated['content_type'] ?? 'doc') === 'excalidraw') {
            $pageShowUrl .= '?new=1';
        }

        return redirect($pageShowUrl);
    }

    /**
     * Display the specified page.
     */
    public function show(Workspace $workspace, Project $project, Page $page)
    {
        $page->load(['parent', 'children' => function ($query) {
            $query->orderBy('type', 'desc')->orderBy('position');
        }, 'creator', 'lastEditor', 'watchers']);

        return Inertia::render('Pages/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'page' => $page,
            'breadcrumbs' => $page->path,
        ]);
    }

    /**
     * Full-page Excalidraw editor (Poznote-style: local IIFE bundle, opens in same tab).
     * Supports embed mode: ?embed=1&diagram_id=xxx&block_id=yyy for embedding in a document.
     */
    public function excalidrawEditor(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $isEmbed = $request->boolean('embed');
        $diagramId = $request->query('diagram_id', '');
        $blockId = $request->query('block_id', '');

        $content = $page->content ?? [];
        if ($isEmbed && $diagramId && isset($content['excalidrawEmbeds'][$diagramId])) {
            $embedData = $content['excalidrawEmbeds'][$diagramId];
            $initialData = [
                'elements' => $embedData['elements'] ?? [],
                'appState' => $embedData['appState'] ?? [],
                'files' => $embedData['files'] ?? [],
                'libraryItems' => $embedData['libraryItems'] ?? [],
            ];
        } elseif (! $isEmbed && isset($content['type']) && $content['type'] === 'excalidraw') {
            $initialData = [
                'elements' => $content['elements'] ?? [],
                'appState' => $content['appState'] ?? [],
                'files' => $content['files'] ?? [],
                'libraryItems' => $content['libraryItems'] ?? [],
            ];
        } else {
            $initialData = [
                'elements' => [],
                'appState' => [],
                'files' => [],
                'libraryItems' => [],
            ];
        }

        if ($isEmbed) {
            $saveUrl = route('pages.excalidraw.embed.save', [$workspace->slug, $project->slug, $page->uuid]);
            $returnUrl = route('pages.show', [$workspace->slug, $project->slug, $page->uuid])
                . '?from=excalidraw_embed&block_id=' . urlencode($blockId) . '&diagram_id=' . urlencode($diagramId);
        } else {
            $saveUrl = route('pages.excalidraw.save', [$workspace->slug, $project->slug, $page->uuid]);
            $returnUrl = route('pages.show', [$workspace->slug, $project->slug, $page->uuid]) . '?from=excalidraw';
        }

        $excalidrawConfig = [
            'initialData' => $initialData,
            'saveUrl' => $saveUrl,
            'returnUrl' => $returnUrl,
            'theme' => 'light',
            'isEmbed' => $isEmbed,
            'diagramId' => $diagramId,
            'blockId' => $blockId,
        ];

        return view('excalidraw-editor', [
            'page' => $page,
            'workspace' => $workspace,
            'project' => $project,
            'excalidrawConfig' => $excalidrawConfig,
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * Save Excalidraw diagram data for a page (standalone whiteboard).
     * Optional: image (PNG) for preview thumbnail when saving via "Save & exit".
     */
    public function saveExcalidraw(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $validated = $request->validate([
            'diagram_data' => 'required',
            'image' => 'nullable|image|mimes:png|max:10240',
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

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store(
                'excalidraw-previews',
                'public'
            );
            $content['previewImageUrl'] = asset('storage/' . $path);
        } elseif (is_array($page->content ?? null) && ! empty(($page->content ?? [])['previewImageUrl'])) {
            $content['previewImageUrl'] = $page->content['previewImageUrl'];
        }

        $page->update([
            'content' => $content,
            'last_edited_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'previewImageUrl' => $content['previewImageUrl'] ?? null]);
    }

    /**
     * Save an embedded Excalidraw diagram (image + scene data) into a document page.
     */
    public function saveExcalidrawEmbed(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $validated = $request->validate([
            'diagram_data' => 'required',
            'diagram_id' => 'required|string|max:255',
            'block_id' => 'required|string|max:255',
            'image' => 'required|image|mimes:png|max:10240',
        ]);

        $diagramData = is_string($validated['diagram_data'])
            ? $validated['diagram_data']
            : json_encode($validated['diagram_data']);
        $data = json_decode($diagramData, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return response()->json(['success' => false, 'message' => 'Invalid diagram data'], 400);
        }

        $content = $page->content ?? [];
        if (! is_array($content)) {
            $content = [];
        }
        if (! isset($content['excalidrawEmbeds']) || ! is_array($content['excalidrawEmbeds'])) {
            $content['excalidrawEmbeds'] = [];
        }
        $content['excalidrawEmbeds'][$validated['diagram_id']] = [
            'elements' => $data['elements'] ?? [],
            'appState' => $data['appState'] ?? [],
            'files' => $data['files'] ?? [],
            'libraryItems' => $data['libraryItems'] ?? [],
        ];

        $path = $request->file('image')->store(
            'excalidraw-embeds/' . $page->id,
            'public'
        );
        $imageUrl = asset('storage/' . $path);

        $page->update([
            'content' => $content,
            'last_edited_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'imageUrl' => $imageUrl,
            'diagramId' => $validated['diagram_id'],
        ]);
    }

    /**
     * Update the specified page.
     */
    public function update(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:2000000',
            'content' => 'nullable',
            'parent_id' => 'nullable|exists:pages,id',
            'status' => 'sometimes|in:draft,published,archived',
        ]);

        // Handle content - it can be a string (JSON) or an array
        if (isset($validated['content'])) {
            $newContent = is_string($validated['content']) ? json_decode($validated['content'], true) : $validated['content'];
            if (is_array($newContent)) {
                $current = $page->content ?? [];
                if (is_array($current) && ! empty($current['excalidrawEmbeds'])) {
                    $newContent['excalidrawEmbeds'] = $current['excalidrawEmbeds'];
                }
                $validated['content'] = $newContent;
            }
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
     * Toggle watch status on a page.
     */
    public function toggleWatch(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'watch'   => 'required|boolean',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($validated['watch']) {
            $page->addWatcher($user);
        } else {
            $page->removeWatcher($user);
        }

        return back();
    }

    /**
     * Explicit update — same as update() but also notifies watchers.
     * Called when the user clicks the "Update" button (as opposed to autosave).
     */
    public function explicitUpdate(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        // Reuse the same update logic
        $this->update($request, $workspace, $project, $page);

        // Notify watchers
        $page->load('watchers');
        $actorId = $request->user()->id;

        foreach ($page->watchers as $watcher) {
            if ($watcher->id === $actorId) continue;
            $watcher->notify(new PageUpdatedNotification($page, $request->user()));
        }

        return back()->with('success', 'Page updated.');
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

    /**
     * Extract mentions from HTML/JSON content and dispatch notifications.
     */
    private function processMentions(?string $content, Page $page, User $actor)
    {
        if (empty($content)) {
            return;
        }

        \Log::info('Page processMentions starting extraction', ['length' => strlen($content)]);

        // 1. Lexical JSON / escaped format: "userId":123 or \"userId\":123 or "userId\":\"123"
        preg_match_all('/"userId"(?:\\\\)?"?\s*:\s*(?:\\\\)?"?(\d+)/', $content, $jsonMatches);
        
        // 2. Regular HTML format: data-mention-user-id="123"
        preg_match_all('/data-mention-user-id="(\d+)"/', $content, $htmlMatches);

        // 3. Fallback escaped HTML: data-mention-user-id\":\"123\"
        preg_match_all('/data-mention-user-id(?:\\\\)?"\s*:\s*(?:\\\\)?"?(\d+)/', $content, $escapedMatches);
        
        $userIds = array_unique(array_merge(
            $jsonMatches[1] ?? [],
            $htmlMatches[1] ?? [], 
            $escapedMatches[1] ?? []
        ));
        
        \Log::info('Page processMentions extraction results', [
            'json_count' => count($jsonMatches[1] ?? []),
            'html_count' => count($htmlMatches[1] ?? []),
            'escaped_count' => count($escapedMatches[1] ?? []),
            'final_ids' => $userIds
        ]);

        if (empty($userIds)) {
            return;
        }
        
        $workspace = $page->project->workspace;
        $project = $page->project;
        
        $pageUrl = route('pages.show', [$workspace->slug, $project->slug, $page->uuid]);

        foreach ($userIds as $userId) {
            // Don't notify the person who made the mention
            if ((int) $userId === $actor->id) {
                continue;
            }

            $user = User::find($userId);
            if ($user && $user->hasProjectAccess($project)) {
                $user->notify(new MentionNotification(
                    contextType: 'page',
                    contextTitle: $page->name,
                    contextUrl: $pageUrl,
                    actor: $actor
                ));
            }
        }
    }
}
