<?php

namespace App\Http\Controllers;

use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\Workspace;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SnippetController extends Controller
{
    // ── Collections ────────────────────────────────────────────────────────

    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);

        $collections = $workspace->snippetCollections()
            ->withCount('snippets')
            ->orderBy('position')
            ->get();

        return Inertia::render('Snippets/Index', [
            'workspace' => $workspace,
            'collections' => $collections,
        ]);
    }

    /** Lightweight JSON list used by the command-palette quick-create modal. */
    public function listCollections(Request $request, Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        $collections = $workspace->snippetCollections()
            ->select(['id', 'slug', 'name', 'icon', 'visibility'])
            ->orderBy('position')
            ->get();

        return response()->json($collections);
    }

    public function showCollection(Request $request, Workspace $workspace, SnippetCollection $collection): Response
    {
        $this->authorize('view', $workspace);
        abort_if($collection->workspace_id !== $workspace->id, 404);

        $collection->load(['snippets', 'creator:id,name,email']);

        $canEdit = $this->canEdit($workspace);

        return Inertia::render('Snippets/Show', [
            'workspace' => $workspace,
            'collection' => $collection,
            'canEdit' => $canEdit,
        ]);
    }

    public function storeCollection(Request $request, Workspace $workspace)
    {
        $this->authorize('create', [SnippetCollection::class, $workspace]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:2048',
            'visibility' => 'nullable|in:private,public',
        ]);

        $slug = Str::slug($validated['name']) ?: 'collection';
        $base = $slug;
        $i = 1;
        while (
            SnippetCollection::where('workspace_id', $workspace->id)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        $maxPos = SnippetCollection::where('workspace_id', $workspace->id)->max('position') ?? 0;

        $collection = SnippetCollection::create([
            ...$validated,
            'workspace_id' => $workspace->id,
            'created_by' => Auth::id(),
            'slug' => $slug,
            'icon' => $validated['icon'] ?? '📋',
            'visibility' => $validated['visibility'] ?? 'private',
            'position' => $maxPos + 1,
        ]);

        return redirect()->route('snippets.show', [$workspace->slug, $collection->slug]);
    }

    public function updateCollection(Request $request, Workspace $workspace, SnippetCollection $collection)
    {
        $this->authorize('update', $collection);
        abort_if($collection->workspace_id !== $workspace->id, 404);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:2048',
            'visibility' => 'nullable|in:private,public',
        ]);

        $collection->update($validated);

        return back();
    }

    public function destroyCollection(Request $request, Workspace $workspace, SnippetCollection $collection)
    {
        $this->authorize('delete', $collection);
        abort_if($collection->workspace_id !== $workspace->id, 404);

        $collection->delete();

        return redirect()->route('snippets.index', $workspace->slug);
    }

    // ── Snippets ───────────────────────────────────────────────────────────

    public function storeSnippet(Request $request, Workspace $workspace, SnippetCollection $collection)
    {
        $this->authorize('create', [SnippetCollection::class, $workspace]);
        abort_if($collection->workspace_id !== $workspace->id, 404);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'language' => 'nullable|string|max:50',
            'content' => 'nullable|string',
            'visibility' => 'nullable|in:inherit,private,public',
        ]);

        $maxPos = Snippet::where('collection_id', $collection->id)->max('position') ?? 0;

        Snippet::create([
            ...$validated,
            'collection_id' => $collection->id,
            'created_by' => Auth::id(),
            'language' => $validated['language'] ?? 'plaintext',
            'visibility' => $validated['visibility'] ?? 'inherit',
            'position' => $maxPos + 1,
        ]);

        return back();
    }

    public function updateSnippet(Request $request, Workspace $workspace, SnippetCollection $collection, Snippet $snippet)
    {
        $this->authorize('create', [SnippetCollection::class, $workspace]);
        abort_if($collection->workspace_id !== $workspace->id, 404);
        abort_if($snippet->collection_id !== $collection->id, 404);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'language' => 'nullable|string|max:50',
            'content' => 'nullable|string',
            'visibility' => 'nullable|in:inherit,private,public',
        ]);

        $snippet->update($validated);

        return back();
    }

    public function destroySnippet(Request $request, Workspace $workspace, SnippetCollection $collection, Snippet $snippet)
    {
        $this->authorize('create', [SnippetCollection::class, $workspace]);
        abort_if($collection->workspace_id !== $workspace->id, 404);
        abort_if($snippet->collection_id !== $collection->id, 404);

        $snippet->delete();

        return back();
    }

    // ── Public share views ─────────────────────────────────────────────────

    public function showPublicCollection(string $uuid): Response
    {
        $collection = SnippetCollection::where('uuid', $uuid)
            ->where('visibility', 'public')
            ->firstOrFail();

        $collection->load(['snippets' => fn ($q) => $q->where(function ($q2) {
            $q2->where('visibility', 'public')->orWhere('visibility', 'inherit');
        }), 'creator:id,name', 'workspace:id,name,slug']);

        return Inertia::render('Snippets/ShowPublic', [
            'collection' => $collection,
            'isPublic' => true,
        ]);
    }

    public function showPublicSnippet(string $uuid): Response
    {
        $snippet = Snippet::with(['collection:id,uuid,name,slug,visibility,workspace_id', 'creator:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Must be public itself, or inherit + collection is public
        abort_if($snippet->resolvedVisibility() !== 'public', 404);

        return Inertia::render('Snippets/ShowPublic', [
            'snippet' => $snippet,
            'collection' => $snippet->collection,
            'isPublic' => true,
            'singleMode' => true,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function uploadIconTemp(Request $request, Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        $request->validate([
            'icon' => 'required|file|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        $file = $request->file('icon');
        $ext = Uploads::imageExtension($file);
        $path = $file->storeAs('snippet-icons/temp', Str::uuid().'.'.$ext, 'public');

        return response()->json(['icon_display' => asset('storage/'.$path)]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function canEdit(Workspace $workspace): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }
        $role = $user->workspaceRole($workspace);

        return in_array($role, ['admin', 'editor']);
    }
}
