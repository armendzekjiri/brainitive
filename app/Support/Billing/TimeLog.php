<?php

namespace App\Support\Billing;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Who may log and change time, and on which day (v1.38.0, BRAI-99). Logging on an issue needs the
 * right to edit it (ItemPolicy::update, as "Log work" always did); a project entry without an issue
 * needs the same role in the project. Entries change only while unlocked, by their author or a
 * workspace admin.
 */
class TimeLog
{
    /** A work day: a real date, not in the future (one day of slack for the browser's zone). */
    public static function spentOnRules(User $user, bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'date_format:Y-m-d',
            'after_or_equal:2000-01-01',
            'before_or_equal:'.now($user->localTimezone())->addDay()->toDateString(),
        ];
    }

    /**
     * Projects of the workspace the user may log time on: every project for workspace admins,
     * otherwise the ones where they are project admin or editor (ItemPolicy::update).
     *
     * @return list<int>
     */
    public static function loggableProjectIds(User $user, Workspace $workspace): array
    {
        $query = Project::where('workspace_id', $workspace->id);
        if ($user->workspaceRole($workspace) !== 'admin') {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id)->whereIn('project_user.role', ['admin', 'editor']));
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The author, or a workspace admin (billing). Locking is checked separately. */
    public static function canChange(User $user, TimeEntry $entry): bool
    {
        return (int) $entry->user_id === (int) $user->id || Gate::forUser($user)->allows('manageBilling', $entry->workspace);
    }
}
