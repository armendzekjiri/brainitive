<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListMeetingsRequest;
use App\Http\Requests\AppApi\StoreMeetingRequest;
use App\Http\Resources\AppApi\MeetingNotesResource;
use App\Http\Resources\AppApi\MeetingResource;
use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\Meetings\MeetingScheduler;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — online meetings (Google Meet / Teams) of issues and docs (BRAI-418): the web's "Meeting" action
 * (MeetingController) through the shared MeetingScheduler and MeetingPolicy. The event goes into the
 * organiser's own calendar; the provider invites the attendees. The meeting's notes doc is an ordinary doc (Docs).
 */
#[Group('Calendar', weight: 140)]
class MeetingController extends Controller
{
    use CreatesOnce;

    /**
     * The meetings of an issue or doc, oldest first.
     *
     * Not paginated (at most 200, as the web). Attendee addresses only for the organiser.
     *
     * @throws NotFoundHttpException the issue / doc doesn't exist or the user can't open it (404)
     */
    public function index(ListMeetingsRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $subject = $request->subject();
        $project = $request->subjectProject();

        $meetings = Meeting::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->with(['creator:id,name', 'connection', 'notesPage'])
            ->orderBy('starts_at')->orderBy('id')
            ->limit(200)
            ->get();

        // one subject: the rights are asked once, not per meeting (as the web)
        $context = [
            'can_update_subject' => $user->can('update', $subject),
            'can_create_pages' => $user->can('create', [Page::class, $project]),
            'visible_notes' => Page::query()->visibleTo($user)
                ->whereIn('id', $meetings->pluck('meeting_notes_page_id')->filter()->all())
                ->pluck('id')->all(),
        ];

        return MeetingResource::collection($meetings->map(
            fn (Meeting $m) => $this->row($m->setRelation('subject', $subject), $user, $project, $context),
        )->values());
    }

    /**
     * Schedule an online meeting for an issue or doc.
     *
     * Creates the event (with a Google Meet / Teams call, or `conference: none`) in one of the user's own
     * calendars and links it to the issue / doc. `member_ids` = project members (by id, the server knows their
     * addresses), `emails` = other guests. Needs edit rights on the issue / doc (403); a calendar that isn't
     * the user's → 422 `connection_id`; a call the calendar can't add → 422 `conference`; a read-only calendar
     * → 422 `calendar`. 201, or 200 on a replayed `request_id`. `conference_provider` set while `join_url` is
     * null = the calendar hasn't returned the link yet (open `event_url`).
     *
     * @throws NotFoundHttpException the issue / doc doesn't exist or the user can't open it (404)
     */
    public function store(StoreMeetingRequest $request): JsonResponse
    {
        $user = $request->user();
        $subject = $request->subject();
        $project = $request->subjectProject();
        $validated = $request->validated();
        $validated['timezone'] ??= $user->localTimezone();

        [$meeting, $replayed] = $this->createOnce($request, 'meetings.store', Meeting::class,
            collect($validated)->except('request_id')->all() + ['subject_class' => $subject::class],
            function () use ($user, $project, $subject, $validated) {
                $connection = MeetingScheduler::connection($user, $validated['connection_id']);
                $this->authorize('create', [Meeting::class, $subject, $connection]);

                return MeetingScheduler::schedule($user, $project, $subject, $connection, $validated)[0];
            });
        $meeting->setRelation('subject', $subject)->load(['creator:id,name', 'connection', 'notesPage']);

        if ($replayed) {
            return (new MeetingResource($this->row($meeting, $user, $project)))->response();
        }

        return (new MeetingResource($this->row($meeting, $user, $project)))->response()->setStatusCode(201);
    }

    /**
     * Remove a meeting from its issue / doc.
     *
     * The calendar event stays; `cancel_event=1` also deletes it (the provider tells the attendees) — only the
     * organiser, on their own active calendar (`can.cancel`, else 403). Unlinking needs `can.remove` (the
     * organiser or someone who can edit the issue / doc).
     *
     * @throws NotFoundHttpException the meeting doesn't exist or the user can't open its issue / doc (404)
     */
    public function destroy(Request $request, string $meeting): Response
    {
        $row = $this->visibleMeeting($request, $meeting);
        $cancel = $request->boolean('cancel_event');
        $this->authorize($cancel ? 'cancel' : 'delete', $row);

        MeetingScheduler::remove($row, $cancel);

        return response()->noContent();
    }

    /**
     * Open the meeting's notes doc, creating it the first time.
     *
     * "Notes — <title> <date>" in the issue's / doc's project (under the doc for a doc's meeting), published,
     * with the meeting's details and Agenda / Notes / Action items headings. 201 when this call created it, 200
     * when it existed. Creating needs the right to create docs in the project (403); an existing notes doc that
     * is still its author's draft → 403.
     *
     * @throws NotFoundHttpException the meeting doesn't exist or the user can't open its issue / doc (404)
     */
    public function notes(Request $request, string $meeting): JsonResponse
    {
        $row = $this->visibleMeeting($request, $meeting);
        $subject = $row->subject;
        $project = ($subject instanceof Item ? $subject->board?->project : $subject->project)?->loadMissing('workspace');
        abort_if($project === null, 404);

        [$page, $created] = MeetingScheduler::notesPage($row, $request->user(), $project);
        $resource = new MeetingNotesResource(['page' => $page, 'url' => route('pages.show', [$project->workspace->slug, $project->slug, $page->uuid])]);
        if (! $created) {
            return $resource->response();
        }

        return $resource->response()->setStatusCode(201);
    }

    private function visibleMeeting(Request $request, string $id): Meeting
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $row = Meeting::query()->with(['subject', 'connection'])->find((int) $id);
        abort_unless($row instanceof Meeting && $row->subject !== null && $request->user()->can('view', $row), 404);

        return $row;
    }

    /**
     * @param  array{can_update_subject: bool, can_create_pages: bool, visible_notes: list<int>}|null  $context
     * @return array<string, mixed>
     */
    private function row(Meeting $meeting, User $user, Project $project, ?array $context = null): array
    {
        $subject = $meeting->subject;

        return MeetingScheduler::present($meeting, $user, $project->loadMissing('workspace'), $context) + [
            'subject' => $subject instanceof Item
                ? ['type' => 'item', 'id' => (string) $subject->id]
                : ['type' => 'page', 'id' => (string) $subject->uuid],
        ];
    }
}
