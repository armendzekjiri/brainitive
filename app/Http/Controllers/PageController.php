<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MentionNotification;
use App\Notifications\PageUpdatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PageController extends Controller
{
    /**
     * Store a newly created page or folder.
     */
    public function store(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('create', [Page::class, $project]);

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
    public function show(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('view', $page);

        $user = $request->user();

        $page->load(['parent', 'children' => function ($query) use ($user) {
            $query->visibleTo($user)->orderBy('type', 'desc')->orderBy('position');
        }, 'creator', 'lastEditor', 'draftEditor', 'watchers']);

        $canUpdate = $user->can('update', $page);

        // Readers care about when the live version changed, not about draft autosaves
        $page->setAttribute('last_published_at', $page->versions()->value('created_at'));

        // Unpublished changes are shared between editors only; readers get the live page
        if ($canUpdate) {
            $page->makeVisible(['draft_content', 'draft_name', 'draft_icon']);
        } else {
            $page->makeHidden(['draft_updated_by', 'draftEditor']);
        }

        return Inertia::render('Pages/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'page' => $page,
            'breadcrumbs' => $page->path,
            'can' => [
                'update' => $canUpdate,
                'delete' => $user->can('delete', $page),
                'create' => $user->can('create', [Page::class, $project]),
            ],
        ]);
    }

    /**
     * Full-page Excalidraw editor (Poznote-style: local IIFE bundle, opens in same tab).
     * Supports embed mode: ?embed=1&diagram_id=xxx&block_id=yyy for embedding in a document.
     */
    public function excalidrawEditor(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

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
                .'?from=excalidraw_embed&block_id='.urlencode($blockId).'&diagram_id='.urlencode($diagramId);
        } else {
            $saveUrl = route('pages.excalidraw.save', [$workspace->slug, $project->slug, $page->uuid]);
            $returnUrl = route('pages.show', [$workspace->slug, $project->slug, $page->uuid]).'?from=excalidraw';
        }

        $excalidrawConfig = [
            'initialData' => $initialData,
            'saveUrl' => $saveUrl,
            'returnUrl' => $returnUrl,
            'theme' => 'light',
            'isEmbed' => $isEmbed,
            'diagramId' => $diagramId,
            'blockId' => $blockId,
            // Conflict guard + autosave for standalone whiteboards (embeds keep the old flow)
            'editRevision' => $page->edit_revision,
            'autosave' => ! $isEmbed,
            'historyUrl' => route('pages.show', [$workspace->slug, $project->slug, $page->uuid]).'?history=1',
            // A brand-new, empty whiteboard opens the template picker
            'isNew' => ! $isEmbed && $request->boolean('new') && empty($initialData['elements']),
            // Issue cards + stickies → issues
            'issuesUrl' => route('pages.whiteboard.issues', [$workspace->slug, $project->slug, $page->uuid]),
            'createIssuesUrl' => route('pages.whiteboard.issues.store', [$workspace->slug, $project->slug, $page->uuid]),
            'boards' => $isEmbed ? [] : $project->boards()->get(['id', 'name', 'project_id'])
                ->each(fn ($board) => $board->setRelation('project', $project))
                ->filter(fn ($board) => $request->user()->can('createItems', $board))
                ->map(fn ($board) => ['id' => $board->id, 'name' => $board->name])
                ->values(),
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
        $this->authorize('update', $page);

        $validated = $request->validate([
            'diagram_data' => 'required',
            'image' => 'nullable|image|mimes:png|max:10240',
            'base_revision' => 'nullable|integer|min:0',
            'force' => 'boolean',
            'checkpoint' => 'boolean',
        ]);

        // Someone else saved since this editor loaded → don't overwrite silently
        if ($conflict = $this->draftConflict($page, $validated)) {
            return response()->json(['success' => false] + $conflict->getData(true), 409);
        }

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
            $content['previewImageUrl'] = asset('storage/'.$path);
        } elseif (is_array($page->content ?? null) && ! empty(($page->content ?? [])['previewImageUrl'])) {
            $content['previewImageUrl'] = $page->content['previewImageUrl'];
        }

        // "Save & exit" (sends a preview image) is an explicit checkpoint in the history
        $version = $page->saveWhiteboard($request->user(), $content, $request->boolean('checkpoint') || $request->hasFile('image'));

        return response()->json([
            'success' => true,
            'previewImageUrl' => $content['previewImageUrl'] ?? null,
            'edit_revision' => $page->edit_revision,
            'version' => $version?->version,
        ]);
    }

    /**
     * Save an embedded Excalidraw diagram (image + scene data) into a document page.
     */
    public function saveExcalidrawEmbed(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

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
            'excalidraw-embeds/'.$page->id,
            'public'
        );
        $imageUrl = asset('storage/'.$path);

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
     * Update page metadata (name, icon, parent). Content edits go through saveDraft() so
     * a published page never changes for readers before "Update".
     */
    public function update(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:2000000',
            'content' => 'nullable',
            'parent_id' => [
                'nullable',
                Rule::exists('pages', 'id')->where('project_id', $project->id),
            ],
        ]);

        if (array_key_exists('content', $validated)) {
            $page->saveDraft($request->user(), ['content' => $this->decodeContent($validated['content'])]);
            unset($validated['content']);
        }

        // Prevent moving a folder into its own descendant
        if (isset($validated['parent_id']) && $validated['parent_id'] !== $page->parent_id) {
            $newParent = Page::find($validated['parent_id']);
            if ($newParent && $this->isDescendantOf($newParent, $page)) {
                return back()->withErrors(['parent_id' => 'Cannot move a folder into its own descendant.']);
            }
        }

        if ($validated !== []) {
            $page->update($validated + ['last_edited_by' => Auth::id()]);
        }

        if ($request->wantsJson()) {
            return response()->json($page);
        }

        return back();
    }

    /**
     * Autosave from the editor (JSON). Rejects with 409 when someone else saved since the
     * revision the client started from, unless the client chose to overwrite.
     */
    public function saveDraft(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'content' => 'sometimes|nullable',
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'sometimes|nullable|string|max:2000000',
            'base_revision' => 'nullable|integer|min:0',
            'force' => 'boolean',
        ]);

        if ($conflict = $this->draftConflict($page, $validated)) {
            return $conflict;
        }

        $page->saveDraft($request->user(), $this->draftChanges($validated));

        return response()->json($this->draftState($page->fresh()));
    }

    /**
     * Drop the unpublished changes of a published page.
     */
    public function discardDraft(Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

        $page->discardDraft();

        return back()->with('success', 'Unpublished changes discarded.');
    }

    /**
     * Heartbeat for "who's here": records the caller and returns the others on the page plus
     * the current revision, so editors notice concurrent saves without websockets.
     */
    public function presence(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('view', $page);

        $validated = $request->validate(['editing' => 'boolean', 'leave' => 'boolean']);
        $user = $request->user();
        $key = "page-presence:{$page->id}";
        $now = now()->timestamp;

        $present = collect(Cache::get($key, []))
            ->filter(fn (array $entry) => $now - $entry['seen'] < self::PRESENCE_TTL_SECONDS)
            ->except($user->id);

        if (! ($validated['leave'] ?? false)) {
            $present->put($user->id, [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'editing' => (bool) ($validated['editing'] ?? false),
                'seen' => $now,
            ]);
        }

        Cache::put($key, $present->all(), self::PRESENCE_TTL_SECONDS * 2);

        return response()->json([
            'users' => $present->except($user->id)->values()->map(fn (array $entry) => collect($entry)->except('seen'))->all(),
        ] + $this->draftState($page->fresh()));
    }

    /**
     * Publish a page.
     */
    public function publish(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

        $validated = $request->validate(['message' => 'nullable|string|max:255']);

        if ($page->isPublished()) {
            return $this->publishChanges($request, $workspace, $project, $page);
        }

        $page->publish($request->user(), $validated['message'] ?? null);

        return back()->with('success', 'Page published.');
    }

    /**
     * "Update" on a published page: saves the editor's latest state, makes the unpublished
     * changes live as a new version and (optionally) tells the watchers.
     */
    public function publishChanges(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'content' => 'sometimes|nullable',
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'sometimes|nullable|string|max:2000000',
            'message' => 'nullable|string|max:255',
            'notify' => 'boolean',
            'base_revision' => 'nullable|integer|min:0',
            'force' => 'boolean',
        ]);

        if (! $page->isPublished()) {
            return back()->withErrors(['page' => 'Only published pages can be updated. Publish the page first.']);
        }

        if ($this->draftConflict($page, $validated)) {
            return back()->withErrors(['page' => 'Someone else changed this page while you were editing. Reload to see their changes.']);
        }

        $user = $request->user();
        $changes = $this->draftChanges($validated);
        if ($changes !== []) {
            $page->saveDraft($user, $changes);
        }

        if (! $page->hasUnpublishedChanges()) {
            return back()->with('success', 'No changes to publish.');
        }

        $version = $page->publishChanges($user, $validated['message'] ?? null);

        if ($validated['notify'] ?? true) {
            $page->load('watchers');
            foreach ($page->watchers as $watcher) {
                if ($watcher->id === $user->id) {
                    continue;
                }
                $watcher->notify(new PageUpdatedNotification($page, $user));
            }
        }

        return back()->with('success', "Page updated to version {$version->version}.");
    }

    /**
     * Unpublish a page (set to draft).
     */
    public function unpublish(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

        $page->unpublish($request->user());

        return back()->with('success', 'Page unpublished.');
    }

    /**
     * Archive a page.
     */
    public function archive(Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('update', $page);

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
        $this->authorize('delete', $page);

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
        $this->authorize('create', [Page::class, $project]);

        $validated = $request->validate([
            'pages' => 'required|array',
            'pages.*.id' => ['required', Rule::exists('pages', 'id')->where('project_id', $project->id)],
            'pages.*.position' => 'required|integer|min:0',
            'pages.*.parent_id' => ['nullable', Rule::exists('pages', 'id')->where('project_id', $project->id)],
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
    public function tree(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('view', $project);

        $user = $request->user();

        $pages = $project->pages()
            ->visibleTo($user)
            ->with(['children' => function ($query) use ($user) {
                $query->visibleTo($user)->orderBy('type', 'desc')->orderBy('position');
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
        $this->authorize('view', $page);

        $validated = $request->validate([
            'watch' => 'required|boolean',
        ]);

        // Users can only (un)watch for themselves
        $user = $request->user();

        if ($validated['watch']) {
            $page->addWatcher($user);
        } else {
            $page->removeWatcher($user);
        }

        return back();
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
            'final_ids' => $userIds,
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

    /**
     * Duplicate a page.
     */
    public function duplicate(Request $request, Workspace $workspace, Project $project, Page $page)
    {
        $this->authorize('view', $page);
        $this->authorize('create', [Page::class, $project]);

        $validated = $request->validate([
            'with_subpages' => 'boolean',
        ]);
        $withSubpages = $validated['with_subpages'] ?? false;

        $new_page = $this->performPageDuplication($page, $request->user()->id, $withSubpages, true);

        return redirect()->route('pages.show', [$workspace->slug, $project->slug, $new_page->uuid])
            ->with('success', 'Page duplicated successfully.');
    }

    /**
     * Recursively duplicate a page and optionally its children.
     */
    private function performPageDuplication(Page $page, int $userId, bool $withSubpages = false, bool $isRoot = false, ?int $newParentId = null): Page
    {
        $newPage = $page->replicate([
            'uuid',
            'slug',
            'status',
            'published_at',
            'created_by',
            'last_edited_by',
            'created_at',
            'updated_at',
            'position',
            'parent_id',
            'draft_content',
            'draft_name',
            'draft_icon',
            'draft_updated_at',
            'draft_updated_by',
            'edit_revision',
            'current_version',
        ]);

        if ($isRoot) {
            $newPage->name = 'Copy of '.$page->name;
        }

        $newPage->status = 'draft';
        $newPage->created_by = $userId;
        $newPage->last_edited_by = $userId;
        $newPage->parent_id = $newParentId ?? $page->parent_id;
        $newPage->position = 0; // Boot method assigns to end
        $newPage->save();

        if ($withSubpages) {
            $children = $page->children()->visibleTo(User::findOrFail($userId))->get();
            foreach ($children as $childPage) {
                $this->performPageDuplication($childPage, $userId, true, false, $newPage->id);
            }
        }

        return $newPage;
    }

    /**
     * Presence entries older than this are considered gone.
     */
    private const PRESENCE_TTL_SECONDS = 45;

    /**
     * Content arrives as a JSON string from the editor or as an array from API clients.
     *
     * @return array<string, mixed>|null
     */
    private function decodeContent(mixed $content): ?array
    {
        $decoded = is_string($content) ? json_decode($content, true) : $content;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{content?: array<string, mixed>|null, name?: string, icon?: string|null}
     */
    private function draftChanges(array $validated): array
    {
        $changes = [];
        if (array_key_exists('content', $validated)) {
            $changes['content'] = $this->decodeContent($validated['content']);
        }
        if (isset($validated['name'])) {
            $changes['name'] = $validated['name'];
        }
        if (array_key_exists('icon', $validated)) {
            $changes['icon'] = $validated['icon'];
        }

        return $changes;
    }

    /**
     * 409 response when the page moved past the client's base revision (and no force).
     *
     * @param  array<string, mixed>  $validated
     */
    private function draftConflict(Page $page, array $validated): ?JsonResponse
    {
        $baseRevision = $validated['base_revision'] ?? null;
        if ($baseRevision === null || ($validated['force'] ?? false) || (int) $baseRevision === $page->edit_revision) {
            return null;
        }

        $editor = $page->isPublished() && ! $page->is_whiteboard ? $page->draftEditor : $page->lastEditor;

        return response()->json([
            'message' => 'This page was changed by someone else while you were editing.',
            'conflict' => [
                'edit_revision' => $page->edit_revision,
                'updated_by' => $editor?->only(['id', 'name']),
                'updated_at' => ($page->draft_updated_at ?? $page->updated_at)?->toIso8601String(),
            ],
        ], 409);
    }

    /**
     * @return array{edit_revision: int, has_unpublished_changes: bool, draft_updated_at: string|null, draft_updated_by: array{id: int, name: string}|null}
     */
    private function draftState(Page $page): array
    {
        $editor = $page->isPublished() && ! $page->is_whiteboard ? $page->draftEditor : $page->lastEditor;

        return [
            'edit_revision' => $page->edit_revision,
            'has_unpublished_changes' => $page->hasUnpublishedChanges(),
            'draft_updated_at' => ($page->draft_updated_at ?? $page->updated_at)?->toIso8601String(),
            'draft_updated_by' => $editor?->only(['id', 'name']),
        ];
    }
}
