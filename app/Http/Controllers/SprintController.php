<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Workspace;
use App\Support\Boards\SprintLifecycle;
use App\Support\Boards\SprintReport;
use App\Support\Live\BoardLive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scrum: backlog planning, sprint lifecycle and sprint reports for a board.
 */
class SprintController extends Controller
{
    /**
     * Backlog: the active + planned sprints and the backlog, each in rank order.
     */
    public function backlog(Request $request, Workspace $workspace, Project $project, Board $board): Response
    {
        $this->authorize('view', $board);

        $sprints = $board->sprints()
            ->whereIn('status', [Sprint::STATUS_ACTIVE, Sprint::STATUS_PLANNED])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->get();

        $items = $board->items()
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->whereNull('sprint_id')->orWhereIn('sprint_id', $sprints->pluck('id')))
            ->orderBy('backlog_position')
            ->orderBy('id')
            ->with(['itemType', 'priority', 'assignee:id,name,email,avatar', 'column:id,name,position,is_done_column', 'labels'])
            ->withCount('children')
            ->get();

        return Inertia::render('Boards/Backlog', [
            'workspace' => $workspace,
            'project' => $project,
            'board' => $board->load('columns:id,board_id,name,position,is_done_column'),
            'sprints' => $sprints->map(fn (Sprint $sprint) => $sprint->setRelation('items', $items->where('sprint_id', $sprint->id)->values())),
            'backlogItems' => $items->whereNull('sprint_id')->values(),
            'completedSprintCount' => $board->sprints()->where('status', Sprint::STATUS_COMPLETED)->count(),
            'canManage' => $request->user()->can('update', $board),
        ]);
    }

    /**
     * Sprint reports: burndown of the active (or a chosen) sprint + velocity.
     */
    public function reports(Request $request, Workspace $workspace, Project $project, Board $board): Response
    {
        $this->authorize('view', $board);

        $sprints = $board->sprints()
            ->whereIn('status', [Sprint::STATUS_ACTIVE, Sprint::STATUS_COMPLETED])
            ->reorder()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get(['id', 'board_id', 'name', 'status', 'goal', 'start_date', 'end_date', 'started_at', 'completed_at', 'burndown', 'committed_points', 'completed_points', 'committed_issues', 'completed_issues']);

        $selected = $sprints->firstWhere('id', (int) $request->query('sprint')) ?? $sprints->first();

        return Inertia::render('Boards/Reports', [
            'workspace' => $workspace,
            'project' => $project,
            'board' => $board,
            'sprints' => $sprints->map(fn (Sprint $sprint) => $sprint->only(['id', 'name', 'status', 'start_date', 'end_date']))->values(),
            'selectedSprint' => $selected?->only(['id', 'name', 'status', 'goal', 'start_date', 'end_date', 'committed_points', 'completed_points']),
            'burndown' => $selected ? SprintReport::burndown($selected) : null,
            'velocity' => SprintReport::velocity($board),
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project, Board $board): RedirectResponse
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'goal' => 'nullable|string|max:2000',
        ]);

        $number = $board->sprints()->count() + 1;

        $board->sprints()->create([
            'name' => $validated['name'] ?? "{$project->key} Sprint {$number}",
            'goal' => $validated['goal'] ?? null,
            'position' => ($board->sprints()->max('position') ?? 0) + 1,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Sprint created.');
    }

    public function update(Request $request, Workspace $workspace, Project $project, Board $board, Sprint $sprint): RedirectResponse
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'goal' => 'nullable|string|max:2000',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($sprint->isCompleted()) {
            return back()->withErrors(['sprint' => 'Completed sprints can no longer be edited.']);
        }

        $sprint->update($validated);

        return back()->with('success', 'Sprint updated.');
    }

    /**
     * Delete a sprint that hasn't started; its issues go back to the backlog.
     */
    public function destroy(Workspace $workspace, Project $project, Board $board, Sprint $sprint): RedirectResponse
    {
        $this->authorize('update', $board);

        if (! $sprint->isPlanned()) {
            return back()->withErrors(['sprint' => "Only sprints that haven't started can be deleted."]);
        }

        DB::transaction(function () use ($sprint) {
            foreach ($sprint->items()->whereNull('parent_id')->get() as $item) {
                $item->moveToSprint(null);
            }
            $sprint->delete();
        });

        return back()->with('success', 'Sprint deleted — its issues are back in the backlog.');
    }

    public function start(Request $request, Workspace $workspace, Project $project, Board $board, Sprint $sprint): RedirectResponse
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'goal' => 'nullable|string|max:2000',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        try {
            $sprint = SprintLifecycle::start($board, $sprint, $validated['start_date'] ?? null, $validated['end_date'] ?? null, $validated['goal'] ?? null, $validated['name'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['sprint' => $e->getMessage()]);
        }

        return redirect()
            ->route('boards.show', [$workspace->slug, $project->slug, $board->slug])
            ->with('success', "{$sprint->name} started.");
    }

    public function complete(Request $request, Workspace $workspace, Project $project, Board $board, Sprint $sprint): RedirectResponse
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'move_open_to' => ['nullable', Rule::exists('sprints', 'id')->where('board_id', $board->id)->where('status', Sprint::STATUS_PLANNED)],
        ]);

        $target = isset($validated['move_open_to']) ? Sprint::find($validated['move_open_to']) : null;

        try {
            $moved = SprintLifecycle::complete($board, $sprint, $target);
        } catch (\DomainException $e) {
            return back()->withErrors(['sprint' => $e->getMessage()]);
        }

        $where = $target ? $target->name : 'the backlog';

        return redirect()
            ->route('boards.reports', [$workspace->slug, $project->slug, $board->slug, 'sprint' => $sprint->id])
            ->with('success', $moved > 0
                ? "{$sprint->name} completed — {$moved} open issue(s) moved to {$where}."
                : "{$sprint->name} completed.");
    }

    /**
     * Drag & drop in the backlog: put these issues, in this order, into a sprint (or the
     * backlog with sprint_id = null). Only the listed issues are touched.
     */
    public function rank(Request $request, Workspace $workspace, Project $project, Board $board): RedirectResponse
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'sprint_id' => ['nullable', Rule::exists('sprints', 'id')->where('board_id', $board->id)->whereIn('status', [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE])],
            'item_ids' => 'required|array|max:1000',
            'item_ids.*' => ['integer', Rule::exists('items', 'id')->where('board_id', $board->id)->whereNull('parent_id')],
        ]);

        $sprint = isset($validated['sprint_id']) ? Sprint::find($validated['sprint_id']) : null;

        DB::transaction(function () use ($validated, $sprint) {
            foreach (array_values($validated['item_ids']) as $index => $itemId) {
                $item = Item::find($itemId);
                if ($item->sprint_id !== $sprint?->id) {
                    $item->moveToSprint($sprint);
                }
                // Rank only — query builder on purpose so reordering doesn't flood the history
                Item::whereKey($itemId)->update(['backlog_position' => $index + 1]);
            }
        });
        BoardLive::itemIds($validated['item_ids'], ['backlog_position'], $board->id);

        return back();
    }
}
