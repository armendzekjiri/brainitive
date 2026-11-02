<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListBookmarkItemsRequest;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\StoreBookmarkGroupRequest;
use App\Http\Requests\AppApi\StoreBookmarkItemRequest;
use App\Http\Requests\AppApi\UpdateBookmarkGroupRequest;
use App\Http\Requests\AppApi\UpdateBookmarkItemRequest;
use App\Http\Resources\AppApi\BookmarkGroupCollection;
use App\Http\Resources\AppApi\BookmarkGroupDetailResource;
use App\Http\Resources\AppApi\BookmarkItemCollection;
use App\Http\Resources\AppApi\BookmarkItemResource;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Workspace;
use App\Support\Bookmarks\Bookmarks;
use App\Support\Entitlements\LimitReached;
use App\Support\Files\FileRoom;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — bookmark groups ("collections") and their items (BRAI-399): links, to-dos, notes, files.
 * Same rules as the web (BookmarkPolicy: a member sees their own groups, groups shared with them, public
 * ones and — as a workspace admin — all; creators, editor collaborators and admins change them) and the
 * same code (Bookmarks). A group the user can't see is a 404. Groups and items are addressed by id.
 * Collaborators and image icons: web only.
 */
#[Group('Bookmarks', weight: 180)]
class BookmarkController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    /**
     * The bookmark groups of a workspace the user can see, in the web's order, with item counts.
     *
     * @throws NotFoundHttpException not a member of the workspace (404)
     */
    public function index(ListRequest $request, Workspace $workspace): BookmarkGroupCollection
    {
        $this->visibleWorkspace($request, $workspace);
        $since = $request->updatedSince();

        $page = $this->withCounts($workspace->bookmarks()->visibleTo($request->user())->getQuery())
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Bookmark $group) => $group->setRelation('workspace', $workspace));

        return new BookmarkGroupCollection($page);
    }

    /**
     * Create a bookmark group at the end of the workspace.
     *
     * Workspace editors / admins (403 otherwise); `visibility` private (default) or public. With
     * `request_id` a retry answers 200 with the group the first call created.
     *
     * @throws NotFoundHttpException not a member of the workspace (404)
     */
    public function store(StoreBookmarkGroupRequest $request, Workspace $workspace): JsonResponse
    {
        $data = $request->safe()->except(['request_id']);

        [$group, $replayed] = $this->createOnce($request, 'bookmarks.store', Bookmark::class, ['workspace' => $workspace->id] + $data,
            fn () => Bookmarks::createGroup($workspace, $request->user(), $data),
            fn (Bookmark $group) => (bool) $this->visibleBookmarkGroup($request, $group->id));

        $group = $this->counted($group);
        if ($replayed) {
            return (new BookmarkGroupDetailResource($group))->response();
        }

        return (new BookmarkGroupDetailResource($group))->response()->setStatusCode(201);
    }

    /**
     * One bookmark group with what the user may do.
     *
     * @throws NotFoundHttpException the group doesn't exist or the user can't see it (404)
     */
    public function show(Request $request, string $group): BookmarkGroupDetailResource
    {
        return new BookmarkGroupDetailResource($this->counted($this->visibleBookmarkGroup($request, $group)));
    }

    /**
     * Change a group's name, description, emoji icon or visibility (only the sent fields).
     *
     * Its creator, editor collaborators and workspace admins (403 otherwise).
     *
     * @throws NotFoundHttpException the group doesn't exist or the user can't see it (404)
     */
    public function update(UpdateBookmarkGroupRequest $request, string $group): BookmarkGroupDetailResource
    {
        $group = $request->group();
        $data = $request->validated();
        if (isset($data['name'])) {
            $data['slug'] = Bookmarks::uniqueSlug($group->workspace, $data['name'], $group->id);
        }
        $group->update($data);

        return new BookmarkGroupDetailResource($this->counted($group));
    }

    /**
     * Delete a group with its items and files.
     *
     * Its creator or a workspace admin (403 otherwise).
     *
     * @throws NotFoundHttpException the group doesn't exist or the user can't see it (404)
     */
    public function destroy(Request $request, string $group): Response
    {
        $group = $this->visibleBookmarkGroup($request, $group);
        $this->authorize('delete', $group);
        Bookmarks::deleteGroup($group);

        return response()->noContent();
    }

    /**
     * The items of a group, in the web's order. `type` = link | todo | note | file.
     *
     * @throws NotFoundHttpException the group doesn't exist or the user can't see it (404)
     */
    public function items(ListBookmarkItemsRequest $request, string $group): BookmarkItemCollection
    {
        $group = $this->visibleBookmarkGroup($request, $group);
        $since = $request->updatedSince();

        $page = BookmarkItem::query()
            ->where('bookmark_id', $group->id)
            ->when($request->type(), fn ($q, string $type) => $q->where('type', $type))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        return new BookmarkItemCollection($page);
    }

    /**
     * Add a link, to-do, note or file at the end of a group.
     *
     * A file is sent as multipart/form-data (`file`, ≤ 200 MB). A link's title, favicon and description
     * are fetched in the background. Needs the right to change the group (403). With `request_id` a retry
     * answers 200 with the item the first call created.
     *
     * @throws NotFoundHttpException the group doesn't exist or the user can't see it (404)
     * @throws LimitReached a file and the workspace's file storage is full (402 limit_reached)
     */
    public function storeItem(StoreBookmarkItemRequest $request): JsonResponse
    {
        $group = $request->group();
        $data = $request->safe()->except(['request_id', 'file']);
        $file = $request->file('file');
        $payload = ['group' => $group->id, 'file' => $file ? [$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', $file->getRealPath())] : null] + $data;

        [$item, $replayed] = $this->createOnce($request, 'bookmarks.items.store', BookmarkItem::class, $payload,
            function () use ($group, $request, $data, $file) {
                // the plan's file storage, as the web's `file.room` (BRAI-416); a replay stores nothing
                FileRoom::ensure($group->workspace, $file);

                return Bookmarks::createItem($group, $request->user(), $data, $file);
            },
            fn (BookmarkItem $item) => (bool) $this->visibleBookmarkGroup($request, (int) $item->bookmark_id));

        if ($replayed) {
            return (new BookmarkItemResource($item))->response();
        }

        return (new BookmarkItemResource($item))->response()->setStatusCode(201);
    }

    /**
     * Change an item: title, URL, content, `is_completed` (to-dos).
     *
     * Needs the right to change the group (403).
     *
     * @throws NotFoundHttpException the group or item doesn't exist, or the user can't see it (404)
     */
    public function updateItem(UpdateBookmarkItemRequest $request, string $group, string $item): BookmarkItemResource
    {
        $item = $this->itemOf($request->group(), $item);
        $item->update($request->validated());

        return new BookmarkItemResource($item);
    }

    /**
     * Delete an item (and its file).
     *
     * Needs the right to change the group (403).
     *
     * @throws NotFoundHttpException the group or item doesn't exist, or the user can't see it (404)
     */
    public function destroyItem(Request $request, string $group, string $item): Response
    {
        $group = $this->visibleBookmarkGroup($request, $group);
        $this->authorize('update', $group);
        Bookmarks::deleteItem($this->itemOf($group, $item));

        return response()->noContent();
    }

    /**
     * @param  Builder<Bookmark>  $query
     * @return Builder<Bookmark>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'items as links_count' => fn ($q) => $q->where('type', 'link'),
            'items as files_count' => fn ($q) => $q->where('type', 'file'),
            'items as todos_count' => fn ($q) => $q->where('type', 'todo'),
            'items as notes_count' => fn ($q) => $q->where('type', 'note'),
            'items as todos_completed_count' => fn ($q) => $q->where('type', 'todo')->where('is_completed', true),
        ]);
    }

    private function counted(Bookmark $group): Bookmark
    {
        $group->loadMissing('workspace');
        $counted = $this->withCounts(Bookmark::query())->find($group->id);
        foreach (['links_count', 'files_count', 'todos_count', 'notes_count', 'todos_completed_count'] as $count) {
            $group->setAttribute($count, $counted?->getAttribute($count) ?? 0);
        }

        return $group;
    }

    private function itemOf(Bookmark $group, string $id): BookmarkItem
    {
        $item = ctype_digit($id) && Str::length($id) <= 18 ? BookmarkItem::where('bookmark_id', $group->id)->find((int) $id) : null;
        abort_unless($item instanceof BookmarkItem, 404);

        return $item;
    }
}
