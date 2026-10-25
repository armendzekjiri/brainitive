<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Project;
use App\Models\RecurringIssue;
use App\Models\Workspace;
use App\Support\RecurringIssues\RecurringIssueForm;
use App\Support\RecurringIssues\RecurringIssueRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recurring issues of a board (v1.40.0, BRAI-123), JSON for the board header's "Recurring issues"
 * dialog and the issue's "Make this recurring". Everything is allowed to whoever can create issues on
 * the board (BoardPolicy::createItems); members without that right get 403.
 */
class RecurringIssueController extends Controller
{
    private const PREVIEW_COUNT = 5;

    public function index(Request $request, Workspace $workspace, Project $project, Board $board): JsonResponse
    {
        $this->authorize('createItems', $board);

        $templates = $board->recurringIssues()->with('creator:id,name')->orderBy('title')->orderBy('id')->get();

        return response()->json([
            'templates' => $templates->map(fn (RecurringIssue $t) => RecurringIssueForm::present($t))->values(),
            'options' => RecurringIssueForm::options($board, $project),
            'timezone' => $request->user()->localTimezone(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project, Board $board): JsonResponse
    {
        $this->authorize('createItems', $board);

        $validated = $request->validate(RecurringIssueForm::rules($board, $project));
        $timezone = $request->user()->localTimezone();

        $template = new RecurringIssue([
            ...RecurringIssueForm::attributes($validated),
            'board_id' => $board->id,
            'created_by' => $request->user()->id,
            'timezone' => $timezone,
        ]);
        $template->active_from = $template->localToday()->toDateString();
        $template->refreshNextOccurrence()->save();

        return response()->json(['template' => RecurringIssueForm::present($template->load('creator:id,name'))], 201);
    }

    public function update(Request $request, Workspace $workspace, Project $project, Board $board, RecurringIssue $recurringIssue): JsonResponse
    {
        $this->authorize('createItems', $board);

        $validated = $request->validate(RecurringIssueForm::rules($board, $project));
        $recurringIssue->fill(RecurringIssueForm::attributes($validated));

        // A new schedule starts today: past dates of the new rule are never created afterwards.
        if ($recurringIssue->isDirty(RecurringIssue::SCHEDULE_FIELDS)) {
            $recurringIssue->active_from = $recurringIssue->localToday()->toDateString();
        }
        $recurringIssue->refreshNextOccurrence()->save();

        return response()->json(['template' => RecurringIssueForm::present($recurringIssue->load('creator:id,name'))]);
    }

    public function destroy(Workspace $workspace, Project $project, Board $board, RecurringIssue $recurringIssue): JsonResponse
    {
        $this->authorize('createItems', $board);

        // the issues it created stay; they just lose the "Repeats …" badge
        $recurringIssue->delete();

        return response()->json(['deleted' => true]);
    }

    public function pause(Workspace $workspace, Project $project, Board $board, RecurringIssue $recurringIssue): JsonResponse
    {
        $this->authorize('createItems', $board);

        if ($recurringIssue->paused_at === null) {
            $recurringIssue->update(['paused_at' => now()]);
        }

        return response()->json(['template' => RecurringIssueForm::present($recurringIssue->load('creator:id,name'))]);
    }

    /**
     * Resume from today (dates missed while paused are not created). Whoever resumes it runs it from now
     * on — this is how a template whose creator lost access gets going again.
     */
    public function resume(Request $request, Workspace $workspace, Project $project, Board $board, RecurringIssue $recurringIssue): JsonResponse
    {
        $this->authorize('createItems', $board);

        if ($recurringIssue->paused_at !== null) {
            $recurringIssue->fill([
                'paused_at' => null,
                'last_error' => null,
                'created_by' => $request->user()->id,
            ]);
            $recurringIssue->active_from = $recurringIssue->localToday()->toDateString();
            $recurringIssue->refreshNextOccurrence()->save();
        }

        return response()->json(['template' => RecurringIssueForm::present($recurringIssue->load('creator:id,name'))]);
    }

    /** The next dates of a schedule being edited (the template's own state when `id` is given). */
    public function preview(Request $request, Workspace $workspace, Project $project, Board $board): JsonResponse
    {
        $this->authorize('createItems', $board);

        $validated = $request->validate([
            ...RecurringIssueForm::scheduleRules(),
            'id' => ['nullable', 'integer'],
        ]);

        $existing = isset($validated['id']) ? $board->recurringIssues()->find($validated['id']) : null;
        $template = new RecurringIssue([
            ...RecurringIssueForm::normalizeSchedule($validated),
            'timezone' => $existing?->timezone ?? $request->user()->localTimezone(),
            'occurrences_count' => $existing?->occurrences_count ?? 0,
            'last_occurrence_date' => $existing?->last_occurrence_date,
        ]);
        $template->active_from = $template->localToday()->toDateString();

        return response()->json([
            'dates' => $template->upcomingDates(self::PREVIEW_COUNT),
            'summary' => $template->schedule()->summary(),
            'badge_label' => $template->badge_label,
        ]);
    }

    /**
     * e2e only (APP_ENV=e2e; the route is not even registered elsewhere): run the scheduler for this
     * board's templates now, as `app:create-recurring-issues` would.
     */
    public function e2eRun(Workspace $workspace, Project $project, Board $board): JsonResponse
    {
        abort_unless(app()->environment('e2e'), 404);
        $this->authorize('createItems', $board);

        $created = $board->recurringIssues()->whereNull('paused_at')->get()
            ->map(fn (RecurringIssue $template) => RecurringIssueRunner::run($template))
            ->filter()
            ->map(fn ($item) => $item->only(['id', 'key', 'title']))
            ->values();

        return response()->json(['created' => $created]);
    }
}
