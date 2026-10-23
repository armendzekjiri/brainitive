<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    /**
     * Determine whether the user can view the item.
     */
    public function view(User $user, Item $item): bool
    {
        return $user->hasProjectAccess($item->board->project);
    }

    /**
     * Determine whether the user can update the item.
     */
    public function update(User $user, Item $item): bool
    {
        $project = $item->board->project;

        // Workspace admin can update any item
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can update
        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can delete the item.
     */
    public function delete(User $user, Item $item): bool
    {
        $project = $item->board->project;

        // Workspace admin can delete any item
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin can delete, or the creator
        $projectRole = $user->projectRole($project);
        if ($projectRole === 'admin') {
            return true;
        }

        // Creator can delete their own items
        return $item->created_by === $user->id;
    }

    /**
     * Determine whether the user can move the item.
     */
    public function move(User $user, Item $item): bool
    {
        return $this->update($user, $item);
    }

    /**
     * Determine whether the user can assign the item.
     */
    public function assign(User $user, Item $item): bool
    {
        return $this->update($user, $item);
    }

    /**
     * Determine whether the user can comment on the item.
     */
    public function comment(User $user, Item $item): bool
    {
        // Anyone with view access can comment
        return $user->hasProjectAccess($item->board->project);
    }
}
