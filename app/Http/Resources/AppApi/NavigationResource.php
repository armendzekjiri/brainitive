<?php

namespace App\Http\Resources\AppApi;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sidebar\SidebarPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * `GET /api/v1/me/navigation` (BRAI-391): the web sidebar of one workspace for the apps' menus,
 * dock / taskbar jump list and "Recent" menu — the visible top-level entries, the projects the user
 * can open with their content groups and the user's layout, and the app's recent destinations
 * resolved. Docs are not listed (they can be many): `GET /projects/{id}` has a project's folder
 * tree; pinned docs are in `pins`.
 *
 * @property-read array{workspace: Workspace, projects: Collection<int, Project>, recent: list<array{type: string, id: int, title: string, subtitle: string, url: string}>} $resource
 */
class NavigationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $hidden = SidebarPreferences::normalize($user->sidebar_preferences)['hidden_nav'];

        return [
            'workspace' => new WorkspaceResource($this->resource['workspace']),
            // top-level entries in sidebar order (home, inbox, my_work, captures, timesheet, billing,
            // insights, calendar, booking_pages, bookmarks, snippets); home and inbox are always there
            'nav' => self::strings(array_merge(['home', 'inbox'], array_diff(SidebarPreferences::NAV, $hidden))),
            'hidden_nav' => self::strings($hidden),
            'projects' => NavigationProjectResource::collection($this->resource['projects']),
            // the app's recent[] entries the user can still open, in the app's order
            'recent' => RecentDestinationResource::collection($this->resource['recent']),
        ];
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map('strval', $values));
    }
}
