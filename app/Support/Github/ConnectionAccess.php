<?php

namespace App\Support\Github;

use App\Models\GithubConnection;
use App\Models\User;
use App\Notifications\GithubSyncFailedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A project's GitHub connection losing or regaining access (BRAI-61): the error shows on the
 * GitHub card, and the project admins get `github_sync_failed` once per loss (access_lost_at).
 */
final class ConnectionAccess
{
    public static function lost(GithubConnection $connection, string $reason): void
    {
        $first = $connection->access_lost_at === null;
        $connection->forceFill(['last_error' => $reason, 'access_lost_at' => $connection->access_lost_at ?? now()])->save();
        if (! $first) {
            return;
        }

        $connection->loadMissing('project');
        $project = $connection->project;
        if (! $project) {
            return;
        }

        DB::afterCommit(function () use ($connection, $project, $reason) {
            // The project admins, plus whoever connected it if they still manage the project.
            $recipients = $project->admins()->get();
            if ($connection->created_by && ! $recipients->contains('id', $connection->created_by) && ($creator = User::find($connection->created_by))
                && Gate::forUser($creator)->allows('update', $project)) {
                $recipients->push($creator);
            }
            foreach ($recipients as $admin) {
                if ($admin->hasProjectAccess($project)) {
                    $admin->notify(new GithubSyncFailedNotification($connection, $reason));
                }
            }
        });
    }

    public static function restored(GithubConnection $connection): void
    {
        if ($connection->access_lost_at !== null) {
            $connection->forceFill(['last_error' => null, 'access_lost_at' => null])->save();
        }
    }
}
