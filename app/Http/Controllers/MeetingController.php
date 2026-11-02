<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Meetings\MeetingCalendars;
use App\Support\Meetings\MeetingScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Online meetings from issues and documents (BRAI-147). The event (with its Google Meet / Teams
 * link) is created in the organiser's own calendar through the calendar provider layer; the
 * `meetings` row links it to the issue / doc. Attendees are invited by the calendar provider
 * (Google `sendUpdates=all`, Outlook invitations) — Brainitive sends no notification of its own.
 */
class MeetingController extends Controller
{
    /** What the schedule dialog needs: the user's meeting calendars and the project's members. */
    public function options(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $user = $request->user();

        return response()->json([
            'calendars' => MeetingCalendars::for($user),
            // names only: the server turns member ids into email addresses
            'members' => $this->members($project)
                ->reject(fn (User $m) => $m->id === $user->id)
                ->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name, 'avatar' => $m->avatar_display])
                ->values(),
            'timezone' => $user->localTimezone(),
            'connect_url' => route('calendar.index'),
            'max_attendees' => Meeting::MAX_ATTENDEES,
        ]);
    }

    /** The calendars for the calendar page's quick create (no project involved). */
    public function calendars(Request $request): JsonResponse
    {
        return response()->json(['calendars' => MeetingCalendars::for($request->user())]);
    }

    /** Meetings of one issue / doc, oldest first (the UI splits upcoming and past). */
    public function index(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(Meeting::SUBJECTS))],
            'subject_id' => ['required', 'string', 'max:64'],
        ]);
        $subject = $this->subject($project, $validated['subject_type'], $validated['subject_id']);
        $this->authorize('view', $subject);
        $user = $request->user();

        // By subject, not by the row's project_id: an issue moved to another project keeps its meetings.
        $meetings = Meeting::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->with(['creator:id,name', 'connection', 'notesPage'])
            ->orderBy('starts_at')->orderBy('id')
            ->limit(200)
            ->get();

        // The rights are the same for every row (one subject): ask once, not per meeting.
        $canUpdate = $user->can('update', $subject);
        $context = [
            'can_update_subject' => $canUpdate,
            'can_create_pages' => $user->can('create', [Page::class, $project]),
            'visible_notes' => Page::query()->visibleTo($user)
                ->whereIn('id', $meetings->pluck('meeting_notes_page_id')->filter()->all())
                ->pluck('id')->all(),
        ];

        return response()->json([
            'meetings' => $meetings->map(fn (Meeting $m) => $this->present($m->setRelation('subject', $subject), $user, $workspace, $project, $context))->values(),
            'can_schedule' => $canUpdate,
        ]);
    }

    /** Create the calendar event (with its video call) and link it to the issue / doc. */
    public function store(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project); // before validation: member ids must not be probed by outsiders
        $user = $request->user();
        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(Meeting::SUBJECTS))],
            'subject_id' => ['required', 'string', 'max:64'],
        ] + MeetingScheduler::rules($project));

        $subject = $this->subject($project, $validated['subject_type'], $validated['subject_id']);
        $connection = MeetingScheduler::connection($user, $validated['connection_id']);
        $this->authorize('create', [Meeting::class, $subject, $connection]);
        [$meeting, $warning] = MeetingScheduler::schedule($user, $project, $subject, $connection, $validated);

        return response()->json([
            'meeting' => $this->present($meeting->load(['creator:id,name', 'connection']), $user, $workspace, $project),
            'warning' => $warning,
        ], 201);
    }

    /**
     * Remove a meeting from its issue / doc. `cancel_event` = also delete the calendar event
     * (the provider tells the attendees); otherwise the event stays and is only unlinked.
     */
    public function destroy(Request $request, Workspace $workspace, Project $project, string $meeting): JsonResponse
    {
        $meeting = $this->meeting($project, $meeting);
        $cancel = $request->boolean('cancel_event');
        $this->authorize($cancel ? 'cancel' : 'delete', $meeting);
        MeetingScheduler::remove($meeting, $cancel);

        return response()->json(['removed' => true, 'cancelled' => $cancel]);
    }

    /**
     * Open the meeting's notes doc, creating it the first time: "Notes — <title> <date>" in the
     * same project (under the doc when the meeting belongs to one), published, linked to the
     * issue / doc. Concurrent clicks create one doc (row lock).
     */
    public function notes(Request $request, Workspace $workspace, Project $project, string $meeting): JsonResponse
    {
        $meeting = $this->meeting($project, $meeting);
        $this->authorize('view', $meeting);
        $user = $request->user();

        [$page, $created] = MeetingScheduler::notesPage($meeting, $user, $project->setRelation('workspace', $workspace));

        return response()->json(['url' => route('pages.show', [$workspace->slug, $project->slug, $page->uuid]), 'created' => $created], $created ? 201 : 200);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** The issue / doc of this project ('item' = numeric id, 'page' = uuid), else 404. */
    private function subject(Project $project, string $type, string $id): Item|Page
    {
        $subject = MeetingScheduler::subject($project, $type, $id);
        abort_if($subject === null, 404);

        return $subject;
    }

    private function meeting(Project $project, string $id): Meeting
    {
        abort_unless(ctype_digit($id), 404);
        $meeting = Meeting::query()->with(['subject', 'connection'])->findOrFail((int) $id);
        // Reachable only through the project its issue / doc is in NOW (an issue can move to
        // another project; the row's project_id is where it was scheduled).
        abort_unless($meeting->subject !== null && (int) $meeting->subject->project_id === $project->id, 404);

        return $meeting;
    }

    /** Project members, plus workspace admins (they open every project) — like hasProjectAccess. */
    private function members(Project $project): Collection
    {
        $admins = $project->workspace->users()->wherePivot('role', 'admin')->get(['users.id', 'users.name', 'users.avatar']);

        return $project->users()->get(['users.id', 'users.name', 'users.avatar'])
            ->concat($admins)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  array{can_update_subject: bool, can_create_pages: bool, visible_notes: list<int>}|null  $context
     * @return array<string, mixed>
     */
    private function present(Meeting $meeting, User $viewer, Workspace $workspace, Project $project, ?array $context = null): array
    {
        return MeetingScheduler::present($meeting, $viewer, $project->setRelation('workspace', $workspace), $context);
    }
}
