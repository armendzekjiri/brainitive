<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any projects in a workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceAccess($workspace);
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->hasProjectAccess($project);
    }

    /**
     * Determine whether the user can create projects.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaceRole($workspace);
        return in_array($role, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        // Workspace admin can update any project
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin can update
        $projectRole = $user->projectRole($project);
        return $projectRole === 'admin';
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        // Only workspace admin can delete projects
        $workspaceRole = $user->workspaceRole($project->workspace);
        return $workspaceRole === 'admin';
    }

    /**
     * Determine whether the user can manage project members.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        // Workspace admin can manage any project
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin can manage
        $projectRole = $user->projectRole($project);
        return $projectRole === 'admin';
    }

    /**
     * Determine whether the user can create boards in the project.
     */
    public function createBoards(User $user, Project $project): bool
    {
        // Workspace admin can create boards
        $workspaceRole = $user->workspaceRole($project->workspace);
        if (in_array($workspaceRole, ['admin', 'editor'])) {
            return true;
        }

        // Project admin or editor can create
        $projectRole = $user->projectRole($project);
        return in_array($projectRole, ['admin', 'editor']);
    }
}
