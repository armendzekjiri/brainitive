<?php

namespace App\Policies;

use App\Models\Helpdesk;
use App\Models\Project;
use App\Models\User;

class HelpdeskPolicy
{
    /**
     * Determine whether the user can create a helpdesk in the project.
     */
    public function create(User $user, Project $project): bool
    {
        // Workspace admin can create helpdesks
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can create helpdesks
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can view the helpdesk.
     */
    public function view(User $user, Helpdesk $helpdesk): bool
    {
        return $user->hasProjectAccess($helpdesk->project);
    }

    /**
     * Determine whether the user can update the helpdesk.
     */
    public function update(User $user, Helpdesk $helpdesk): bool
    {
        $project = $helpdesk->project;

        // Workspace admin can update any helpdesk
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can update
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can delete the helpdesk.
     */
    public function delete(User $user, Helpdesk $helpdesk): bool
    {
        $project = $helpdesk->project;

        // Workspace admin can delete any helpdesk
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin can delete
        $projectRole = $user->projectRole($project);

        return $projectRole === 'admin';
    }

    /**
     * Determine whether the user can create tickets in the helpdesk.
     */
    public function createTickets(User $user, Helpdesk $helpdesk): bool
    {
        $project = $helpdesk->project;

        // Workspace admin or editor can create tickets
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can create tickets
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }
}
