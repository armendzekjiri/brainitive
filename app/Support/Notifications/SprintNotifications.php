<?php

namespace App\Support\Notifications;

use App\Models\Sprint;
use App\Models\User;
use App\Notifications\SprintCompletedNotification;
use App\Notifications\SprintStartedNotification;
use Illuminate\Support\Facades\DB;

/**
 * sprint_started / sprint_completed: everyone with an issue in the sprint (its assignees), except the
 * person who started / completed it, with the open / done counts. Called by SprintLifecycle inside its
 * transaction; sent after the commit.
 */
class SprintNotifications
{
    /** Assignees of the sprint's issues — read before completing, which moves open issues out. */
    public static function recipientIds(Sprint $sprint): array
    {
        return $sprint->items()->whereNotNull('assigned_to')->distinct()->pluck('assigned_to')->map(fn ($id) => (int) $id)->all();
    }

    public static function started(Sprint $sprint): void
    {
        $open = $sprint->items()->whereNull('parent_id')->whereNull('completed_at')->count();
        $done = $sprint->items()->whereNull('parent_id')->whereNotNull('completed_at')->count();

        self::send(self::recipientIds($sprint), fn (?User $actor) => new SprintStartedNotification($sprint, $actor, $open, $done));
    }

    /**
     * @param  list<int>  $recipientIds  from recipientIds(), taken before the sprint was completed
     */
    public static function completed(Sprint $sprint, array $recipientIds, int $openMoved): void
    {
        self::send($recipientIds, fn (?User $actor) => new SprintCompletedNotification($sprint, $actor, $openMoved, (int) $sprint->completed_issues));
    }

    private static function send(array $recipientIds, \Closure $make): void
    {
        $actor = auth()->user();
        $actor = $actor instanceof User ? $actor : null;
        $ids = array_values(array_diff(array_unique($recipientIds), [$actor?->id]));
        if ($ids === []) {
            return;
        }

        DB::afterCommit(function () use ($ids, $make, $actor) {
            foreach (User::whereIn('id', $ids)->get() as $user) {
                $user->notify($make($actor));
            }
        });
    }
}
