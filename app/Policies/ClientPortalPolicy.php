<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Team side of a project's client portal (v1.42.0, BRAI-73). Abilities take the project, because the
 * portal row only exists once someone enabled it: `authorize('manage', [ClientPortal::class, $project])`.
 * Clients never pass through here — they are checked by App\Support\ClientPortal\ClientPortalAccess.
 */
class ClientPortalPolicy
{
    /** See the team page: settings, clients, deliverables, decisions and comments. */
    public function viewAny(User $user, Project $project): bool
    {
        return $user->hasProjectAccess($project);
    }

    /** Settings, enabling, inviting and revoking clients: project admins (workspace owner / admins included). */
    public function manage(User $user, Project $project): bool
    {
        $workspace = $project->workspace;

        return $workspace->owner_id === $user->id
            || $user->workspaceRole($workspace) === 'admin'
            || $user->projectRole($project) === 'admin';
    }

    /** Publish / withdraw deliverables and comment (reply to the client or note internally): admins and editors. */
    public function publish(User $user, Project $project): bool
    {
        return $this->manage($user, $project) || $user->projectRole($project) === 'editor';
    }
}
