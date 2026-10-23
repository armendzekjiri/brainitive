<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Release;
use App\Models\User;

/**
 * Anyone who can open the project sees its releases; editors and admins plan and ship them
 * (same people who may create boards).
 */
class ReleasePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    public function view(User $user, Release $release): bool
    {
        return $user->can('view', $release->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('createBoards', $project);
    }

    public function update(User $user, Release $release): bool
    {
        return $user->can('createBoards', $release->project);
    }

    public function delete(User $user, Release $release): bool
    {
        return $this->update($user, $release);
    }
}
