<?php

namespace App\Support\AppApi;

use App\Models\BillingActivity;
use App\Models\User;
use App\Models\WorkTimer;
use App\Support\Billing\TimeLogActivities;
use Illuminate\Validation\ValidationException;

/**
 * A timer stop the workspace's activity rules refused (a missing / unusable `activity_id`): the stop rolled
 * back and the timer runs on. The app gets what it may pick — `activities: [{id, name}]` (no rates) and
 * `default_activity_id` (or null) — so it can show a picker and retry. The same for `POST /me/timer/stop`
 * (a 422 with the extras at the top level) and the sync change `timer.stop` (an `invalid` result with them).
 */
final class TimerStopRefusal
{
    /** The exception with the picker added; any other validation error comes back unchanged. */
    public static function withPicker(ValidationException $e, User $user, ?WorkTimer $timer): ValidationException
    {
        $workspaceId = isset($e->errors()['activity_id']) ? $timer?->item?->board?->project?->workspace_id : null;
        if ($workspaceId === null || $timer === null) {
            return $e;
        }

        return ValidationWithExtras::with($e->errors(), [
            'activities' => TimeLogActivities::active($workspaceId)->map(fn (BillingActivity $a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
            'default_activity_id' => TimeLogActivities::defaultFor($user, $workspaceId, $timer->item),
        ]);
    }
}
