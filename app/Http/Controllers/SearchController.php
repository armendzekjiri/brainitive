<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Support\Search\PlaceSearch;
use App\Support\Search\SearchService;
use App\Support\Search\SearchTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Find anything" (BRAI-55).
 *
 * - `search` (GET /search): the search page — free text, filters, a tab per type with counts.
 * - `search.quick` (GET /search/quick, JSON): the command palette — the best content results
 *   plus places to go (workspaces, projects, boards, plans, snippet and bookmark collections).
 *
 * Content comes from SearchService (the search index); visibility is decided in its query.
 */
class SearchController extends Controller
{
    public const PAGE_SIZE = 20;

    public const QUICK_SIZE = 8;

    public function page(Request $request, SearchService $search): Response
    {
        $user = $request->user();
        $input = $request->query();
        $query = SearchService::queryFrom($input, $user, self::PAGE_SIZE);
        $type = $input['type'] ?? null;
        $updated = $input['updated'] ?? null;

        return Inertia::render('Search/Index', [
            'filters' => [
                'q' => $query->text,
                'type' => is_string($type) && isset(SearchTypes::GROUPS[$type]) ? $type : null,
                'project' => $query->projectIds,
                'status' => $query->status,
                'assignee' => ($input['assignee'] ?? null) === 'me' ? 'me' : ($query->assigneeId !== null ? (string) $query->assigneeId : null),
                'updated' => is_string($updated) && isset(SearchService::UPDATED_PRESETS[$updated]) ? $updated : null,
                'page' => $query->page,
            ],
            'search' => $query->isSearchable() ? $search->search($user, $query) : null,
            'groups' => collect(SearchTypes::GROUPS)->map(fn (array $group, string $key) => ['key' => $key, 'label' => $group[0]])->values(),
            // filter choices: loaded with the page, skipped by the partial reloads while typing
            'options' => fn () => $this->options($user),
        ]);
    }

    public function quick(Request $request, SearchService $search): JsonResponse
    {
        $user = $request->user();
        $text = trim((string) $request->input('q', ''));
        if (mb_strlen($text) < 2) {
            return response()->json(['results' => [], 'total' => 0, 'fuzzy' => false, ...PlaceSearch::none()]);
        }

        $context = (int) $request->integer('project');
        $found = $search->search($user, SearchService::queryFrom(['q' => $text, 'context_project' => $context ?: null], $user, self::QUICK_SIZE));

        return response()->json([
            'results' => $found['results'],
            'total' => $found['total'],
            'fuzzy' => $found['fuzzy'],
            ...PlaceSearch::find($user, $text, $context),
        ]);
    }

    /**
     * The browser extension's search (`ext.search`): the shape installed extensions read —
     * `items`, `pages`, `projects`, `bookmarks`, `snippets`, `workspaces` lists of
     * `{id, type, title, subtitle, url, workspace}`. Content comes from the search index — titles
     * and keys only, as a capture token may only search titles (CONVENTIONS, API tokens): docs in
     * `pages`, issues, tickets, tasks, plans … in `items`.
     */
    public function extension(Request $request): JsonResponse
    {
        $user = $request->user();
        $text = trim((string) $request->input('q', ''));
        if (mb_strlen($text) < 2) {
            return response()->json(['items' => [], 'pages' => [], ...PlaceSearch::none()]);
        }

        $found = app(SearchService::class)->search($user, SearchService::queryFrom(['q' => $text], $user, 12, titlesOnly: true));
        $hits = ['items' => [], 'pages' => []];
        foreach ($found['results'] as $result) {
            if ($result['type'] === SearchTypes::COMMENT) {
                continue; // comments have no title of their own
            }
            $hits[$result['type'] === SearchTypes::PAGE ? 'pages' : 'items'][] = PlaceSearch::row(
                $result['type'],
                $result['source_id'],
                $result['title'] !== '' ? $result['title'] : $result['label'],
                trim(($result['key'] ? $result['key'].' · ' : '').$result['label'].($result['context'] ? ' · '.$result['context'] : '')),
                $result['url'],
                $result['workspace'],
            );
        }

        return response()->json([...$hits, ...PlaceSearch::find($user, $text, 0)]);
    }

    /**
     * Projects and people to filter by.
     *
     * @return array{projects: list<array{id: int, name: string, workspace: string}>, people: list<array{id: int, name: string}>}
     */
    private function options(User $user): array
    {
        $projects = Project::query()
            ->whereIn('projects.id', $user->accessibleProjectIds())
            ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
            ->orderBy('workspaces.name')
            ->orderBy('projects.name')
            ->get(['projects.id', 'projects.name', 'workspaces.name as workspace_name'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'workspace' => $p->workspace_name])
            ->all();

        $people = User::query()
            ->whereIn('id', DB::table('workspace_user')
                ->whereIn('workspace_id', $user->workspaces()->select('workspaces.id'))
                ->select('user_id'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->all();

        return ['projects' => $projects, 'people' => $people];
    }
}
