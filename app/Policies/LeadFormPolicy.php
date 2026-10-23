<?php

namespace App\Policies;

use App\Models\LeadForm;
use App\Models\Project;
use App\Models\User;

/**
 * Lead forms follow the helpdesk rules: project members see a form and its leads, workspace
 * admins and project admins / editors build forms and work the leads, project admins delete.
 */
class LeadFormPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $this->canEdit($user, $project);
    }

    public function view(User $user, LeadForm $leadForm): bool
    {
        return $user->hasProjectAccess($leadForm->project);
    }

    public function update(User $user, LeadForm $leadForm): bool
    {
        return $this->canEdit($user, $leadForm->project);
    }

    /** Move, assign and edit leads on the form's board. */
    public function manageLeads(User $user, LeadForm $leadForm): bool
    {
        return $this->canEdit($user, $leadForm->project);
    }

    public function delete(User $user, LeadForm $leadForm): bool
    {
        $project = $leadForm->project;

        return $user->workspaceRole($project->workspace) === 'admin' || $user->projectRole($project) === 'admin';
    }

    private function canEdit(User $user, Project $project): bool
    {
        return $user->workspaceRole($project->workspace) === 'admin'
            || in_array($user->projectRole($project), ['admin', 'editor'], true);
    }
}
