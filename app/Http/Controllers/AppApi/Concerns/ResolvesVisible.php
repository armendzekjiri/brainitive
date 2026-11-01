<?php

namespace App\Http\Controllers\AppApi\Concerns;

use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\Sprint;
use App\Models\TaskList;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * App API v1: a workspace or project the user can't open is a 404, never a 403, so nobody learns
 * that it exists (docs/ai/API.md → Errors). Same rules as the web policies (WorkspacePolicy::view,
 * ProjectPolicy::view); finer checks (update, invite …) follow with authorize() and are 403.
 */
trait ResolvesVisible
{
    protected function visibleWorkspace(Request $request, Workspace $workspace): Workspace
    {
        abort_unless($request->user()->can('view', $workspace), 404);

        return $workspace;
    }

    /** `$id` from a route without a constraint (writes): anything but digits is a 404. */
    protected function visibleProject(Request $request, int|string $id): Project
    {
        abort_unless(self::isId($id), 404);
        $project = Project::with('workspace')->find((int) $id);
        abort_unless($project instanceof Project && $project->workspace !== null && $request->user()->can('view', $project), 404);

        return $project;
    }

    /** A task list of a project the user can open (TaskListPolicy::view). */
    protected function visibleTaskList(Request $request, int|string $id): TaskList
    {
        abort_unless(self::isId($id), 404);
        $list = TaskList::with('project.workspace')->find((int) $id);
        abort_unless($list instanceof TaskList && $list->project?->workspace !== null && $request->user()->can('view', $list), 404);

        return $list;
    }

    /** A snippet collection of a workspace the user is a member of (the web: every member reads them). */
    protected function visibleSnippetCollection(Request $request, int|string $id): SnippetCollection
    {
        abort_unless(self::isId($id), 404);
        $collection = SnippetCollection::with('workspace')->find((int) $id);
        abort_unless($collection instanceof SnippetCollection && $collection->workspace !== null && $request->user()->hasWorkspaceAccess($collection->workspace), 404);

        return $collection;
    }

    protected function visibleSnippet(Request $request, int|string $id): Snippet
    {
        abort_unless(self::isId($id), 404);
        $snippet = Snippet::with('collection.workspace')->find((int) $id);
        abort_unless($snippet instanceof Snippet && $snippet->collection?->workspace !== null && $request->user()->hasWorkspaceAccess($snippet->collection->workspace), 404);

        return $snippet;
    }

    /**
     * A bookmark group the user may see: a member of its workspace, and BookmarkPolicy::view (own, shared
     * with them, public, or a workspace admin). Public groups of other workspaces are only on their share link.
     */
    protected function visibleBookmarkGroup(Request $request, int|string $id): Bookmark
    {
        abort_unless(self::isId($id), 404);
        $group = Bookmark::with('workspace')->find((int) $id);
        $user = $request->user();
        abort_unless($group instanceof Bookmark && $group->workspace !== null && $user->hasWorkspaceAccess($group->workspace) && $user->can('view', $group), 404);

        return $group;
    }

    /** A board of a project the user can open (BoardPolicy::view). */
    protected function visibleBoard(Request $request, int|string $id): Board
    {
        abort_unless(self::isId($id), 404);
        $board = Board::with('project.workspace')->find((int) $id);
        abort_unless($board instanceof Board && $board->project?->workspace !== null && $request->user()->can('view', $board), 404);

        return $board;
    }

    /** An issue on a board of a project the user can open (ItemPolicy::view). */
    protected function visibleItem(Request $request, int|string $id): Item
    {
        abort_unless(self::isId($id), 404);
        $item = Item::with('board.project.workspace')->find((int) $id);
        abort_unless($item instanceof Item && $item->board?->project?->workspace !== null && $request->user()->can('view', $item), 404);

        return $item;
    }

    /** A sprint of a board the user can open (BoardPolicy::view). */
    protected function visibleSprint(Request $request, int|string $id): Sprint
    {
        abort_unless(self::isId($id), 404);
        $sprint = Sprint::with('board.project.workspace')->find((int) $id);
        abort_unless($sprint instanceof Sprint && $sprint->board?->project?->workspace !== null && $request->user()->can('view', $sprint->board), 404);

        return $sprint;
    }

    /**
     * A doc, whiteboard or folder the user may see (PagePolicy::view): a project they can open, and an
     * unpublished page only when it's theirs or they are an admin. Pages are addressed by uuid.
     */
    protected function visiblePage(Request $request, string $uuid): Page
    {
        abort_unless(Str::isUuid($uuid), 404);
        $page = Page::with('project.workspace')->where('uuid', $uuid)->first();
        abort_unless($page instanceof Page && $page->project?->workspace !== null && $request->user()->can('view', $page), 404);

        return $page;
    }

    /** A Gantt plan of a project the user can open (PlanPolicy::view). */
    protected function visiblePlan(Request $request, int|string $id): Plan
    {
        abort_unless(self::isId($id), 404);
        $plan = Plan::with('project.workspace')->find((int) $id);
        abort_unless($plan instanceof Plan && $plan->project?->workspace !== null && $request->user()->can('view', $plan), 404);

        return $plan;
    }

    /** A release (fix version) of a project the user can open (ReleasePolicy::view). */
    protected function visibleRelease(Request $request, int|string $id): Release
    {
        abort_unless(self::isId($id), 404);
        $release = Release::with('project.workspace')->find((int) $id);
        abort_unless($release instanceof Release && $release->project?->workspace !== null && $request->user()->can('view', $release), 404);

        return $release;
    }

    private static function isId(int|string $id): bool
    {
        return is_int($id) ? $id > 0 : (bool) preg_match('/^[1-9][0-9]{0,17}$/', $id);
    }
}
