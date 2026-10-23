<?php

namespace App\Policies;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;

class BoardPolicy
{
    /**
     * Determine whether the user can create a board in the project.
     */
    public function create(User $user, Project $project): bool
    {
        // Workspace admin can create boards
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can create boards
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can view the board.
     */
    public function view(User $user, Board $board): bool
    {
        return $user->hasProjectAccess($board->project);
    }

    /**
     * Determine whether the user can update the board.
     */
    public function update(User $user, Board $board): bool
    {
        $project = $board->project;

        // Workspace admin can update any board
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can update
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can delete the board.
     */
    public function delete(User $user, Board $board): bool
    {
        $project = $board->project;

        // Workspace admin can delete any board
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin can delete
        $projectRole = $user->projectRole($project);

        return $projectRole === 'admin';
    }

    /**
     * Determine whether the user can create items in the board.
     */
    public function createItems(User $user, Board $board): bool
    {
        $project = $board->project;

        // Workspace admin, or the project's own admins / editors (a project viewer stays read-only)
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can create items
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }
}
