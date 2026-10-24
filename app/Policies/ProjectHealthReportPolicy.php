<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectHealthReport;
use App\Models\User;

/**
 * Project health (BRAI-64): every project member sees the metrics and saved reports; editors
 * and admins draft (AI) and save reports; the author or a project / workspace admin deletes.
 */
class ProjectHealthReportPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    public function view(User $user, ProjectHealthReport $report): bool
    {
        return $user->can('view', $report->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('createBoards', $project);
    }

    public function delete(User $user, ProjectHealthReport $report): bool
    {
        if (! $user->can('view', $report->project)) {
            return false;
        }

        // authors only while they can still write reports (a demoted editor can't delete)
        return ($report->created_by === $user->id && $user->can('createBoards', $report->project))
            || $user->can('update', $report->project);
    }
}
