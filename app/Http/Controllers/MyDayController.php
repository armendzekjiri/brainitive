<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Support\MyDay\MyDayPlan;
use App\Support\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * My Day (BRAI-74) / My Week (v1.53.0): the lines a user plans per day, shown on top of My Work —
 * linked tasks and free-form notes. Only the plan changes here — status, due dates and assignees of
 * linked tasks are never touched (their circle goes through the task's own routes).
 *
 * `store` / `destroy` / `reorder` work on today by work key (the ☀ toggle); the `*Entry` actions work
 * on any date by entry id (the week view).
 */
class MyDayController extends Controller
{
    /** Add a board item or list task to today's plan (adding it twice is a no-op). */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateWork($request);
        MyDayPlan::add($request->user(), $data['work_type'], (int) $data['work_id']);

        return back();
    }

    /** Take a task off today's plan. */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_type' => ['required', Rule::in(MyDayEntry::TYPES)],
            'work_id' => 'required|integer',
        ]);

        MyDayPlan::remove($request->user(), $data['work_type'], (int) $data['work_id']);

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

    /** Bring unfinished tasks (and notes) from earlier days onto today's plan (only when asked). */
    public function carryOver(Request $request): RedirectResponse
    {
        $count = MyDayPlan::carryOver($request->user(), withNotes: true);

        return back()->with('success', $count === 1 ? 'Brought over 1 task.' : "Brought over {$count} tasks.");
    }

    /**
     * Add a line to any date or to Someday (`plan_date = someday`): a free-form note (`title`) or a
     * linked task (`work_type` + `work_id`).
     */
    public function storeEntry(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'plan_date' => ['required', 'string', $this->plannable($request)],
            'title' => 'required_without:work_type|nullable|string|max:500',
            'color' => ['nullable', Rule::in(MyDayEntry::COLORS)],
            'work_type' => ['required_without:title', 'nullable', Rule::in(MyDayEntry::TYPES)],
            'work_id' => 'required_with:work_type|nullable|integer',
        ]);

        if (! MyDayPlan::hasRoom($user, $this->day($data['plan_date']))) {
            return back()->withErrors(['plan_date' => 'This list is full ('.MyDayPlan::LIST_LIMIT.' lines).']);
        }
        if (! empty($data['work_type'])) {
            $work = $this->validateWork($request);
            MyDayPlan::add($user, $work['work_type'], (int) $work['work_id'], $data['plan_date']);
        } else {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return back()->withErrors(['title' => 'Write something first.']);
            }
            MyDayPlan::addNote($user, $this->day($data['plan_date']), $title, $data['color'] ?? null);
        }

        return back();
    }

    /**
     * Edit a line: move it to the end of another day (`plan_date`, any line), or a note's title,
     * colour and done state. A linked task's own state goes through the task's routes.
     */
    public function updateEntry(Request $request, MyDayEntry $entry): RedirectResponse
    {
        $this->own($request, $entry);
        $data = $request->validate([
            'plan_date' => ['sometimes', 'string', $this->plannable($request)],
            'title' => 'sometimes|required|string|max:500',
            'color' => ['sometimes', 'nullable', Rule::in(MyDayEntry::COLORS)],
            'done' => 'sometimes|boolean',
        ]);
        $noteFields = array_intersect_key($data, array_flip(['title', 'color', 'done']));
        abort_if($noteFields !== [] && ! $entry->isNote(), 422, 'Only free-form lines can be edited here.');

        if (array_key_exists('title', $data)) {
            $title = trim($data['title']);
            if ($title === '') {
                return back()->withErrors(['title' => 'Write something first.']);
            }
            $entry->title = $title;
        }
        if (array_key_exists('color', $data)) {
            $entry->color = $data['color'];
        }
        if (array_key_exists('done', $data)) {
            $entry->completed_at = $data['done'] ? ($entry->completed_at ?? now()) : null;
        }
        $entry->save();

        if (isset($data['plan_date']) && $this->day($data['plan_date']) !== $entry->plan_date?->toDateString()) {
            if (! MyDayPlan::hasRoom($request->user(), $this->day($data['plan_date']))) {
                return back()->withErrors(['plan_date' => 'That list is full ('.MyDayPlan::LIST_LIMIT.' lines).']);
            }
            MyDayPlan::moveToEnd($request->user(), $entry, $this->day($data['plan_date']));
        }

        return back();
    }

    /** Take a line off the plan (a linked task itself stays as it is). */
    public function destroyEntry(Request $request, MyDayEntry $entry): RedirectResponse
    {
        $this->own($request, $entry);
        $entry->delete();

        return back();
    }

    /** Put lines on a date in this order — also how a line moves to another day. */
    public function reorderEntries(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan_date' => ['required', 'string', $this->plannable($request)],
            'ids' => 'required|array|max:'.MyDayPlan::LIST_LIMIT,
            'ids.*' => 'integer',
        ]);

        MyDayPlan::arrange($request->user(), $this->day($data['plan_date']), array_map('intval', $data['ids']));

        return back();
    }

    /**
     * Issues and list tasks to link to a plan line (`#text` in the week view), from the search
     * index — the same visibility as search.
     */
    public function search(Request $request, SearchService $search): JsonResponse
    {
        $user = $request->user();
        $text = trim((string) $request->input('q', ''));
        if (mb_strlen($text) < 2) {
            return response()->json(['results' => []]);
        }

        $results = collect(['issues' => 'board', 'tasks' => 'list'])->flatMap(function (string $workType, string $group) use ($search, $user, $text) {
            $found = $search->search($user, SearchService::queryFrom(['q' => $text, 'type' => $group], $user, 6, withCounts: false));

            return collect($found['results'])->map(fn (array $hit) => [
                'work_type' => $workType,
                'work_id' => (int) $hit['source_id'],
                'key' => $hit['key'],
                'title' => $hit['title'],
                'project' => $hit['project']['name'] ?? null,
                'context' => $hit['context'],
                'done' => $hit['status'] === 'done',
            ]);
        })->values();

        return response()->json(['results' => $results]);
    }

    private function own(Request $request, MyDayEntry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
    }

    /** A day within a year of today, or `someday`. */
    private function plannable(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request) {
            if ($value === MyDayPlan::SOMEDAY) {
                return;
            }
            if (! is_string($value) || ! MyDayPlan::plannable($request->user(), $value)) {
                $fail('Pick a day within a year from today.');
            }
        };
    }

    /** The stored date of a validated `plan_date` (null = Someday). */
    private function day(string $planDate): ?string
    {
        return $planDate === MyDayPlan::SOMEDAY ? null : $planDate;
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
}
