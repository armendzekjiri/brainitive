<?php

namespace App\Support\Meetings;

use App\Models\ActivityLog;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Rules\ProjectMember;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Entitlements\LimitGate;
use App\Support\Mcp\PageContentConverter;
use App\Support\References\ContentReferences;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Online meetings from issues and docs (BRAI-147), shared by the web (MeetingController) and the App API
 * (BRAI-418): the input rules, scheduling the calendar event (with its Google Meet / Teams link) in the
 * organiser's own calendar, linking it to the issue / doc, removing it, and the meeting row the UIs show.
 * Callers authorize (MeetingPolicy) before calling schedule() / remove().
 */
final class MeetingScheduler
{
    /**
     * The scheduling input (the web's "Schedule meeting" dialog).
     *
     * @return array<string, mixed>
     */
    public static function rules(?Project $project): array
    {
        return [
            'connection_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:5', 'max:720'],
            'timezone' => ['required', 'string', 'timezone:all_with_bc'],
            'conference' => ['required', Rule::in(['google_meet', 'teams', 'none'])],
            'member_ids' => ['nullable', 'array', 'max:'.Meeting::MAX_ATTENDEES],
            'member_ids.*' => array_values(array_filter(['integer', 'distinct', $project ? new ProjectMember($project) : null])),
            'emails' => ['nullable', 'array', 'max:'.Meeting::MAX_ATTENDEES],
            'emails.*' => ['string', 'max:254', 'email:rfc'],
        ];
    }

    /** The issue / doc of this project ('item' = numeric id, 'page' = uuid), or null. */
    public static function subject(Project $project, string $type, string $id): Item|Page|null
    {
        return match ($type) {
            'item' => ctype_digit($id) ? Item::query()->where('project_id', $project->id)->whereHas('board')->find((int) $id) : null,
            'page' => Page::query()->where('project_id', $project->id)->where('uuid', $id)->first(),
            default => null,
        };
    }

    /** One of the user's own connected calendars, else 422 `connection_id` (never someone else's). */
    public static function connection(User $user, mixed $id): CalendarConnection
    {
        $connection = CalendarConnection::query()->where('user_id', $user->id)->whereKey((int) $id)->first();
        if (! $connection) {
            throw ValidationException::withMessages(['connection_id' => 'Pick one of your connected calendars.']);
        }

        return $connection;
    }

    /**
     * Create the calendar event (with its video call) and link it to the issue / doc. The caller checked
     * MeetingPolicy::create for $subject and $connection.
     *
     * @param  array<string, mixed>  $validated  rules()
     * @return array{0: Meeting, 1: string|null} the meeting and a warning when the call link isn't there yet
     */
    public static function schedule(User $user, Project $project, Item|Page $subject, CalendarConnection $connection, array $validated): array
    {
        $provider = CalendarProviders::writable($connection);

        $conference = $validated['conference'] === 'none' ? null : $validated['conference'];
        if ($conference !== null && ! in_array($conference, MeetingCalendars::conferenceProviders($connection), true)) {
            throw ValidationException::withMessages(['conference' => $conference === 'teams'
                ? 'Teams isn’t available for this account. Pick “No video call” or another calendar.'
                : 'Google Meet isn’t available for this calendar. Pick “No video call” or another calendar.']);
        }
        $attendees = self::attendees($user, $connection, $validated);

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
        app(LimitGate::class)->ensureContentRoom($project->workspace); // content limit (Codex web review B)
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

        $warning = $conference !== null && $joinUrl === null
            ? 'The calendar hasn’t returned the meeting link yet. Open the event in your calendar to find it.'
            : null;

        return [$meeting->setRelation('subject', $subject), $warning];
    }

    /**
     * Remove a meeting from its issue / doc; $cancel also deletes the calendar event (the provider tells the
     * attendees). The caller checked MeetingPolicy::cancel / delete.
     */
    public static function remove(Meeting $meeting, bool $cancel): void
    {
        if ($cancel) {
            // the event this meeting created itself (never a series): no series check needed
            CalendarProviders::writable($meeting->connection)->deleteEvent($meeting->connection, $meeting->external_event_id, wholeSeries: true);
        }

        $subject = $meeting->subject;
        $meeting->delete();
        ActivityLog::log($subject, $cancel ? 'meeting_cancelled' : 'meeting_removed', null, [
            'title' => $meeting->title,
            'conference' => $meeting->conferenceLabel(),
            'when' => $meeting->whenLabel(),
        ]);
    }

    /**
     * One meeting as the UIs show it to $viewer. $context = rights shared by a list of one subject's meetings
     * (the caller already checked view), so they're asked once per list, not per row. Attendee addresses
     * only for the organiser (they typed them); everyone else sees names.
     *
     * @param  array{can_update_subject: bool, can_create_pages: bool, visible_notes: list<int>}|null  $context
     * @return array{id: int, title: string, starts_at: string, ends_at: string, timezone: string, conference_provider: string|null, conference_label: string|null, join_url: string|null, event_url: string|null, provider: string, provider_label: string, attendees: list<array{name: string|null, email: string|null}>, organizer: array{id: int, name: string}|null, notes_page: array{uuid: string, name: string, url: string}|null, can_remove: bool, can_cancel: bool, can_create_notes: bool}
     */
    public static function present(Meeting $meeting, User $viewer, Project $project, ?array $context = null): array
    {
        $isOrganiser = $meeting->created_by === $viewer->id;
        $notes = $meeting->notesPage;
        $connection = $meeting->connection;
        $context ??= [
            'can_update_subject' => $viewer->can('update', $meeting->subject),
            'can_create_pages' => $viewer->can('create', [Page::class, $project]),
            'visible_notes' => $notes && $viewer->can('view', $notes) ? [$notes->id] : [],
        ];
        $workspaceSlug = $project->workspace->slug;

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
            'attendees' => array_map(fn (array $a) => [
                'name' => $a['name'] ?? ($isOrganiser ? null : 'Guest'),
                'email' => $isOrganiser ? ($a['email'] ?? null) : null,
            ], array_values((array) $meeting->attendees)),
            'organizer' => $meeting->creator ? ['id' => $meeting->creator->id, 'name' => $meeting->creator->name] : null,
            'notes_page' => $notes && in_array($notes->id, $context['visible_notes'], true)
                ? ['uuid' => $notes->uuid, 'name' => $notes->name, 'url' => route('pages.show', [$workspaceSlug, $project->slug, $notes->uuid])]
                : null,
            // = MeetingPolicy::delete / cancel for a viewer who can see the subject
            'can_remove' => $isOrganiser || $context['can_update_subject'],
            'can_cancel' => $isOrganiser && $connection !== null && $connection->user_id === $viewer->id && (bool) $connection->is_active,
            'can_create_notes' => $context['can_create_pages'],
        ];
    }

    /**
     * The meeting's notes doc, created the first time: "Notes — <title> <date>" in the same project (under the
     * doc when the meeting belongs to one), published, linked to the issue / doc. Concurrent calls create one
     * doc (row lock). The caller checked MeetingPolicy::view; creating needs PagePolicy::create (403), and an
     * existing notes doc the user can't open (an unpublished draft of its author) is a 403.
     *
     * @return array{0: Page, 1: bool} the doc and whether this call created it
     */
    public static function notesPage(Meeting $meeting, User $user, Project $project): array
    {
        return DB::transaction(function () use ($meeting, $user, $project) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->meeting_notes_page_id ? Page::find($locked->meeting_notes_page_id) : null;
            if ($existing) {
                // unpublished by its author: never replace it with a second doc
                abort_unless($user->can('view', $existing), 403, 'The meeting notes are a draft of their author.');

                return [$existing, false];
            }
            Gate::forUser($user)->authorize('create', [Page::class, $project]);

            $subject = $meeting->subject;
            $page = $project->allPages()->create([
                'name' => mb_substr('Notes — '.$locked->title.' '.$locked->starts_at->copy()->setTimezone($locked->timezone)->format('M j, Y'), 0, 255),
                'type' => 'page',
                'parent_id' => $subject instanceof Page && ! $subject->isFolder() ? $subject->id : null,
                'icon' => '📝',
                'created_by' => $user->id,
                'content' => PageContentConverter::htmlToPageContent(self::notesHtml($locked, $subject, $project)),
            ]);
            $page->publish($user);
            ContentReferences::link($project, $user, $subject, $page);
            $locked->update(['meeting_notes_page_id' => $page->id]);

            return [$page, true];
        });
    }

    /**
     * Members (by id, checked by ProjectMember) + typed addresses, de-duplicated, without the organiser, at
     * most MAX_ATTENDEES.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array{email: string, name: string|null, user_id: int|null}>
     */
    private static function attendees(User $user, CalendarConnection $connection, array $validated): array
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

    private static function notesHtml(Meeting $meeting, ?Model $subject, Project $project): string
    {
        $lines = ['<p><strong>Meeting:</strong> '.e($meeting->title).'</p>'];
        $lines[] = '<p><strong>When:</strong> '.e($meeting->whenLabel()).' ('.$meeting->starts_at->diffInMinutes($meeting->ends_at).' min)</p>';
        if ($join = NormalizedEvent::conferenceUrl($meeting->join_url)) {
            $lines[] = '<p><strong>Join:</strong> <a href="'.e($join).'">'.e($meeting->conferenceLabel() ?? 'Video call').'</a></p>';
        }
        if ($subject instanceof Item) {
            $url = route('boards.items.show', [$project->workspace->slug, $project->slug, $subject->board->slug, $subject->id]);
            $lines[] = '<p><strong>Issue:</strong> <a href="'.e($url).'">'.e(trim($subject->key.' '.$subject->title)).'</a></p>';
        } elseif ($subject instanceof Page) {
            $url = route('pages.show', [$project->workspace->slug, $project->slug, $subject->uuid]);
            $lines[] = '<p><strong>Doc:</strong> <a href="'.e($url).'">'.e($subject->name).'</a></p>';
        }
        // Names of members only: the doc is readable by the whole project, guest addresses are
        // the organiser's to see (as in the meeting list).
        $attendees = (array) $meeting->attendees;
        $names = array_values(array_filter(array_map(fn (array $a) => ! empty($a['user_id']) ? ($a['name'] ?? null) : null, $attendees)));
        $guests = count($attendees) - count($names);
        if ($guests > 0) {
            $names[] = '+'.$guests.' '.($guests === 1 ? 'guest' : 'guests');
        }
        if ($names !== []) {
            $lines[] = '<p><strong>Attendees:</strong> '.e(implode(', ', $names)).'</p>';
        }

        return implode('', $lines).'<h2>Agenda</h2><p><br></p><h2>Notes</h2><p><br></p><h2>Action items</h2><p><br></p>';
    }
}
