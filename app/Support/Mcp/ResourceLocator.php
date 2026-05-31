<?php

namespace App\Support\Mcp;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves "where does this live?" for any content model an MCP tool creates or
 * touches. Every write tool returns this so the assistant can tell the user the
 * exact location (breadcrumb) and link (URL) of what it just changed.
 */
class ResourceLocator
{
    /**
     * @return array{type: string, title: string, workspace: ?string, project: ?string, breadcrumb: string, url: string}
     */
    public static function locate(Model $model): array
    {
        return match (true) {
            $model instanceof Workspace => self::build('workspace', $model->name, $model, null, route('workspaces.show', $model->slug)),
            $model instanceof Project => self::build('project', $model->name, $model->workspace, $model, route('projects.show', [$model->workspace->slug, $model->slug])),
            $model instanceof Page => self::page($model),
            $model instanceof Board => self::board($model),
            $model instanceof Item => self::boardItem($model),
            $model instanceof TaskList => self::taskList($model),
            $model instanceof TaskListItem => self::taskListItem($model),
            $model instanceof Bookmark => self::bookmark($model),
            $model instanceof BookmarkItem => self::bookmark($model->bookmark, $model->title ?: 'Item'),
            $model instanceof SnippetCollection => self::snippetCollection($model),
            $model instanceof Snippet => self::snippet($model),
            default => self::build('item', (string) ($model->getAttribute('name') ?? $model->getAttribute('title') ?? 'Item'), null, null, url('/')),
        };
    }

    private static function page(Page $page): array
    {
        $project = $page->project;
        $workspace = $project?->workspace;

        return self::build(
            'page',
            $page->name,
            $workspace,
            $project,
            $workspace ? route('pages.show', [$workspace->slug, $project->slug, $page->uuid]) : url('/'),
        );
    }

    private static function board(Board $board): array
    {
        $project = $board->project;
        $workspace = $project?->workspace;

        return self::build(
            'board',
            $board->name,
            $workspace,
            $project,
            $workspace ? route('boards.show', [$workspace->slug, $project->slug, $board->slug]) : url('/'),
        );
    }

    private static function boardItem(Item $item): array
    {
        $board = $item->board;
        $project = $board?->project ?? $item->project;
        $workspace = $project?->workspace;
        $url = ($workspace && $board)
            ? route('boards.show', [$workspace->slug, $project->slug, $board->slug]).'#item-'.$item->id
            : url('/');

        return self::build('board item', $item->title ?: ($item->key ?? 'Item'), $workspace, $project, $url, $board?->name);
    }

    private static function taskList(TaskList $list): array
    {
        $project = $list->project;
        $workspace = $project?->workspace;

        return self::build(
            'task list',
            $list->name,
            $workspace,
            $project,
            $workspace ? route('task-lists.show', [$workspace->slug, $project->slug, $list->slug]) : url('/'),
        );
    }

    private static function taskListItem(TaskListItem $item): array
    {
        $list = $item->taskList;
        $located = self::taskList($list);
        $located['type'] = 'task';
        $located['title'] = $item->title;
        $located['breadcrumb'] .= ' / '.$item->title;

        return $located;
    }

    private static function bookmark(Bookmark $bookmark, ?string $title = null): array
    {
        $workspace = $bookmark->workspace;

        return self::build(
            'bookmark',
            $title ?? $bookmark->name,
            $workspace,
            null,
            $workspace ? route('bookmarks.show', [$workspace->slug, $bookmark->slug]) : url('/'),
            $title ? $bookmark->name : null,
        );
    }

    private static function snippetCollection(SnippetCollection $collection): array
    {
        $workspace = $collection->workspace;

        return self::build(
            'snippet collection',
            $collection->name,
            $workspace,
            null,
            $workspace ? route('snippets.show', [$workspace->slug, $collection->slug]) : url('/'),
        );
    }

    private static function snippet(Snippet $snippet): array
    {
        $collection = $snippet->collection;
        $located = self::snippetCollection($collection);
        $located['type'] = 'snippet';
        $located['title'] = $snippet->title;
        $located['breadcrumb'] .= ' / '.$snippet->title;

        return $located;
    }

    /**
     * @return array{type: string, title: string, workspace: ?string, project: ?string, breadcrumb: string, url: string}
     */
    private static function build(string $type, string $title, ?Workspace $workspace, ?Project $project, string $url, ?string $extra = null): array
    {
        $crumbs = array_filter([
            $workspace?->name,
            $project?->name,
            $extra,
            $title,
        ]);

        return [
            'type' => $type,
            'title' => $title,
            'workspace' => $workspace?->name,
            'project' => $project?->name,
            'breadcrumb' => implode(' / ', $crumbs),
            'url' => $url,
        ];
    }
}
