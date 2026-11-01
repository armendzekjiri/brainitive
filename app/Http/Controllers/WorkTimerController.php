<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Support\Billing\TimeLog;
use App\Support\Billing\WorkTimers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        if (WorkTimers::start($request->user(), $item) === null) {
            return back()->withErrors(['timer' => 'A timer is already running — stop it first.']);
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

        $stopped = WorkTimers::stop($request->user(), (int) $data['timer_id'], $minutes, $data['note'] ?? null, $data['activity_id'] ?? null, $data['spent_on'] ?? null);

        return match ($stopped['result']) {
            WorkTimers::ALREADY_STOPPED => back()->with('success', 'This timer was already stopped.'),
            WorkTimers::NO_ACCESS => back()->withErrors(['timer' => 'You can no longer log work on this issue. The timer was stopped.']),
            default => back()->with('success', 'Logged '.Item::formatDuration($minutes).' on '.$stopped['item']?->key.'.'),
        };
    }

    /** Stop without logging anything. */
    public function discard(Request $request): RedirectResponse
    {
        $data = $request->validate(['timer_id' => 'required|integer']);
        WorkTimers::discard($request->user(), (int) $data['timer_id']);

        return back();
    }
}
