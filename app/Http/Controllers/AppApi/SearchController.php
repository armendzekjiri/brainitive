<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\SearchRequest;
use App\Http\Resources\AppApi\SearchPlaceResource;
use App\Http\Resources\AppApi\SearchResultResource;
use App\Support\Search\PlaceSearch;
use App\Support\Search\SearchService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — global search (BRAI-392) for the desktop quick switcher and the mobile search tab.
 * The same service, ranking and visibility as the web search page, the command palette, the MCP
 * `search` tool and the extension (SearchService + SearchViewer): never a second search.
 */
#[Group('Search', weight: 100)]
class SearchController extends Controller
{
    /**
     * Search everything the user can open: issues, docs, comments, tickets, tasks, plans, snippets,
     * bookmarks, their captures and files — ranked, typo tolerant.
     *
     * Without filters the first page also lists matching places (workspaces, projects, boards,
     * plans, snippet and bookmark collections) by name, as the web's command palette does.
     *
     * @throws NotFoundHttpException `workspace` is not one of the user's workspaces (404)
     */
    public function __invoke(SearchRequest $request, SearchService $search): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $workspaceIds = [];
        if (! empty($validated['workspace'])) {
            $workspace = $user->workspaces()->where('workspaces.slug', $validated['workspace'])->first();
            abort_unless($workspace !== null, 404);
            $workspaceIds = [$workspace->id];
        }

        $found = $search->search($user, SearchService::queryFrom([
            'q' => $validated['q'],
            'type' => $validated['type'] ?? null,
            'workspace' => $workspaceIds,
            'project' => $validated['project'] ?? [],
            'status' => $validated['status'] ?? null,
            'assignee' => $validated['assignee'] ?? null,
            'updated' => $validated['updated'] ?? null,
            'page' => $request->page(),
        ], $user, $request->limit()));

        $text = trim($validated['q']);
        $places = $request->page() === 1 && $request->unfiltered() && mb_strlen($text) >= 2
            ? collect(PlaceSearch::find($user, $text, 0))->flatten(1)->values()->all()
            : [];

        return response()->json([
            'data' => SearchResultResource::collection($found['results']),
            // the next page's number as a string; null on the last page (at most 100 pages)
            'next_cursor' => $found['has_more'] && $found['page'] < 100 ? (string) ($found['page'] + 1) : null,
            'total' => (int) $found['total'],
            // matches per group (issues, docs, comments, tickets, tasks, plans, snippets, bookmarks, captures, files)
            'counts' => $found['counts'],
            // true when nothing matched exactly and these are typo-tolerant matches
            'fuzzy' => (bool) $found['fuzzy'],
            'places' => SearchPlaceResource::collection($places),
        ]);
    }
}
