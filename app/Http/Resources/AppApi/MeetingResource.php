<?php

namespace App\Http\Resources\AppApi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An online meeting of an issue / doc (MeetingScheduler::present, the web's meeting row) + its subject.
 * Attendee addresses only for the organiser; everyone else sees names ("Guest" for typed addresses).
 *
 * @property-read array{id: int, title: string, starts_at: string, ends_at: string, timezone: string, conference_provider: string|null, conference_label: string|null, join_url: string|null, event_url: string|null, provider: string, provider_label: string, attendees: list<array{name: string|null, email: string|null}>, organizer: array{id: int, name: string}|null, notes_page: array{uuid: string, name: string, url: string}|null, can_remove: bool, can_cancel: bool, can_create_notes: bool, subject: array{type: string, id: string}} $resource
 */
class MeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'id' => (int) $m['id'],
            'title' => (string) $m['title'],
            // the issue / doc: {type: item|page, id: issue id | doc uuid}
            'subject' => ['type' => (string) $m['subject']['type'], 'id' => (string) $m['subject']['id']],
            'starts_at' => Carbon::parse($m['starts_at'])->toIso8601ZuluString(),
            'ends_at' => Carbon::parse($m['ends_at'])->toIso8601ZuluString(),
            // the zone it was scheduled in (for showing the organiser's wall clock)
            'timezone' => (string) $m['timezone'],
            // google_meet | teams | other | null (no video call). Set while join_url is null = the calendar hasn't returned the link yet: open event_url
            'conference_provider' => $m['conference_provider'],
            'conference_label' => $m['conference_label'],
            // https join link of the call
            'join_url' => $m['join_url'],
            // the event in the organiser's calendar app
            'event_url' => $m['event_url'],
            'provider' => (string) $m['provider'],
            'provider_label' => (string) $m['provider_label'],
            // [{name, email}]: email only for the organiser
            'attendees' => array_map(fn (array $a) => ['name' => $a['name'], 'email' => $a['email']], $m['attendees']),
            'organizer' => $m['organizer'] === null ? null : ['id' => (int) $m['organizer']['id'], 'name' => (string) $m['organizer']['name']],
            // the notes doc (created on the web), only when the user may open it
            'notes_page' => $m['notes_page'] === null ? null : [
                'uuid' => (string) $m['notes_page']['uuid'],
                'name' => (string) $m['notes_page']['name'],
                'url' => (string) $m['notes_page']['url'],
            ],
            'can' => [
                // DELETE /meetings/{id}: unlink from the issue / doc (the event stays)
                'remove' => (bool) $m['can_remove'],
                // DELETE /meetings/{id}?cancel_event=1: also cancel the calendar event (the organiser, on their active calendar)
                'cancel' => (bool) $m['can_cancel'],
            ],
        ];
    }
}
