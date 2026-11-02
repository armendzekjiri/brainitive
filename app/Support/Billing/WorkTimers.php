<?php

namespace App\Support\Billing;

use App\Models\Item;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkTimer;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The focus timer (BRAI-74), the same way for the web's top-bar pill (`WorkTimerController`) and the
 * App API (`/api/v1/me/timer`, BRAI-400): one running timer per user, on a board issue the user may
 * edit (logging work needs ItemPolicy::update; list tasks have no work log). Stopping logs the reviewed
 * time through Item::logWork() exactly once: the timer row is deleted in the same transaction, so a
 * retried stop finds nothing and logs nothing. Callers authorize the start.
 */
final class WorkTimers
{
    public const LOGGED = 'logged';

    /** No such running timer (stopped before, e.g. by a retry or on another device). */
    public const ALREADY_STOPPED = 'already_stopped';

    /** The user can no longer log work on the issue: the timer is stopped, nothing is logged. */
    public const NO_ACCESS = 'no_access';

    /** How far back a start time may lie (a timer started offline, sent later: App API sync `timer.start`). */
    public const MAX_BACKDATE_HOURS = 12;

    /**
     * Start a timer on the issue; null when one is already running (stop it first). `$startedAt` (default now)
     * is clamped to the last MAX_BACKDATE_HOURS hours and never lies in the future; `$description` (what the
     * user works on) becomes the work log's note unless the stop gives one.
     */
    public static function start(User $user, Item $item, ?CarbonInterface $startedAt = null, ?string $description = null): ?WorkTimer
    {
        if (WorkTimer::where('user_id', $user->id)->exists()) {
            return null;
        }
        $now = now();
        $startedAt = $startedAt === null ? $now : Carbon::instance($startedAt)->max($now->copy()->subHours(self::MAX_BACKDATE_HOURS))->min($now);
        $description = $description !== null && trim($description) !== '' ? trim($description) : null;
        try {
            return WorkTimer::create(['user_id' => $user->id, 'item_id' => $item->id, 'started_at' => $startedAt]
                + ($description !== null ? ['description' => $description] : []));
        } catch (UniqueConstraintViolationException) {
            return null; // started in another tab / on another device a moment ago
        }
    }

    /**
     * Stop the timer the user reviewed (a stale tab or device must not log onto a newer timer) and log
     * `$minutes`. A missing / unavailable activity throws a ValidationException: the transaction rolls
     * back and the timer keeps running.
     *
     * @return array{result: string, item: Item|null, entry: TimeEntry|null}
     */
    public static function stop(User $user, int $timerId, int $minutes, ?string $note, mixed $activityId, ?string $spentOn): array
    {
        return DB::transaction(function () use ($user, $timerId, $minutes, $note, $activityId, $spentOn) {
            $timer = WorkTimer::where('user_id', $user->id)->whereKey($timerId)->lockForUpdate()->first();
            if (! $timer) {
                return ['result' => self::ALREADY_STOPPED, 'item' => null, 'entry' => null];
            }
            $item = $timer->item;
            $note ??= $timer->description;
            $timer->delete();
            if (! $item || Gate::forUser($user)->denies('update', $item)) {
                return ['result' => self::NO_ACCESS, 'item' => null, 'entry' => null];
            }
            $activity = TimeLogActivities::resolve($item->board->project->workspace_id, $activityId);
            $entry = $item->logWork($minutes, $note, $activity, $spentOn, TimeEntry::SOURCE_TIMER);

            return ['result' => self::LOGGED, 'item' => $item, 'entry' => $entry];
        });
    }

    /** Stop without logging anything; false when that timer wasn't running. */
    public static function discard(User $user, int $timerId): bool
    {
        return WorkTimer::where('user_id', $user->id)->whereKey($timerId)->delete() > 0;
    }
}
