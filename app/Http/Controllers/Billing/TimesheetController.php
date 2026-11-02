<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingActivity;
use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Support\Billing\TimeEntries;
use App\Support\Billing\TimeLog;
use App\Support\Billing\TimeLogActivities;
use App\Support\Boards\ProjectIssues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My timesheet (v1.38.0, BRAI-99): a week of your own time entries in a workspace — add, edit,
 * delete. Every workspace member has one; nothing here carries a price. Entries on an issue are
 * logged through Item::logWork() like "Log work" and the timer; TimeEntry's model events keep
 * items.time_spent right on edit, move and delete. Billed (locked) entries never change.
 */
class TimesheetController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);
        $user = $request->user();

        $week = TimeEntries::week($user, $request->query('week'));
        $entries = TimeEntries::between($user, $workspace, $week['start'], $week['end']);

        $accessible = array_flip($user->accessibleProjectIds());
        $loggable = TimeLog::loggableProjectIds($user, $workspace);

        return Inertia::render('Timesheet/Index', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'week' => $week,
            'entries' => $entries->map(fn (TimeEntry $entry) => $this->entryPayload($workspace, $entry, $accessible))->values(),
            'projects' => Project::whereIn('id', $loggable)->orderBy('name')->get(['id', 'name', 'key', 'slug']),
            'activities' => TimeLogActivities::options($workspace),
            'activityRequired' => TimeLogActivities::required($workspace),
            'defaultActivityId' => TimeLogActivities::defaultFor($user, $workspace),
        ]);
    }

    /**
     * Member-facing activity options for a log form (Log work, timer, timesheet): never a rate.
     * `item_id` adds the issue's preselected activity (ticket type default, then last used).
     */
    public function activities(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $item = null;
        if (filter_var($request->query('item_id'), FILTER_VALIDATE_INT) !== false) {
            $item = Item::whereKey((int) $request->query('item_id'))
                ->whereIn('board_id', Board::whereIn('project_id', Project::where('workspace_id', $workspace->id)->select('id'))->select('id'))
                ->first();
            if ($item && Gate::denies('view', $item)) {
                $item = null;
            }
        }

        return response()->json([
            'activities' => TimeLogActivities::options($workspace),
            'required' => TimeLogActivities::required($workspace),
            'default_activity_id' => TimeLogActivities::defaultFor($request->user(), $workspace, $item),
        ]);
    }

    /** Issues of a project you can log on, for the timesheet's issue picker. */
    public function items(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);
        $data = $request->validate([
            'project_id' => ['required', 'integer', Rule::in(TimeLog::loggableProjectIds($request->user(), $workspace))],
            'q' => 'nullable|string|max:100',
        ]);
        $project = Project::findOrFail($data['project_id']);

        return response()->json([
            'items' => ProjectIssues::find($project, $data['q'] ?? null, null, 20)
                ->map(fn (Item $item) => ['id' => $item->id, 'key' => $item->key, 'title' => $item->title])
                ->values(),
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);
        $user = $request->user();
        $data = $request->validate(TimeEntries::rules($user, $workspace));

        $entry = TimeEntries::create($user, $workspace, $data);

        return back()->with('success', 'Logged '.Item::formatDuration($entry->minutes).'.');
    }

    public function update(Request $request, Workspace $workspace, TimeEntry $timeEntry): RedirectResponse
    {
        $this->guard($request, $workspace, $timeEntry);
        $data = $request->validate(TimeEntries::rules($request->user(), $workspace, $timeEntry));

        TimeEntries::update($request->user(), $workspace, $timeEntry, $data);

        return back()->with('success', 'Time entry updated.');
    }

    public function destroy(Request $request, Workspace $workspace, TimeEntry $timeEntry): RedirectResponse
    {
        $this->guard($request, $workspace, $timeEntry);
        TimeEntries::delete($timeEntry);

        return back()->with('success', 'Time entry deleted.');
    }

    /** The entry is in this workspace, yours (or you're a workspace admin), and not billed. */
    private function guard(Request $request, Workspace $workspace, TimeEntry $entry): void
    {
        $this->authorize('view', $workspace); // former members can't change what is billed here
        TimeEntries::guard($request->user(), $workspace, $entry);
    }

    /**
     * One entry for the timesheet. No money. The issue's live key / title / link only while you can
     * still open it (where it is now — issues move between projects); otherwise the snapshot.
     *
     * @param  array<int, int>  $accessible  project ids you can open, as keys
     */
    private function entryPayload(Workspace $workspace, TimeEntry $entry, array $accessible): array
    {
        $item = TimeEntries::visibleIssue($entry, $accessible);
        $board = $item?->board;
        $activity = $entry->activity;

        return [
            'id' => $entry->id,
            'spent_on' => $entry->spent_on->toDateString(),
            'minutes' => $entry->minutes,
            'note' => $entry->note,
            'billable' => $entry->billable,
            'source' => $entry->source,
            'locked' => $entry->isLocked(),
            'project' => TimeEntries::visibleProject($entry, $accessible), // never a moved project's new name (Codex web review S)
            'item' => $entry->item_id || $entry->item_key ? [
                'id' => $entry->item_id,
                'key' => $item?->key ?? $entry->item_key,
                'title' => $item?->title ?? $entry->item_title,
                'url' => $item ? route('boards.items.show', [$board->project->workspace->slug, $board->project->slug, $board->slug, $item->id]) : null,
            ] : null,
            'activity' => $activity instanceof BillingActivity ? TimeLogActivities::option($activity) + ['archived' => $activity->archived_at !== null] : null,
        ];
    }
}
