<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * Full-text search across workspaces, projects, snippets, bookmarks, board items, and documents (pages).
     * Scoped to the authenticated user's workspaces.
     */
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $empty = [
            'workspaces' => [],
            'projects' => [],
            'snippets' => [],
            'bookmarks' => [],
            'items' => [],
            'pages' => [],
            'boards' => [],
            'plans' => [],
        ];

        if (strlen($q) < 2) {
            return response()->json($empty);
        }

        $user = $request->user();
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->toArray();
        if (empty($workspaceIds)) {
            return response()->json($empty);
        }

        $term = '%'.preg_replace('/\s+/', '%', $q).'%';
        // The palette passes the project being viewed; its results come first.
        $contextProject = (int) $request->integer('project');
        // Projects the user can open (member, or workspace owner/admin) — not every project
        // in their workspaces.
        $projectIds = $user->accessibleProjectIds();

        // Workspaces (name, description)
        $workspaces = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            })
            ->limit(5)
            ->get(['id', 'name', 'slug', 'description'])
            ->map(fn ($ws) => [
                'id' => $ws->id,
                'type' => 'workspace',
                'title' => $ws->name,
                'subtitle' => $ws->description ? Str::limit($ws->description, 60) : 'Workspace',
                'url' => route('workspaces.show', $ws->slug),
                'workspace' => $ws->name,
            ]);

        // Projects (name, description, key)
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('key', 'like', $term);
            })
            ->limit(8)
            ->get(['id', 'workspace_id', 'name', 'slug', 'key', 'description'])
            ->map(function ($p) {
                $ws = Workspace::find($p->workspace_id);

                return [
                    'id' => $p->id,
                    'type' => 'project',
                    'title' => $p->name,
                    'subtitle' => $p->key.($p->description ? ' · '.Str::limit($p->description, 50) : ''),
                    'url' => $ws ? route('projects.show', [$ws->slug, $p->slug]) : '#',
                    'workspace' => $ws?->name,
                ];
            });

        // Snippet collections (name, description)
        $snippets = SnippetCollection::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            })
            ->limit(8)
            ->get(['id', 'workspace_id', 'name', 'slug', 'description'])
            ->map(function ($sc) {
                $ws = Workspace::find($sc->workspace_id);

                return [
                    'id' => $sc->id,
                    'type' => 'snippet',
                    'title' => $sc->name,
                    'subtitle' => $sc->description ? Str::limit($sc->description, 60) : $ws?->name.' · Snippets',
                    'url' => $ws ? route('snippets.show', [$ws->slug, $sc->slug]) : '#',
                    'workspace' => $ws?->name,
                ];
            });

        // Bookmarks (name, description) in user's workspaces
        $bookmarks = Bookmark::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            })
            ->limit(25)
            ->get()
            ->filter(fn (Bookmark $b) => $user->can('view', $b)) // private groups stay private
            ->take(8)
            ->values()
            ->map(function ($b) {
                $ws = Workspace::find($b->workspace_id);

                return [
                    'id' => $b->id,
                    'type' => 'bookmark',
                    'title' => $b->name,
                    'subtitle' => $b->description ? Str::limit($b->description, 60) : $ws?->name,
                    'url' => $ws ? route('bookmarks.show', [$ws->slug, $b->slug]) : '#',
                    'workspace' => $ws?->name,
                ];
            });

        // Board items (title, description, key) in projects that belong to user's workspaces
        $items = Item::query()
            ->join('boards', 'items.board_id', '=', 'boards.id')
            ->join('projects', 'boards.project_id', '=', 'projects.id')
            ->whereIn('projects.id', $projectIds)
            ->where(function ($query) use ($term) {
                $query->where('items.title', 'like', $term)
                    ->orWhere('items.description', 'like', $term)
                    ->orWhere('items.key', 'like', $term);
            })
            ->select('items.id', 'items.title', 'items.key', 'items.board_id', 'items.description', 'projects.slug as project_slug', 'projects.workspace_id', 'boards.slug as board_slug')
            ->orderByRaw('case when projects.id = ? then 0 else 1 end', [$contextProject])
            ->orderByDesc('items.updated_at')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $ws = Workspace::find($row->workspace_id);
                $url = $ws ? route('boards.show', [$ws->slug, $row->project_slug, $row->board_slug]) : '#';

                return [
                    'id' => $row->id,
                    'type' => 'item',
                    'title' => $row->title ?: $row->key,
                    'subtitle' => $row->key.($row->description ? ' · '.Str::limit(strip_tags($row->description), 50) : ''),
                    'url' => $url.'#item-'.$row->id,
                    'workspace' => $ws?->name,
                ];
            });

        // Pages/documents (name; content is JSON so we search name only for now)
        $pages = Page::query()
            ->join('projects', 'pages.project_id', '=', 'projects.id')
            ->whereIn('projects.id', $projectIds)
            ->where('pages.type', 'page')
            ->visibleTo($user)
            ->where(function ($query) use ($term) {
                $query->where('pages.name', 'like', $term);
            })
            ->select('pages.id', 'pages.name', 'pages.uuid', 'pages.project_id', 'projects.slug as project_slug', 'projects.workspace_id')
            ->orderByRaw('case when projects.id = ? then 0 else 1 end', [$contextProject])
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $ws = Workspace::find($row->workspace_id);
                $url = $ws ? route('pages.show', [$ws->slug, $row->project_slug, $row->uuid]) : '#';

                return [
                    'id' => $row->id,
                    'type' => 'page',
                    'title' => $row->name,
                    'subtitle' => $ws?->name.' · Document',
                    'url' => $url,
                    'workspace' => $ws?->name,
                ];
            });

        $boards = Board::query()
            ->with('project:id,workspace_id,name,slug', 'project.workspace:id,slug,name')
            ->whereIn('project_id', $projectIds)
            ->where('name', 'like', $term)
            ->orderByRaw('case when project_id = ? then 0 else 1 end', [$contextProject])
            ->limit(25)
            ->get()
            ->filter(fn (Board $b) => $user->can('view', $b))
            ->take(6)
            ->values()
            ->map(fn (Board $b) => [
                'id' => $b->id,
                'type' => 'board',
                'title' => $b->name,
                'subtitle' => $b->project->name.' · Board',
                'url' => route('boards.show', [$b->project->workspace->slug, $b->project->slug, $b->slug]),
                'workspace' => $b->project->workspace->name,
            ]);

        $plans = Plan::query()
            ->with('project:id,workspace_id,name,slug', 'project.workspace:id,slug,name')
            ->whereIn('project_id', $projectIds)
            ->where('name', 'like', $term)
            ->orderByRaw('case when project_id = ? then 0 else 1 end', [$contextProject])
            ->limit(25)
            ->get()
            ->filter(fn (Plan $p) => $user->can('view', $p))
            ->take(6)
            ->values()
            ->map(fn (Plan $p) => [
                'id' => $p->id,
                'type' => 'plan',
                'title' => $p->name,
                'subtitle' => $p->project->name.' · Plan',
                'url' => route('plans.show', [$p->project->workspace->slug, $p->project->slug, $p->slug]),
                'workspace' => $p->project->workspace->name,
            ]);

        return response()->json([
            'boards' => $boards,
            'plans' => $plans,
            'workspaces' => $workspaces,
            'projects' => $projects,
            'snippets' => $snippets,
            'bookmarks' => $bookmarks,
            'items' => $items,
            'pages' => $pages,
        ]);
    }
}
