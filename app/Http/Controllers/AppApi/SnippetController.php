<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\StoreSnippetCollectionRequest;
use App\Http\Requests\AppApi\StoreSnippetRequest;
use App\Http\Requests\AppApi\UpdateSnippetCollectionRequest;
use App\Http\Requests\AppApi\UpdateSnippetRequest;
use App\Http\Resources\AppApi\SnippetCollectionCollection;
use App\Http\Resources\AppApi\SnippetCollectionDetailResource;
use App\Http\Resources\AppApi\SnippetResource;
use App\Http\Resources\AppApi\SnippetsCollection;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\Workspace;
use App\Support\Snippets\Snippets;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — snippet collections and snippets (BRAI-399), for the desktop quick switcher's "copy
 * snippet" and the mobile snippets screen. Same rules as the web: every workspace member reads them;
 * workspace editors / admins add, change and delete snippets; a collection is changed by its creator or
 * a workspace admin (SnippetPolicy). Not a member → 404. Collections and snippets are addressed by id.
 */
#[Group('Snippets', weight: 170)]
class SnippetController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    /**
     * The snippet collections of a workspace, in the web's order.
     *
     * @throws NotFoundHttpException not a member of the workspace (404)
     */
    public function index(ListRequest $request, Workspace $workspace): SnippetCollectionCollection
    {
        $this->visibleWorkspace($request, $workspace);
        $since = $request->updatedSince();

        $page = $workspace->snippetCollections()
            ->withCount('snippets')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (SnippetCollection $collection) => $collection->setRelation('workspace', $workspace));

        return new SnippetCollectionCollection($page);
    }

    /**
     * Create a snippet collection at the end of the workspace.
     *
     * Workspace editors / admins (403 otherwise). With `request_id` a retry answers 200 with the
     * collection the first call created.
     *
     * @throws NotFoundHttpException not a member of the workspace (404)
     */
    public function store(StoreSnippetCollectionRequest $request, Workspace $workspace): JsonResponse
    {
        $data = $request->safe()->except(['request_id']);

        [$collection, $replayed] = $this->createOnce($request, 'snippet-collections.store', SnippetCollection::class, ['workspace' => $workspace->id] + $data,
            fn () => Snippets::createCollection($workspace, $request->user(), $data),
            fn (SnippetCollection $collection) => (bool) $this->visibleSnippetCollection($request, $collection->id));

        $collection = $this->withCount($collection);
        if ($replayed) {
            return (new SnippetCollectionDetailResource($collection))->response();
        }

        return (new SnippetCollectionDetailResource($collection))->response()->setStatusCode(201);
    }

    /**
     * One snippet collection with what the user may do.
     *
     * @throws NotFoundHttpException the collection doesn't exist or the user isn't a member of its workspace (404)
     */
    public function show(Request $request, string $collection): SnippetCollectionDetailResource
    {
        return new SnippetCollectionDetailResource($this->withCount($this->visibleSnippetCollection($request, $collection)));
    }

    /**
     * Change a collection (only the sent fields).
     *
     * Its creator or a workspace admin (403 otherwise).
     *
     * @throws NotFoundHttpException the collection doesn't exist or the user isn't a member of its workspace (404)
     */
    public function update(UpdateSnippetCollectionRequest $request, string $collection): SnippetCollectionDetailResource
    {
        $collection = $request->collection();
        $collection->update($request->validated());

        return new SnippetCollectionDetailResource($this->withCount($collection));
    }

    /**
     * Delete a collection with its snippets.
     *
     * Its creator or a workspace admin (403 otherwise).
     *
     * @throws NotFoundHttpException the collection doesn't exist or the user isn't a member of its workspace (404)
     */
    public function destroy(Request $request, string $collection): Response
    {
        $collection = $this->visibleSnippetCollection($request, $collection);
        $this->authorize('delete', $collection);
        $collection->delete();

        return response()->noContent();
    }

    /**
     * The snippets of a collection, in the web's order, with their content.
     *
     * @throws NotFoundHttpException the collection doesn't exist or the user isn't a member of its workspace (404)
     */
    public function snippets(ListRequest $request, string $collection): SnippetsCollection
    {
        $collection = $this->visibleSnippetCollection($request, $collection);
        $since = $request->updatedSince();

        $page = Snippet::query()
            ->where('collection_id', $collection->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Snippet $snippet) => $snippet->setRelation('collection', $collection));

        return new SnippetsCollection($page);
    }

    /**
     * Add a snippet at the end of a collection.
     *
     * Workspace editors / admins (403 otherwise). With `request_id` a retry answers 200 with the snippet
     * the first call created.
     *
     * @throws NotFoundHttpException the collection doesn't exist or the user isn't a member of its workspace (404)
     */
    public function storeSnippet(StoreSnippetRequest $request): JsonResponse
    {
        $collection = $request->collection();
        $data = $request->safe()->except(['request_id']);

        [$snippet, $replayed] = $this->createOnce($request, 'snippets.store', Snippet::class, ['collection' => $collection->id] + $data,
            fn () => Snippets::createSnippet($collection, $request->user(), $data),
            fn (Snippet $snippet) => (bool) $this->visibleSnippet($request, $snippet->id));

        $snippet->setRelation('collection', $collection);
        if ($replayed) {
            return (new SnippetResource($snippet))->response();
        }

        return (new SnippetResource($snippet))->response()->setStatusCode(201);
    }

    /**
     * One snippet.
     *
     * @throws NotFoundHttpException the snippet doesn't exist or the user isn't a member of its workspace (404)
     */
    public function showSnippet(Request $request, string $snippet): SnippetResource
    {
        return new SnippetResource($this->visibleSnippet($request, $snippet));
    }

    /**
     * Change a snippet (only the sent fields).
     *
     * Workspace editors / admins (403 otherwise).
     *
     * @throws NotFoundHttpException the snippet doesn't exist or the user isn't a member of its workspace (404)
     */
    public function updateSnippet(UpdateSnippetRequest $request, string $snippet): SnippetResource
    {
        $snippet = $request->snippet();
        $snippet->update($request->validated());

        return new SnippetResource($snippet);
    }

    /**
     * Delete a snippet.
     *
     * Workspace editors / admins (403 otherwise).
     *
     * @throws NotFoundHttpException the snippet doesn't exist or the user isn't a member of its workspace (404)
     */
    public function destroySnippet(Request $request, string $snippet): Response
    {
        $snippet = $this->visibleSnippet($request, $snippet);
        $this->authorize('create', [SnippetCollection::class, $snippet->collection->workspace]);
        $snippet->delete();

        return response()->noContent();
    }

    private function withCount(SnippetCollection $collection): SnippetCollection
    {
        return $collection->loadMissing('workspace')->loadCount('snippets');
    }
}
