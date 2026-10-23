<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;

class TaskListPolicy
{
    public function create(User $user, Project $project): bool
    {
        $workspaceRole = $user->workspaceRole($project->workspace);
        if (in_array($workspaceRole, ['owner', 'admin'], true) || ($workspaceRole === 'editor' && $user->hasProjectAccess($project))) {
            return true;
        }

        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    public function view(User $user, TaskList $taskList): bool
    {
        return $user->hasProjectAccess($taskList->project);
    }

    public function update(User $user, TaskList $taskList): bool
    {
        $project = $taskList->project;
        $workspaceRole = $user->workspaceRole($project->workspace);
        if (in_array($workspaceRole, ['owner', 'admin'], true) || ($workspaceRole === 'editor' && $user->hasProjectAccess($project))) {
            return true;
        }

        $projectRole = $user->projectRole($project);

        return in_array($projectRole, ['admin', 'editor']);
    }

    public function delete(User $user, TaskList $taskList): bool
    {
        $project = $taskList->project;
        $workspaceRole = $user->workspaceRole($project->workspace);
        if (in_array($workspaceRole, ['owner', 'admin'])) {
            return true;
        }

        $projectRole = $user->projectRole($project);

        return $projectRole === 'admin';
    }

    public function manageItems(User $user, TaskList $taskList): bool
    {
        return $this->update($user, $taskList);
    }
}
