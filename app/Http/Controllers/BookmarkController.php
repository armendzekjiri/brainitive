<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Bookmarks\Bookmarks;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BookmarkController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);

        $bookmarks = $workspace->bookmarks()->visibleTo($request->user())
            ->withCount([
                'items as links_count' => fn ($q) => $q->where('type', 'link'),
                'items as files_count' => fn ($q) => $q->where('type', 'file'),
                'items as todos_count' => fn ($q) => $q->where('type', 'todo'),
                'items as notes_count' => fn ($q) => $q->where('type', 'note'),
                'items as todos_completed_count' => fn ($q) => $q->where('type', 'todo')->where('is_completed', true),
            ])
            ->orderBy('position')
            ->get();

        return Inertia::render('Bookmarks/Index', [
            'workspace' => $workspace,
            'bookmarks' => $bookmarks,
        ]);
    }

    /** Lightweight JSON list used by the command-palette quick-create modal. */
    public function listBookmarks(Request $request, Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        $bookmarks = $workspace->bookmarks()->visibleTo($request->user())
            ->select(['id', 'slug', 'name', 'icon', 'visibility'])
            ->orderBy('position')
            ->get();

        return response()->json($bookmarks);
    }

    public function show(Request $request, Workspace $workspace, Bookmark $bookmark): Response
    {
        $this->authorize('view', $bookmark);

        $bookmark->load([
            'items' => fn ($q) => $q->orderBy('position'),
            'collaborators:id,name,email',
            'creator:id,name,email',
        ]);

        foreach ($bookmark->items->where('type', 'file') as $item) {
            if ($item->file_path) {
                $item->file_url = asset('storage/'.$item->file_path);
            }
        }

        $workspaceMembers = $workspace->users()->get(['users.id', 'name', 'email']);
        $canEdit = $bookmark->isEditableBy($request->user());

        return Inertia::render('Bookmarks/Show', [
            'workspace' => $workspace,
            'bookmark' => $bookmark,
            'workspaceMembers' => $workspaceMembers,
            'canEdit' => $canEdit,
        ]);
    }

    public function store(Request $request, Workspace $workspace)
    {
        $this->authorize('create', [Bookmark::class, $workspace]);

        $validated = $request->validate(Bookmarks::groupRules() + [
            'icon' => 'nullable|string|max:500',
            'icon_image' => 'nullable|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        $iconValue = $validated['icon'] ?? null;
        $isTempIcon = $iconValue && str_contains($iconValue, 'bookmark-icons/temp/');
        if (isset($validated['icon']) && (str_contains($validated['icon'], 'storage/') || str_starts_with($validated['icon'], 'http'))) {
            $iconValue = Str::after($validated['icon'], 'storage/');
            if (str_starts_with($iconValue, 'http')) {
                $iconValue = $validated['icon'];
            }
        }
        if ($isTempIcon) {
            $iconValue = null; // set after we move the temp file
        }

        $bookmark = Bookmarks::createGroup($workspace, $request->user(), ['icon' => $iconValue] + $validated);

        if ($request->hasFile('icon_image')) {
            $path = $request->file('icon_image')->storeAs('bookmark-icons/'.$bookmark->id, 'icon.'.Uploads::imageExtension($request->file('icon_image')), 'public');
            $bookmark->update(['icon' => $path]);
        } elseif ($isTempIcon && isset($validated['icon'])) {
            $tempPath = str_contains($validated['icon'], 'storage/') ? Str::after($validated['icon'], 'storage/') : $validated['icon'];
            // Only a file uploadIconTemp() created — no `..` tricks to move other people's files.
            if (preg_match('#^bookmark-icons/temp/[0-9a-f-]{36}\.(png|jpg|gif|webp)$#', $tempPath) && Storage::disk('public')->exists($tempPath)) {
                $ext = pathinfo($tempPath, PATHINFO_EXTENSION);
                $newPath = 'bookmark-icons/'.$bookmark->id.'/icon.'.$ext;
                Storage::disk('public')->move($tempPath, $newPath);
                $bookmark->update(['icon' => $newPath]);
            }
        }

        return redirect()->route('bookmarks.show', [$workspace->slug, $bookmark->slug])
            ->with('success', 'Bookmark created.');
    }

    public function update(Request $request, Workspace $workspace, Bookmark $bookmark)
    {
        $this->authorize('update', $bookmark);

        $validated = $request->validate(Bookmarks::groupRules(update: true) + [
            'icon' => 'nullable|string|max:500',
            'icon_image' => 'nullable|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Bookmarks::uniqueSlug($workspace, $validated['name'], $bookmark->id);
        }

        if (array_key_exists('icon', $validated) && $validated['icon'] && str_contains($validated['icon'], 'storage/')) {
            $validated['icon'] = Str::after($validated['icon'], 'storage/');
        }

        if ($request->hasFile('icon_image')) {
            $path = $request->file('icon_image')->storeAs('bookmark-icons/'.$bookmark->id, 'icon.'.Uploads::imageExtension($request->file('icon_image')), 'public');
            $validated['icon'] = $path;
        }

        $bookmark->update($validated);

        if ($request->wantsJson()) {
            return response()->json(['bookmark' => $bookmark->fresh()]);
        }

        return back()->with('success', 'Bookmark updated.');
    }

    public function uploadIcon(Request $request, Workspace $workspace, Bookmark $bookmark)
    {
        $this->authorize('update', $bookmark);

        if ($bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $request->validate([
            'icon' => 'required|file|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        $file = $request->file('icon');
        $ext = Uploads::imageExtension($file);
        $path = $file->storeAs('bookmark-icons/'.$bookmark->id, 'icon.'.$ext, 'public');
        $bookmark->update(['icon' => $path]);

        return response()->json(['icon_display' => $bookmark->fresh()->icon_display]);
    }

    /**
     * Upload icon for a bookmark that doesn't exist yet (create flow).
     * File is stored in bookmark-icons/temp/ and moved to bookmark-icons/{id}/ on store.
     */
    public function uploadIconTemp(Request $request, Workspace $workspace)
    {
        $this->authorize('create', [Bookmark::class, $workspace]);

        $request->validate([
            'icon' => 'required|file|image|mimes:png,jpeg,jpg|max:2048',
        ]);

        $file = $request->file('icon');
        $ext = Uploads::imageExtension($file);
        $path = $file->storeAs('bookmark-icons/temp', Str::uuid().'.'.$ext, 'public');

        return response()->json(['icon_display' => asset('storage/'.$path), 'icon_path' => $path]);
    }

    public function destroy(Request $request, Workspace $workspace, Bookmark $bookmark)
    {
        $this->authorize('delete', $bookmark);

        if ($bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        Bookmarks::deleteGroup($bookmark);

        return redirect()->route('bookmarks.index', [$workspace->slug])
            ->with('success', 'Bookmark deleted.');
    }

    public function storeItem(Request $request, Workspace $workspace, Bookmark $bookmark)
    {
        $this->authorize('update', $bookmark);

        if ($bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $item = Bookmarks::createItem($bookmark, $request->user(), $request->validate(Bookmarks::itemRules()), $request->file('file'));

        if ($request->wantsJson()) {
            $item->file_url = Bookmarks::fileUrl($item);

            return response()->json(['item' => $item]);
        }

        return back()->with('success', 'Item added.');
    }

    public function updateItem(Request $request, Workspace $workspace, Bookmark $bookmark, BookmarkItem $item)
    {
        $this->authorize('update', $bookmark);
        if ($item->bookmark_id !== $bookmark->id || $bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $item->update($request->validate(Bookmarks::itemUpdateRules()));

        if ($request->wantsJson()) {
            return response()->json(['item' => $item->fresh()]);
        }

        return back()->with('success', 'Item updated.');
    }

    public function toggleItem(Request $request, Workspace $workspace, Bookmark $bookmark, BookmarkItem $item)
    {
        $this->authorize('update', $bookmark);
        if ($item->bookmark_id !== $bookmark->id || $item->type !== 'todo' || $bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $item->update(['is_completed' => ! $item->is_completed]);

        if ($request->wantsJson()) {
            return response()->json(['item' => $item->fresh()]);
        }

        return back();
    }

    public function destroyItem(Request $request, Workspace $workspace, Bookmark $bookmark, BookmarkItem $item)
    {
        $this->authorize('update', $bookmark);
        if ($item->bookmark_id !== $bookmark->id || $bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        Bookmarks::deleteItem($item);

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('success', 'Item removed.');
    }

    public function inviteCollaborator(Request $request, Workspace $workspace, Bookmark $bookmark)
    {
        $this->authorize('manageCollaborators', $bookmark);

        if ($bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:viewer,editor',
        ]);

        $user = User::findOrFail($validated['user_id']);
        if (! $workspace->hasUser($user)) {
            return back()->with('error', 'User must be a member of this workspace.');
        }

        if ($bookmark->created_by === $user->id) {
            return back()->with('error', 'Creator is already the owner.');
        }

        $bookmark->collaborators()->syncWithoutDetaching([
            $user->id => ['role' => $validated['role']],
        ]);

        return back()->with('success', 'Collaborator added.');
    }

    public function updateCollaborator(Request $request, Workspace $workspace, Bookmark $bookmark, User $user)
    {
        $this->authorize('manageCollaborators', $bookmark);

        if ($bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $validated = $request->validate(['role' => 'required|in:viewer,editor']);

        if (! $bookmark->collaborators()->where('user_id', $user->id)->exists()) {
            abort(404);
        }

        $bookmark->collaborators()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return back()->with('success', 'Role updated.');
    }

    public function removeCollaborator(Request $request, Workspace $workspace, Bookmark $bookmark, User $user)
    {
        $this->authorize('manageCollaborators', $bookmark);

        if ($bookmark->workspace_id !== $workspace->id) {
            abort(404);
        }

        $bookmark->collaborators()->detach($user->id);

        return back()->with('success', 'Collaborator removed.');
    }

    /**
     * Public view (view only) - no auth required when visibility is public.
     */
    public function showPublic(Request $request, string $uuid): Response
    {
        $bookmark = Bookmark::where('uuid', $uuid)->whereHas('workspace')->firstOrFail(); // a live workspace (Codex web review P)

        if ($bookmark->visibility !== 'public') {
            abort(404);
        }

        $bookmark->load(['items' => fn ($q) => $q->orderBy('position'), 'creator:id,name']);

        // An explicit whitelist (Codex web review A): no ids of people, settings or internal metadata
        $items = $bookmark->items->map(fn (BookmarkItem $item) => [
            ...$item->only(['id', 'type', 'title', 'url', 'content', 'is_completed', 'position']),
            'metadata' => array_intersect_key((array) $item->metadata, array_flip(['title', 'description', 'favicon_url', 'image'])),
            'file_url' => $item->type === 'file' && $item->file_path ? asset('storage/'.$item->file_path) : null,
        ])->values();

        return Inertia::render('Bookmarks/ShowPublic', [
            'bookmark' => [
                ...$bookmark->only(['uuid', 'name', 'description', 'icon', 'icon_display', 'visibility']),
                'creator' => $bookmark->creator ? ['name' => $bookmark->creator->name] : null,
                'items' => $items,
            ],
        ]);
    }
}
