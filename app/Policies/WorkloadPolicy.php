<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Workload planning (BRAI-75). Everyone who can open the project sees its workload; planners —
 * project admins and workspace admins, the people who manage the project's settings and members
 * (ProjectPolicy::update) — change capacity, overrides and allocations.
 * Registered for App\Models\WorkloadAllocation.
 */
class WorkloadPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    public function plan(User $user, Project $project): bool
    {
        return $user->can('update', $project);
    }
}
