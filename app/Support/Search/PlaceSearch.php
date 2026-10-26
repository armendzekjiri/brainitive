<?php

namespace App\Support\Search;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Plan;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Places by name (BRAI-55): workspaces, projects, boards, plans, snippet and bookmark collections
 * — the "go to" part of the command palette, the browser extension and the MCP search, next to
 * the content of the search index.
 */
final class PlaceSearch
{
    /** @return array<string, list<mixed>> */
    public static function none(): array
    {
        return ['workspaces' => [], 'projects' => [], 'boards' => [], 'plans' => [], 'snippets' => [], 'bookmarks' => []];
    }

    /**
     * Places matching the name: workspaces, projects (name, key), boards, plans, snippet and
     * bookmark collections — only what the user can open, the project being viewed first.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function find(User $user, string $text, int $contextProject): array
    {
        $term = '%'.preg_replace('/\s+/', '%', addcslashes($text, '%_\\')).'%';
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();
        if ($workspaceIds === []) {
            return self::none();
        }
        $projectIds = $user->accessibleProjectIds();

        $workspaces = Workspace::query()->whereKey($workspaceIds)
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term))
            ->limit(5)->get(['id', 'name', 'slug', 'description'])
            ->map(fn (Workspace $ws) => self::row('workspace', $ws->id, $ws->name, $ws->description ? Str::limit($ws->description, 60) : 'Workspace', route('workspaces.show', $ws->slug), $ws->name));

        $projects = Project::query()->whereKey($projectIds)->with('workspace:id,slug,name')
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term)->orWhereLike('key', $term))
            ->limit(8)->get(['id', 'workspace_id', 'name', 'slug', 'key', 'description'])
            ->map(fn (Project $p) => self::row('project', $p->id, $p->name, $p->key.($p->description ? ' · '.Str::limit($p->description, 50) : ''), route('projects.show', [$p->workspace->slug, $p->slug]), $p->workspace->name));

        // boards and plans of projects the user can open are viewable (BoardPolicy / PlanPolicy::view)
        $boards = Board::query()->whereIn('project_id', $projectIds)->with('project:id,workspace_id,name,slug', 'project.workspace:id,slug,name')
            ->whereLike('name', $term)->orderByRaw('case when project_id = ? then 0 else 1 end', [$contextProject])->orderBy('name')->limit(6)->get()
            ->map(fn (Board $b) => self::row('board', $b->id, $b->name, $b->project->name.' · Board', route('boards.show', [$b->project->workspace->slug, $b->project->slug, $b->slug]), $b->project->workspace->name));

        $plans = Plan::query()->whereIn('project_id', $projectIds)->with('project:id,workspace_id,name,slug', 'project.workspace:id,slug,name')
            ->whereLike('name', $term)->orderByRaw('case when project_id = ? then 0 else 1 end', [$contextProject])->orderBy('name')->limit(6)->get()
            ->map(fn (Plan $p) => self::row('plan', $p->id, $p->name, $p->project->name.' · Plan', route('plans.show', [$p->project->workspace->slug, $p->project->slug, $p->slug]), $p->project->workspace->name));

        $snippets = SnippetCollection::query()->whereIn('workspace_id', $workspaceIds)->with('workspace:id,slug,name')
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term))
            ->limit(6)->get(['id', 'workspace_id', 'name', 'slug', 'description'])
            ->map(fn (SnippetCollection $c) => self::row('snippet', $c->id, $c->name, $c->description ? Str::limit($c->description, 60) : $c->workspace->name.' · Snippets', route('snippets.show', [$c->workspace->slug, $c->slug]), $c->workspace->name));

        // private collections stay private (BookmarkPolicy::view)
        $bookmarks = Bookmark::query()->whereIn('workspace_id', $workspaceIds)->visibleTo($user)->with('workspace:id,slug,name')
            ->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('description', $term))
            ->limit(6)->get(['id', 'workspace_id', 'name', 'slug', 'description'])
            ->map(fn (Bookmark $b) => self::row('bookmark', $b->id, $b->name, $b->description ? Str::limit($b->description, 60) : $b->workspace->name, route('bookmarks.show', [$b->workspace->slug, $b->slug]), $b->workspace->name));

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
    public static function row(string $type, int $id, string $title, ?string $subtitle, string $url, ?string $workspace): array
    {
        return ['id' => $id, 'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url, 'workspace' => $workspace];
    }
}
