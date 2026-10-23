<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Determine whether the user can view any workspaces.
     */
    public function viewAny(User $user): bool
    {
        // Users can view workspaces they belong to
        return true;
    }

    /**
     * Determine whether the user can view the workspace.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceAccess($workspace);
    }

    /**
     * Determine whether the user can create workspaces.
     * All authenticated users can create workspaces.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the workspace.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaceRole($workspace);

        return $role === 'admin';
    }

    /**
     * Determine whether the user can delete the workspace.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        // Only workspace owner or instance admin can delete
        return $workspace->owner_id === $user->id;
    }

    /**
     * Determine whether the user can manage workspace members.
     */
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaceRole($workspace);

        return $role === 'admin';
    }

    /**
     * Determine whether the user can invite members to the workspace.
     */
    public function inviteMembers(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaceRole($workspace);

        return $role === 'admin';
    }

    /**
     * Determine whether the user can create projects in the workspace.
     */
    public function createProjects(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaceRole($workspace);

        return in_array($role, ['admin', 'editor']);
    }
}
