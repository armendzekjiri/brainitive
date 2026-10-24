<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\TimeEntry;
use App\Models\WorkTimer;
use App\Support\Billing\TimeLog;
use App\Support\Billing\TimeLogActivities;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Focus timer (BRAI-74): one running timer per user, on a board item. Stopping logs the
 * reviewed duration through Item::logWork() exactly once — the timer row is deleted in the
 * same transaction, so a retried stop finds nothing and logs nothing.
 * List tasks have no work log, so they can't be timed.
 */
class WorkTimerController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['item_id' => 'required|integer']);
        $item = Item::findOrFail($data['item_id']);
        $this->authorize('update', $item); // the same right as logging work

        $busy = ['timer' => 'A timer is already running — stop it first.'];
        if (WorkTimer::where('user_id', $request->user()->id)->exists()) {
            return back()->withErrors($busy);
        }
        try {
            WorkTimer::create(['user_id' => $request->user()->id, 'item_id' => $item->id, 'started_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors($busy); // started in another tab a moment ago
        }

        return back();
    }

    /** Stop the timer and log the reviewed time. */
    public function stop(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'timer_id' => 'required|integer',
            'time' => Item::durationRules(),
            'note' => 'nullable|string|max:255',
            'activity_id' => 'nullable',
            'spent_on' => TimeLog::spentOnRules($request->user()),
        ]);
        $minutes = Item::parseDuration($data['time']);

        $result = DB::transaction(function () use ($request, $minutes, $data) {
            // Only the timer the user reviewed: a stale tab must not log onto a newer timer.
            $timer = WorkTimer::where('user_id', $request->user()->id)->whereKey($data['timer_id'])->lockForUpdate()->first();
            if (! $timer) {
                return 'none';
            }
            $item = $timer->item;
            $timer->delete();
            if (! $item || Gate::denies('update', $item)) {
                return 'denied';
            }
            // A missing / unavailable activity throws: the transaction rolls back and the timer keeps running.
            $activity = TimeLogActivities::resolve($item->board->project->workspace_id, $data['activity_id'] ?? null);
            $item->logWork($minutes, $data['note'] ?? null, $activity, $data['spent_on'] ?? null, TimeEntry::SOURCE_TIMER);

            return $item;
        });

        return match (true) {
            $result === 'none' => back()->with('success', 'This timer was already stopped.'),
            $result === 'denied' => back()->withErrors(['timer' => 'You can no longer log work on this issue. The timer was stopped.']),
            default => back()->with('success', 'Logged '.Item::formatDuration($minutes).' on '.$result->key.'.'),
        };
    }

    /** Stop without logging anything. */
    public function discard(Request $request): RedirectResponse
    {
        $data = $request->validate(['timer_id' => 'required|integer']);
        WorkTimer::where('user_id', $request->user()->id)->whereKey($data['timer_id'])->delete();

        return back();
    }
}
