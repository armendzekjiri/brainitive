<?php

namespace App\Policies;

use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;

class SnippetPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceAccess($workspace)
            && in_array($user->workspaceRole($workspace), ['admin', 'editor']);
    }

    public function update(User $user, SnippetCollection $collection): bool
    {
        $workspace = $collection->workspace;
        if (! $user->hasWorkspaceAccess($workspace)) {
            return false;
        }
        $role = $user->workspaceRole($workspace);
        if ($role === 'admin') {
            return true;
        }

        return $collection->created_by === $user->id;
    }

    public function delete(User $user, SnippetCollection $collection): bool
    {
        return $this->update($user, $collection);
    }
}
