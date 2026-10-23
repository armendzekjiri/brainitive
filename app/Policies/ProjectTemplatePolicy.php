<?php

namespace App\Policies;

use App\Models\ProjectTemplate;
use App\Models\User;
use App\Models\Workspace;

/**
 * Workspace project templates (BRAI-72): anyone who may create projects in the workspace can
 * use them; only workspace admins save (they see every project) and delete them.
 */
class ProjectTemplatePolicy
{
    public function use(User $user, ProjectTemplate $template): bool
    {
        return $user->can('createProjects', $template->workspace);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $user->can('update', $workspace);
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $user->can('update', $template->workspace);
    }
}
