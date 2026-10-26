<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Plan;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Search\SearchService;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            return response()->json(['results' => [], 'total' => 0, 'fuzzy' => false, ...$this->emptyNavigation()]);
        }

        $context = (int) $request->integer('project');
        $found = $search->search($user, SearchService::queryFrom(['q' => $text, 'context_project' => $context ?: null], $user, self::QUICK_SIZE));

        return response()->json([
            'results' => $found['results'],
            'total' => $found['total'],
            'fuzzy' => $found['fuzzy'],
            ...$this->navigation($user, $text, $context),
        ]);
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

    /** @return array<string, list<mixed>> */
    private function emptyNavigation(): array
    {
        return ['workspaces' => [], 'projects' => [], 'boards' => [], 'plans' => [], 'snippets' => [], 'bookmarks' => []];
    }

    /**
     * Places matching the name: workspaces, projects (name, key), boards, plans, snippet and
     * bookmark collections — only what the user can open, the project being viewed first.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function navigation(User $user, string $text, int $contextProject): array
    {
        $term = '%'.preg_replace('/\s+/', '%', addcslashes($text, '%_\\')).'%';
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();
        if ($workspaceIds === []) {
            return $this->emptyNavigation();
        }
        $projectIds = $user->accessibleProjectIds();
        $contextFirst = 'case when project_id = '.$contextProject.' then 0 else 1 end';

        $workspaces = Workspace::query()->whereKey($workspaceIds)
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term))
            ->limit(5)->get(['id', 'name', 'slug', 'description'])
            ->map(fn (Workspace $ws) => $this->row('workspace', $ws->id, $ws->name, $ws->description ? Str::limit($ws->description, 60) : 'Workspace', route('workspaces.show', $ws->slug), $ws->name));

        $projects = Project::query()->whereKey($projectIds)->with('workspace:id,slug,name')
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term)->orWhereLike('key', $term))
            ->limit(8)->get(['id', 'workspace_id', 'name', 'slug', 'key', 'description'])
            ->map(fn (Project $p) => $this->row('project', $p->id, $p->name, $p->key.($p->description ? ' · '.Str::limit($p->description, 50) : ''), route('projects.show', [$p->workspace->slug, $p->slug]), $p->workspace->name));

        // boards and plans of projects the user can open are viewable (BoardPolicy / PlanPolicy::view)
        $boards = Board::query()->whereIn('project_id', $projectIds)->with('project:id,workspace_id,name,slug', 'project.workspace:id,slug,name')
            ->whereLike('name', $term)->orderByRaw($contextFirst)->orderBy('name')->limit(6)->get()
            ->map(fn (Board $b) => $this->row('board', $b->id, $b->name, $b->project->name.' · Board', route('boards.show', [$b->project->workspace->slug, $b->project->slug, $b->slug]), $b->project->workspace->name));

        $plans = Plan::query()->whereIn('project_id', $projectIds)->with('project:id,workspace_id,name,slug', 'project.workspace:id,slug,name')
            ->whereLike('name', $term)->orderByRaw($contextFirst)->orderBy('name')->limit(6)->get()
            ->map(fn (Plan $p) => $this->row('plan', $p->id, $p->name, $p->project->name.' · Plan', route('plans.show', [$p->project->workspace->slug, $p->project->slug, $p->slug]), $p->project->workspace->name));

        $snippets = SnippetCollection::query()->whereIn('workspace_id', $workspaceIds)->with('workspace:id,slug,name')
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term))
            ->limit(6)->get(['id', 'workspace_id', 'name', 'slug', 'description'])
            ->map(fn (SnippetCollection $c) => $this->row('snippet', $c->id, $c->name, $c->description ? Str::limit($c->description, 60) : $c->workspace->name.' · Snippets', route('snippets.show', [$c->workspace->slug, $c->slug]), $c->workspace->name));

        // private collections stay private (BookmarkPolicy::view)
        $bookmarks = Bookmark::query()->whereIn('workspace_id', $workspaceIds)->visibleTo($user)->with('workspace:id,slug,name')
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term))
            ->limit(6)->get(['id', 'workspace_id', 'name', 'slug', 'description'])
            ->map(fn (Bookmark $b) => $this->row('bookmark', $b->id, $b->name, $b->description ? Str::limit($b->description, 60) : $b->workspace->name, route('bookmarks.show', [$b->workspace->slug, $b->slug]), $b->workspace->name));

        return [
            'workspaces' => $workspaces->values()->all(),
            'projects' => $projects->values()->all(),
            'boards' => $boards->values()->all(),
            'plans' => $plans->values()->all(),
            'snippets' => $snippets->values()->all(),
            'bookmarks' => $bookmarks->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $type, int $id, string $title, ?string $subtitle, string $url, ?string $workspace): array
    {
        return ['id' => $id, 'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url, 'workspace' => $workspace];
    }
}
