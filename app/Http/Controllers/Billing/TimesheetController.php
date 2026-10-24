<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingActivity;
use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Support\Billing\TimeLog;
use App\Support\Billing\TimeLogActivities;
use App\Support\Boards\ProjectIssues;
use App\Support\Workload\WorkloadWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        $current = WorkloadWeeks::current($user);
        $requested = $request->query('week');
        $monday = is_string($requested) && $this->isDate($requested) ? WorkloadWeeks::monday($requested) : $current;
        $sunday = CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC')->addDays(6)->toDateString();

        $entries = TimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$monday, $sunday])
            ->with([
                'project:id,name,key,slug',
                'activity:id,name,color,pricing_type,billable,archived_at',
                'item' => fn ($q) => $q->select(['id', 'board_id', 'key', 'title'])->with('board:id,slug,project_id', 'board.project:id,slug,workspace_id', 'board.project.workspace:id,slug'),
            ])
            ->orderBy('spent_on')->orderBy('id')
            ->get();

        $accessible = array_flip($user->accessibleProjectIds());
        $loggable = TimeLog::loggableProjectIds($user, $workspace);

        return Inertia::render('Timesheet/Index', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'week' => [
                'start' => $monday,
                'end' => $sunday,
                'previous' => WorkloadWeeks::add($monday, -1),
                'next' => WorkloadWeeks::add($monday, 1),
                'current' => $current,
                'number' => (int) CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC')->isoWeek(),
                'today' => now($user->localTimezone())->toDateString(),
            ],
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
        $data = $request->validate($this->rules($request, $workspace));

        $activity = TimeLogActivities::resolve($workspace, $data['activity_id'] ?? null);
        $item = $this->loggableItem((int) $data['project_id'], $data['item_id'] ?? null);
        $minutes = Item::parseDuration($data['time']);
        $note = $this->note($data['note'] ?? null);
        // only workspace admins choose billable; everyone else gets the activity's default (v1.39.0)
        $billable = isset($data['billable']) && Gate::allows('manageBilling', $workspace) ? (bool) $data['billable'] : null;

        if ($item) {
            $item->logWork($minutes, $note, $activity, $data['spent_on'], TimeEntry::SOURCE_MANUAL, $billable);
        } else {
            TimeEntry::create([
                'workspace_id' => $workspace->id,
                'project_id' => (int) $data['project_id'],
                'user_id' => $user->id,
                'billing_activity_id' => $activity?->id,
                'spent_on' => $data['spent_on'],
                'minutes' => $minutes,
                'note' => $note,
                'billable' => $billable ?? ($activity ? (bool) $activity->billable : true),
                'source' => TimeEntry::SOURCE_MANUAL,
            ]);
        }

        return back()->with('success', 'Logged '.Item::formatDuration($minutes).'.');
    }

    public function update(Request $request, Workspace $workspace, TimeEntry $timeEntry): RedirectResponse
    {
        $this->guard($request, $workspace, $timeEntry);
        $data = $request->validate($this->rules($request, $workspace, $timeEntry));

        $activity = TimeLogActivities::resolve($workspace, $data['activity_id'] ?? null, $timeEntry->billing_activity_id);
        $minutes = Item::parseDuration($data['time']);
        $projectId = (int) $data['project_id'];
        $itemId = isset($data['item_id']) ? (int) $data['item_id'] : null;

        $currentItemId = $timeEntry->item_id !== null ? (int) $timeEntry->item_id : null;
        $moved = $projectId !== (int) $timeEntry->project_id || $itemId !== $currentItemId;
        if ($timeEntry->source === TimeEntry::SOURCE_LEGACY && ($moved || $minutes !== $timeEntry->minutes)) {
            throw ValidationException::withMessages(['entry' => 'This time was logged before the timesheet existed: only its date, activity and note can change.']);
        }

        $item = null;
        if ($moved) {
            if (! in_array($projectId, TimeLog::loggableProjectIds($request->user(), $workspace), true)) {
                throw ValidationException::withMessages(['project_id' => 'You can’t log time on this project.']);
            }
            $item = $this->loggableItem($projectId, $itemId);
        }

        $billable = match (true) {
            isset($data['billable']) && Gate::allows('manageBilling', $workspace) => (bool) $data['billable'],
            $activity?->id !== $timeEntry->billing_activity_id => $activity ? (bool) $activity->billable : true,
            default => $timeEntry->billable,
        };

        DB::transaction(function () use ($timeEntry, $data, $activity, $minutes, $projectId, $itemId, $moved, $item, $billable) {
            $timeEntry = $this->lockUnbilled($timeEntry);
            $oldItemId = $timeEntry->item_id;
            $oldMinutes = $timeEntry->minutes;

            $timeEntry->fill([
                'project_id' => $projectId,
                'item_id' => $itemId,
                'billing_activity_id' => $activity?->id,
                'spent_on' => $data['spent_on'],
                'minutes' => $minutes,
                'note' => $this->note($data['note'] ?? null),
                'billable' => $billable,
            ]);
            if ($moved) {
                $timeEntry->item_key = $item?->key;
                $timeEntry->item_title = $item ? Str::limit((string) $item->title, 250, '') : null;
            }
            $timeEntry->save(); // the model events move the minutes between the issues' totals

            // the issues' history shows what happened to logged work
            if ($oldItemId && $oldItemId !== $itemId) {
                $this->history($oldItemId, 'work_log_removed', ['minutes' => $oldMinutes, 'moved_to' => $item?->key]);
            }
            if ($itemId && $oldItemId !== $itemId) {
                $this->history($itemId, 'work_logged', ['minutes' => $minutes, 'note' => $timeEntry->note, 'activity' => $activity?->name, 'time_entry_id' => $timeEntry->id]);
            } elseif ($itemId && $oldMinutes !== $minutes) {
                $this->history($itemId, 'work_log_updated', ['from_minutes' => $oldMinutes, 'minutes' => $minutes]);
            }
        });

        return back()->with('success', 'Time entry updated.');
    }

    public function destroy(Request $request, Workspace $workspace, TimeEntry $timeEntry): RedirectResponse
    {
        $this->guard($request, $workspace, $timeEntry);
        if ($timeEntry->source === TimeEntry::SOURCE_LEGACY) {
            throw ValidationException::withMessages(['entry' => 'Time logged before the timesheet existed can’t be deleted — mark it as not billable instead.']);
        }

        DB::transaction(function () use ($timeEntry) {
            $timeEntry = $this->lockUnbilled($timeEntry);
            $timeEntry->delete(); // the model event takes the minutes off the issue's total
            if ($timeEntry->item_id) {
                $this->history($timeEntry->item_id, 'work_log_removed', ['minutes' => $timeEntry->minutes]);
            }
        });

        return back()->with('success', 'Time entry deleted.');
    }

    /** The entry is in this workspace, yours (or you're a workspace admin), and not billed. */
    private function guard(Request $request, Workspace $workspace, TimeEntry $entry): void
    {
        $this->authorize('view', $workspace); // former members can't change what is billed here
        abort_unless((int) $entry->workspace_id === $workspace->id, 404);
        abort_unless(TimeLog::canChange($request->user(), $entry), 403);
        if ($entry->isLocked()) {
            throw ValidationException::withMessages(['entry' => 'This time is on a finalized billing report and can’t be changed.']);
        }
    }

    /** Re-read the row under a lock: a report finalized meanwhile wins, the entry stays as billed. */
    private function lockUnbilled(TimeEntry $entry): TimeEntry
    {
        $fresh = TimeEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if ($fresh->isLocked()) {
            throw ValidationException::withMessages(['entry' => 'This time is on a finalized billing report and can’t be changed.']);
        }

        return $fresh;
    }

    private function rules(Request $request, Workspace $workspace, ?TimeEntry $entry = null): array
    {
        $projects = TimeLog::loggableProjectIds($request->user(), $workspace);
        if ($entry) {
            $projects[] = (int) $entry->project_id; // an entry may stay where it is
        }

        return [
            'project_id' => ['required', 'integer', Rule::in($projects)],
            'item_id' => 'nullable|integer',
            'spent_on' => TimeLog::spentOnRules($request->user(), true),
            'time' => Item::durationRules(),
            'activity_id' => 'nullable',
            'note' => 'nullable|string|max:255',
            'billable' => 'nullable|boolean',
        ];
    }

    /** An issue of the project that you may edit (= log work on), or a validation error. */
    private function loggableItem(int $projectId, mixed $itemId): ?Item
    {
        if ($itemId === null) {
            return null;
        }
        $item = Item::whereKey((int) $itemId)
            ->whereIn('board_id', Board::where('project_id', $projectId)->select('id'))
            ->first();
        if (! $item || Gate::denies('update', $item)) {
            throw ValidationException::withMessages(['item_id' => 'You can’t log time on this issue.']);
        }

        return $item;
    }

    private function history(int $itemId, string $action, array $metadata): void
    {
        $item = Item::withTrashed()->find($itemId);
        if ($item) {
            ActivityLog::log($item, $action, null, array_filter($metadata, fn ($v) => $v !== null));
        }
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private function isDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== null && $date->toDateString() === $value;
    }

    /**
     * One entry for the timesheet. No money. The issue's live key / title / link only while you can
     * still open it (where it is now — issues move between projects); otherwise the snapshot.
     *
     * @param  array<int, int>  $accessible  project ids you can open, as keys
     */
    private function entryPayload(Workspace $workspace, TimeEntry $entry, array $accessible): array
    {
        $item = $entry->item;
        $board = $item?->board;
        $item = $item && $board && $board->project?->workspace && isset($accessible[(int) $board->project_id]) ? $item : null;
        $activity = $entry->activity;

        return [
            'id' => $entry->id,
            'spent_on' => $entry->spent_on->toDateString(),
            'minutes' => $entry->minutes,
            'note' => $entry->note,
            'billable' => $entry->billable,
            'source' => $entry->source,
            'locked' => $entry->isLocked(),
            'project' => $entry->project ? $entry->project->only(['id', 'name', 'key']) : null,
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
