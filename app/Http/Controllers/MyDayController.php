<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Support\MyDay\MyDayPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * My Day (BRAI-74): the tasks a user picks for today, shown on top of My Work. Only the plan
 * changes here — status, due dates and assignees of the tasks are never touched.
 */
class MyDayController extends Controller
{
    /** Add a board item or list task to today's plan (adding it twice is a no-op). */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateWork($request);
        $user = $request->user();
        $today = MyDayPlan::today($user);

        MyDayEntry::firstOrCreate(
            ['user_id' => $user->id, 'work_type' => $data['work_type'], 'work_id' => $data['work_id'], 'plan_date' => $today],
            ['position' => $this->nextPosition($user->id, $today)],
        );

        return back();
    }

    /** Take a task off today's plan. */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_type' => ['required', Rule::in(MyDayEntry::TYPES)],
            'work_id' => 'required|integer',
        ]);

        MyDayEntry::where('user_id', $request->user()->id)
            ->where('plan_date', MyDayPlan::today($request->user()))
            ->where('work_type', $data['work_type'])
            ->where('work_id', $data['work_id'])
            ->delete();

        return back();
    }

    /** Save the order of today's plan. */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => 'required|array|max:200',
            'items.*.work_type' => ['required', Rule::in(MyDayEntry::TYPES)],
            'items.*.work_id' => 'required|integer',
        ]);
        $user = $request->user();
        $today = MyDayPlan::today($user);

        foreach ($data['items'] as $position => $work) {
            MyDayEntry::where('user_id', $user->id)->where('plan_date', $today)
                ->where('work_type', $work['work_type'])->where('work_id', $work['work_id'])
                ->update(['position' => $position]);
        }

        return back();
    }

    /** Bring unfinished tasks from earlier days onto today's plan (only when asked). */
    public function carryOver(Request $request): RedirectResponse
    {
        $user = $request->user();
        $today = MyDayPlan::today($user);
        $position = $this->nextPosition($user->id, $today);

        $rows = MyDayPlan::carryOverCandidates($user, $today);
        foreach ($rows as $row) {
            MyDayEntry::firstOrCreate(
                ['user_id' => $user->id, 'work_type' => $row['entry']->work_type, 'work_id' => $row['entry']->work_id, 'plan_date' => $today],
                ['position' => $position++],
            );
        }

        return back()->with('success', $rows->count() === 1 ? 'Brought over 1 task.' : "Brought over {$rows->count()} tasks.");
    }

    /** @return array{work_type: string, work_id: int} */
    private function validateWork(Request $request): array
    {
        $data = $request->validate([
            'work_type' => ['required', Rule::in(MyDayEntry::TYPES)],
            'work_id' => 'required|integer',
        ]);

        // Only work the user can open can go on their plan.
        if ($data['work_type'] === 'board') {
            $this->authorize('view', Item::findOrFail($data['work_id']));
        } else {
            $this->authorize('view', TaskListItem::with('taskList')->findOrFail($data['work_id'])->taskList);
        }

        return $data;
    }

    private function nextPosition(int $userId, string $date): int
    {
        return (int) MyDayEntry::where('user_id', $userId)->where('plan_date', $date)->max('position') + 1;
    }
}
