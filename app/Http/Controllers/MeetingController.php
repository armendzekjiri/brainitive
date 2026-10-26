<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ProjectMember;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Mcp\PageContentConverter;
use App\Support\Meetings\MeetingCalendars;
use App\Support\References\ContentReferences;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(Meeting::SUBJECTS))],
            'subject_id' => ['required', 'string', 'max:64'],
        ]);
        $subject = $this->subject($project, $validated['subject_type'], $validated['subject_id']);
        $this->authorize('view', $subject);
        $user = $request->user();

        $meetings = Meeting::query()
            ->where('project_id', $project->id)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->with(['creator:id,name', 'connection', 'notesPage'])
            ->orderBy('starts_at')
            ->limit(200)
            ->get();

        return response()->json([
            'meetings' => $meetings->map(fn (Meeting $m) => $this->present($m->setRelation('subject', $subject), $user, $workspace, $project))->values(),
            'can_schedule' => $user->can('update', $subject),
        ]);
    }

    /** Create the calendar event (with its video call) and link it to the issue / doc. */
    public function store(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(Meeting::SUBJECTS))],
            'subject_id' => ['required', 'string', 'max:64'],
            'connection_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:5', 'max:720'],
            'timezone' => ['required', 'string', 'timezone:all_with_bc'],
            'conference' => ['required', Rule::in(['google_meet', 'teams', 'none'])],
            'member_ids' => ['nullable', 'array', 'max:'.Meeting::MAX_ATTENDEES],
            'member_ids.*' => ['integer', 'distinct', new ProjectMember($project)],
            'emails' => ['nullable', 'array', 'max:'.Meeting::MAX_ATTENDEES],
            'emails.*' => ['string', 'max:254', 'email:rfc'],
        ]);

        $subject = $this->subject($project, $validated['subject_type'], $validated['subject_id']);
        $connection = CalendarConnection::query()->where('user_id', $user->id)->whereKey((int) $validated['connection_id'])->first();
        if (! $connection) {
            throw ValidationException::withMessages(['connection_id' => 'Pick one of your connected calendars.']);
        }
        $this->authorize('create', [Meeting::class, $subject, $connection]);
        $provider = CalendarProviders::writable($connection);

        $conference = $validated['conference'] === 'none' ? null : $validated['conference'];
        if ($conference !== null && ! in_array($conference, MeetingCalendars::conferenceProviders($connection), true)) {
            throw ValidationException::withMessages(['conference' => $conference === 'teams'
                ? 'Teams isn’t available for this account. Pick “No video call” or another calendar.'
                : 'Google Meet isn’t available for this calendar. Pick “No video call” or another calendar.']);
        }
        $attendees = $this->attendees($user, $connection, $validated);

        $timezone = $validated['timezone'];
        $start = Carbon::parse($validated['date'].' '.$validated['start_time'], $timezone);
        $end = $start->copy()->addMinutes((int) $validated['duration']);

        $event = $provider->createEvent($connection, [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_date' => $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_date' => $end->toDateString(),
            'end_time' => $end->format('H:i'),
            'timezone' => $timezone,
            'conference' => $conference,
            'attendees' => array_map(fn (array $a) => ['email' => $a['email'], 'name' => $a['name']], $attendees),
        ]);

        $joinUrl = NormalizedEvent::conferenceUrl($event['conference_url'] ?? null);
        $meeting = Meeting::create([
            'project_id' => $project->id,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'calendar_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'external_event_id' => (string) $event['id'],
            'conference_provider' => $joinUrl !== null ? ($event['conference_provider'] ?? $conference ?? 'other') : $conference,
            'join_url' => $joinUrl,
            'event_url' => $event['external_url'] ?? null,
            'title' => $validated['title'],
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $end->copy()->utc(),
            'timezone' => $timezone,
            'attendees' => $attendees,
            'created_by' => $user->id,
        ]);

        ActivityLog::log($subject, 'meeting_scheduled', null, [
            'title' => $meeting->title,
            'conference' => $meeting->conferenceLabel(),
            'when' => $meeting->whenLabel(),
        ]);

        return response()->json([
            'meeting' => $this->present($meeting->setRelation('subject', $subject)->load(['creator:id,name', 'connection']), $user, $workspace, $project),
            'warning' => $conference !== null && $joinUrl === null
                ? 'The calendar hasn’t returned the meeting link yet. Open the event in your calendar to find it.'
                : null,
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

        if ($cancel) {
            $this->authorize('cancel', $meeting);
            CalendarProviders::writable($meeting->connection)->deleteEvent($meeting->connection, $meeting->external_event_id);
        } else {
            $this->authorize('delete', $meeting);
        }

        $subject = $meeting->subject;
        $meeting->delete();
        ActivityLog::log($subject, $cancel ? 'meeting_cancelled' : 'meeting_removed', null, [
            'title' => $meeting->title,
            'conference' => $meeting->conferenceLabel(),
            'when' => $meeting->whenLabel(),
        ]);

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

        return DB::transaction(function () use ($meeting, $user, $workspace, $project) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->meeting_notes_page_id ? Page::find($locked->meeting_notes_page_id) : null;
            if ($existing && $user->can('view', $existing)) {
                return response()->json(['url' => route('pages.show', [$workspace->slug, $project->slug, $existing->uuid]), 'created' => false]);
            }
            $this->authorize('create', [Page::class, $project]);

            $subject = $meeting->subject;
            $page = $project->allPages()->create([
                'name' => mb_substr('Notes — '.$locked->title.' '.$locked->starts_at->copy()->setTimezone($locked->timezone)->format('M j, Y'), 0, 255),
                'type' => 'page',
                'parent_id' => $subject instanceof Page && ! $subject->isFolder() ? $subject->id : null,
                'icon' => '📝',
                'created_by' => $user->id,
                'content' => PageContentConverter::htmlToPageContent($this->notesHtml($locked, $subject, $workspace, $project)),
            ]);
            $page->publish($user);
            ContentReferences::link($project, $user, $subject, $page);
            $locked->update(['meeting_notes_page_id' => $page->id]);

            return response()->json(['url' => route('pages.show', [$workspace->slug, $project->slug, $page->uuid]), 'created' => true], 201);
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** The issue / doc of this project ('item' = numeric id, 'page' = uuid), else 404. */
    private function subject(Project $project, string $type, string $id): Item|Page
    {
        $subject = match ($type) {
            'item' => ctype_digit($id) ? Item::query()->where('project_id', $project->id)->whereHas('board')->find((int) $id) : null,
            'page' => Page::query()->where('project_id', $project->id)->where('uuid', $id)->first(),
            default => null,
        };
        abort_if($subject === null, 404);

        return $subject;
    }

    private function meeting(Project $project, string $id): Meeting
    {
        abort_unless(ctype_digit($id), 404);

        return Meeting::query()->where('project_id', $project->id)->with(['subject', 'connection'])->findOrFail((int) $id);
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
     * Members (by id, checked by ProjectMember) + typed addresses, de-duplicated, without the
     * organiser, at most MAX_ATTENDEES.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array{email: string, name: string|null, user_id: int|null}>
     */
    private function attendees(User $user, CalendarConnection $connection, array $validated): array
    {
        $skip = array_map('mb_strtolower', array_filter([$user->email, $connection->account_email]));
        $out = [];
        $members = User::query()->whereIn('id', array_map('intval', $validated['member_ids'] ?? []))->get(['id', 'name', 'email']);
        foreach ($members as $member) {
            $out[mb_strtolower($member->email)] ??= ['email' => $member->email, 'name' => $member->name, 'user_id' => $member->id];
        }
        foreach ($validated['emails'] ?? [] as $email) {
            $email = trim((string) $email);
            $out[mb_strtolower($email)] ??= ['email' => $email, 'name' => null, 'user_id' => null];
        }
        foreach ($skip as $own) {
            unset($out[$own]);
        }
        if (count($out) > Meeting::MAX_ATTENDEES) {
            throw ValidationException::withMessages(['emails' => 'Invite at most '.Meeting::MAX_ATTENDEES.' people.']);
        }

        return array_values($out);
    }

    /** @return array<string, mixed> */
    private function present(Meeting $meeting, User $viewer, Workspace $workspace, Project $project): array
    {
        $isOrganiser = $meeting->created_by === $viewer->id;
        $notes = $meeting->notesPage;
        $connection = $meeting->connection;

        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'starts_at' => $meeting->starts_at->toIso8601String(),
            'ends_at' => $meeting->ends_at->toIso8601String(),
            'timezone' => $meeting->timezone,
            'conference_provider' => $meeting->conference_provider,
            'conference_label' => $meeting->conferenceLabel(),
            'join_url' => NormalizedEvent::conferenceUrl($meeting->join_url),
            'event_url' => NormalizedEvent::conferenceUrl($meeting->event_url),
            'provider' => $meeting->provider,
            'provider_label' => CalendarProviders::has($meeting->provider) ? CalendarProviders::make($meeting->provider)->label() : $meeting->provider,
            // Addresses only for the organiser (they typed them); everyone else sees names.
            'attendees' => array_map(fn (array $a) => [
                'name' => $a['name'] ?? ($isOrganiser ? null : 'Guest'),
                'email' => $isOrganiser ? ($a['email'] ?? null) : null,
            ], (array) $meeting->attendees),
            'organizer' => $meeting->creator ? ['id' => $meeting->creator->id, 'name' => $meeting->creator->name] : null,
            'notes_page' => $notes && $viewer->can('view', $notes)
                ? ['uuid' => $notes->uuid, 'name' => $notes->name, 'url' => route('pages.show', [$workspace->slug, $project->slug, $notes->uuid])]
                : null,
            'can_remove' => $viewer->can('delete', $meeting),
            'can_cancel' => $viewer->can('cancel', $meeting) && (bool) $connection?->is_active,
            'can_create_notes' => $viewer->can('create', [Page::class, $project]),
        ];
    }

    private function notesHtml(Meeting $meeting, ?Model $subject, Workspace $workspace, Project $project): string
    {
        $lines = ['<p><strong>Meeting:</strong> '.e($meeting->title).'</p>'];
        $lines[] = '<p><strong>When:</strong> '.e($meeting->whenLabel()).' ('.$meeting->starts_at->diffInMinutes($meeting->ends_at).' min)</p>';
        if ($join = NormalizedEvent::conferenceUrl($meeting->join_url)) {
            $lines[] = '<p><strong>Join:</strong> <a href="'.e($join).'">'.e($meeting->conferenceLabel() ?? 'Video call').'</a></p>';
        }
        if ($subject instanceof Item) {
            $url = route('boards.items.show', [$workspace->slug, $project->slug, $subject->board->slug, $subject->id]);
            $lines[] = '<p><strong>Issue:</strong> <a href="'.e($url).'">'.e(trim($subject->key.' '.$subject->title)).'</a></p>';
        } elseif ($subject instanceof Page) {
            $url = route('pages.show', [$workspace->slug, $project->slug, $subject->uuid]);
            $lines[] = '<p><strong>Doc:</strong> <a href="'.e($url).'">'.e($subject->name).'</a></p>';
        }
        $names = array_values(array_filter(array_map(fn (array $a) => $a['name'] ?? $a['email'] ?? null, (array) $meeting->attendees)));
        if ($names !== []) {
            $lines[] = '<p><strong>Attendees:</strong> '.e(implode(', ', $names)).'</p>';
        }

        return implode('', $lines).'<h2>Agenda</h2><p><br></p><h2>Notes</h2><p><br></p><h2>Action items</h2><p><br></p>';
    }
}
