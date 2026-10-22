<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
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
            ->whereIn('workspace_id', $workspaceIds)
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
            ->limit(8)
            ->get(['id', 'workspace_id', 'name', 'slug', 'description'])
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
            ->whereIn('projects.workspace_id', $workspaceIds)
            ->where(function ($query) use ($term) {
                $query->where('items.title', 'like', $term)
                    ->orWhere('items.description', 'like', $term)
                    ->orWhere('items.key', 'like', $term);
            })
            ->select('items.id', 'items.title', 'items.key', 'items.board_id', 'items.description', 'projects.slug as project_slug', 'projects.workspace_id', 'boards.slug as board_slug')
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
            ->whereIn('projects.workspace_id', $workspaceIds)
            ->where('pages.type', 'page')
            ->visibleTo($user)
            ->where(function ($query) use ($term) {
                $query->where('pages.name', 'like', $term);
            })
            ->select('pages.id', 'pages.name', 'pages.uuid', 'pages.project_id', 'projects.slug as project_slug', 'projects.workspace_id')
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

        return response()->json([
            'workspaces' => $workspaces,
            'projects' => $projects,
            'snippets' => $snippets,
            'bookmarks' => $bookmarks,
            'items' => $items,
            'pages' => $pages,
        ]);
    }
}
