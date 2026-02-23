<?php

namespace App\Policies;

use App\Models\Bookmark;
use App\Models\User;
use App\Models\Workspace;

class BookmarkPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        if (!$user->hasWorkspaceAccess($workspace)) {
            return false;
        }
        $role = $user->workspaceRole($workspace);
        return in_array($role, ['admin', 'editor']);
    }

    public function view(User $user, Bookmark $bookmark): bool
    {
        if ($bookmark->visibility === 'public') {
            return true;
        }
        if (!$user->hasWorkspaceAccess($bookmark->workspace)) {
            return false;
        }
        if ($user->workspaceRole($bookmark->workspace) === 'admin') {
            return true;
        }
        return $bookmark->isViewableBy($user);
    }

    public function update(User $user, Bookmark $bookmark): bool
    {
        if (!$user->hasWorkspaceAccess($bookmark->workspace)) {
            return false;
        }
        if ($user->workspaceRole($bookmark->workspace) === 'admin') {
            return true;
        }
        return $bookmark->isEditableBy($user);
    }

    public function delete(User $user, Bookmark $bookmark): bool
    {
        if (!$user->hasWorkspaceAccess($bookmark->workspace)) {
            return false;
        }
        if ($user->workspaceRole($bookmark->workspace) === 'admin') {
            return true;
        }
        return $bookmark->created_by === $user->id;
    }

    public function manageCollaborators(User $user, Bookmark $bookmark): bool
    {
        return $this->update($user, $bookmark);
    }
}
