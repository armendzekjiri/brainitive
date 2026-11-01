<?php

namespace App\Http\Controllers\AppApi\Concerns;

use App\Models\Bookmark;
use App\Models\Project;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\Workspace;
use Illuminate\Http\Request;

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

    private static function isId(int|string $id): bool
    {
        return is_int($id) ? $id > 0 : (bool) preg_match('/^[1-9][0-9]{0,17}$/', $id);
    }
}
