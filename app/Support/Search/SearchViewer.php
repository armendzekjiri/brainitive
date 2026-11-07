<?php

namespace App\Support\Search;

use App\Models\Bookmark;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyAccess;

/**
 * What one person may find (BRAI-55), loaded once per search in a fixed number of queries and
 * applied inside the search query — never by filtering results afterwards, so counts and pages
 * can't leak. Mirrors the view policies:
 *
 * - project content: projects the user can open (member, or workspace owner / admin;
 *   trashed projects are gone) — ItemPolicy, PagePolicy, TicketPolicy, PlanPolicy, TaskListPolicy;
 * - docs never published: their author and project admins (Page::visibleTo);
 * - snippets: members of the workspace (SnippetController shows every collection);
 * - bookmarks: collections the user may see (Bookmark::visibleTo / BookmarkPolicy::view);
 * - captures: their owner only (CapturePolicy).
 */
final class SearchViewer
{
    /**
     * @param  list<int>  $projectIds
     * @param  list<int>  $adminProjectIds
     * @param  list<int>  $workspaceIds
     * @param  list<int>  $bookmarkIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $projectIds,
        public readonly array $adminProjectIds,
        public readonly array $workspaceIds,
        public readonly array $bookmarkIds,
    ) {}

    public static function for(User $user): self
    {
        $workspaceIds = array_map('intval', $user->workspaces()->pluck('workspaces.id')->all());
        // a personal API key: workspace-wide content (snippets, bookmarks) only of whole workspaces in its scope;
        // project content follows accessibleProjectIds(), which already is the key's scope
        if (($key = ApiKeyAccess::forUser($user)) !== null) {
            $workspaceIds = $key->coversWholeWorkspaces() ? array_values(array_intersect($workspaceIds, $key->workspaceIds())) : [];
        }

        return new self(
            userId: (int) $user->id,
            projectIds: array_values(array_map('intval', $user->accessibleProjectIds())),
            adminProjectIds: array_values(array_map('intval', $user->adminProjectIds())),
            workspaceIds: $workspaceIds,
            bookmarkIds: $workspaceIds === [] ? [] : array_map('intval', Bookmark::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->visibleTo($user)
                ->pluck('id')
                ->all()),
        );
    }
}
