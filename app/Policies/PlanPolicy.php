<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\Project;
use App\Models\User;

/**
 * Same rules as task lists: project members view, editors edit, admins delete.
 */
class PlanPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $this->canEdit($user, $project);
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->hasProjectAccess($plan->project);
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->canEdit($user, $plan->project);
    }

    public function delete(User $user, Plan $plan): bool
    {
        $project = $plan->project;
        if (in_array($user->workspaceRole($project->workspace), ['owner', 'admin'], true)) {
            return true;
        }

        return $user->projectRole($project) === 'admin';
    }

    private function canEdit(User $user, Project $project): bool
    {
        $workspaceRole = $user->workspaceRole($project->workspace);
        if (in_array($workspaceRole, ['owner', 'admin'], true)) {
            return true;
        }

        return in_array($user->projectRole($project), ['admin', 'editor'], true);
    }
}
