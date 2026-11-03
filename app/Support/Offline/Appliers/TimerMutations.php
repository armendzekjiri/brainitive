<?php

namespace App\Support\Offline\Appliers;

use App\Models\Item;
use App\Models\WorkTimer;
use App\Support\AppApi\TimerStopRefusal;
use App\Support\Billing\WorkTimers;
use App\Support\MyWork\WorkRef;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The focus timer started / stopped while offline (App API sync): the same timer and rules as
 * `/api/v1/me/timer/*` and the web's top-bar pill (`WorkTimers`, one per user, `ItemPolicy::update` to log
 * on the issue). The device's clock times are clamped: a start lies within the last 12 hours and not in the
 * future, a stop between the start and now. The entity is the timer state (`GET /me/timer`).
 *
 * - `timer.start {issue_id, description?, started_at}` — another timer running → conflict (with it).
 *   `task_id` → invalid (list tasks have no work log).
 * - `timer.stop {timer_id, stopped_at, activity_id?}` — logs the time once, on the user's local day of the
 *   start. An activity the workspace requires → invalid with `activities` + `default_activity_id` (the timer
 *   runs on). No such running timer → gone.
 * - `timer.discard {timer_id}` — stop without logging; not running → gone.
 */
final class TimerMutations
{
    public const ENTITY = 'timer';

    public static function start(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate([
            'issue_id' => 'required_without:task_id|nullable|integer',
            'task_id' => 'nullable|integer',
            'description' => 'nullable|string|max:255',
            'started_at' => 'required|date',
        ]);
        if (! empty($data['task_id'])) {
            throw ValidationException::withMessages(['task_id' => 'List tasks have no work log and can’t be timed. Pick an issue.']);
        }

        $item = WorkRef::visible($ctx->user, WorkRef::ISSUE.':'.(int) $data['issue_id']);
        if (! $item instanceof Item) {
            throw new SyncAbort(SyncOutcome::deleted(IssueMutations::ENTITY, 'This issue was deleted while you were offline.'));
        }
        Gate::forUser($ctx->user)->authorize('update', $item); // logging work on it, as POST /me/timer/start

        $running = WorkTimer::where('user_id', $ctx->user->id)->lockForUpdate()->first();
        $timer = $running ? null : WorkTimers::start($ctx->user, $item, Carbon::parse($data['started_at']), $data['description'] ?? null);
        if ($timer === null) {
            $running ??= WorkTimer::where('user_id', $ctx->user->id)->first();

            return SyncOutcome::conflict(self::ENTITY, self::snapshot($running), (int) $running?->id, [], [], 'A timer is already running — stop it first.');
        }

        return SyncOutcome::applied(self::ENTITY, self::snapshot($timer), $timer->id);
    }

    public static function stop(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate([
            'timer_id' => 'required|integer',
            'stopped_at' => 'required|date',
            'activity_id' => 'nullable|integer',
        ]);
        $timer = self::running($ctx, (int) $data['timer_id']);

        $now = Carbon::now();
        $stoppedAt = Carbon::parse($data['stopped_at'])->min($now)->max($timer->started_at);
        $minutes = max(1, (int) round($timer->started_at->diffInSeconds($stoppedAt) / 60));
        $spentOn = $timer->started_at->copy()->setTimezone($ctx->user->localTimezone())->toDateString();

        try {
            $stopped = WorkTimers::stop($ctx->user, $timer->id, $minutes, null, $data['activity_id'] ?? null, $spentOn);
        } catch (ValidationException $e) {
            // nothing was written (the timer runs on): tell the app what it may pick, as the REST 422
            throw TimerStopRefusal::withPicker($e, $ctx->user, $timer);
        }

        return match ($stopped['result']) {
            WorkTimers::ALREADY_STOPPED => throw new SyncAbort(self::gone()),
            WorkTimers::NO_ACCESS => SyncOutcome::applied(self::ENTITY, self::snapshot(null) + [
                'result' => WorkTimers::NO_ACCESS,
                'message' => 'You can no longer log work on this issue. The timer was stopped, nothing was logged.',
            ], $timer->id),
            default => SyncOutcome::applied(self::ENTITY, self::snapshot(null) + [
                'result' => WorkTimers::LOGGED,
                'work_log_id' => $stopped['entry']?->id,
            ], $timer->id),
        };
    }

    public static function discard(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate(['timer_id' => 'required|integer']);
        $timer = self::running($ctx, (int) $data['timer_id']);
        WorkTimers::discard($ctx->user, $timer->id);

        return SyncOutcome::applied(self::ENTITY, self::snapshot(null), $timer->id);
    }

    /** The user's running timer with this id (a stale device must not stop a newer one); else gone. */
    private static function running(MutationContext $ctx, int $id): WorkTimer
    {
        $timer = WorkTimer::where('user_id', $ctx->user->id)->whereKey($id)->lockForUpdate()->first();
        if (! $timer) {
            throw new SyncAbort(self::gone());
        }

        return $timer;
    }

    private static function gone(): SyncOutcome
    {
        return SyncOutcome::gone(self::ENTITY, 'This timer is no longer running: it was stopped or discarded meanwhile.');
    }

    /**
     * What the web's offline engine stores (the App API renders the current timer state instead).
     *
     * @return array<string, mixed>
     */
    private static function snapshot(?WorkTimer $timer): array
    {
        return [
            'running' => $timer !== null,
            'id' => $timer?->id,
            'item_id' => $timer?->item_id,
            'started_at' => $timer?->started_at?->toIso8601String(),
        ];
    }
}
