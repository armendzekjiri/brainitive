<?php

namespace App\Http\Controllers;

use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\Workspace;
use App\Support\Snippets\Snippets;
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

        $collection = Snippets::createCollection($workspace, $request->user(), $request->validate(Snippets::collectionRules()));

        return redirect()->route('snippets.show', [$workspace->slug, $collection->slug]);
    }

    public function updateCollection(Request $request, Workspace $workspace, SnippetCollection $collection)
    {
        $this->authorize('update', $collection);
        abort_if($collection->workspace_id !== $workspace->id, 404);

        $collection->update($request->validate(Snippets::collectionRules(update: true)));

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

        Snippets::createSnippet($collection, $request->user(), $request->validate(Snippets::snippetRules()));

        return back();
    }

    public function updateSnippet(Request $request, Workspace $workspace, SnippetCollection $collection, Snippet $snippet)
    {
        $this->authorize('create', [SnippetCollection::class, $workspace]);
        abort_if($collection->workspace_id !== $workspace->id, 404);
        abort_if($snippet->collection_id !== $collection->id, 404);

        $snippet->update($request->validate(Snippets::snippetRules(update: true)));

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
        $collection = SnippetCollection::where('uuid', $uuid)->whereHas('workspace') // a live workspace (P)
            ->where('visibility', 'public')
            ->firstOrFail();

        $snippets = $collection->snippets()->where(function ($q) {
            $q->where('visibility', 'public')->orWhere('visibility', 'inherit');
        })->get();

        // An explicit whitelist (Codex web review A): no workspace, ids of people or internal settings
        return Inertia::render('Snippets/ShowPublic', [
            'collection' => [...self::publicCollection($collection), 'snippets' => $snippets->map(fn (Snippet $s) => self::publicSnippet($s))->values()],
            'isPublic' => true,
        ]);
    }

    public function showPublicSnippet(string $uuid): Response
    {
        $snippet = Snippet::with(['collection:id,uuid,name,slug,visibility,workspace_id', 'creator:id,name'])
            ->where('uuid', $uuid)
            ->whereHas('collection.workspace') // a live workspace (Codex web review P)
            ->firstOrFail();

        // Must be public itself, or inherit + collection is public
        abort_if($snippet->resolvedVisibility() !== 'public', 404);

        return Inertia::render('Snippets/ShowPublic', [
            'snippet' => self::publicSnippet($snippet),
            'collection' => self::publicCollection($snippet->collection),
            'isPublic' => true,
            'singleMode' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private static function publicCollection(SnippetCollection $collection): array
    {
        return $collection->only(['uuid', 'slug', 'name', 'description', 'icon', 'visibility']);
    }

    /** @return array<string, mixed> */
    private static function publicSnippet(Snippet $snippet): array
    {
        return $snippet->only(['id', 'uuid', 'slug', 'title', 'description', 'language', 'content', 'visibility']);
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
