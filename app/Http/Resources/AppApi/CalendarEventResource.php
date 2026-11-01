<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One calendar entry (App\Support\Calendar\AgendaEvent::from): a connected-calendar event, a Google task, a
 * booking, or a dated Brainitive issue / task.
 *
 * @property-read array{id: string, type: string, work_id: string|null, title: string, all_day: bool, start: string, end: string, location: string|null, conference_url: string|null, conference_provider: string|null, color: string|null, calendar: array{connection_id: int, name: string|null, provider: string, provider_label: string}|null, project: array{id: int, name: string, key: string|null}|null, url: string|null, external_url: string|null, done: bool, read_only: bool} $resource
 */
class CalendarEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            // unique per occurrence: issue:<id>, task:<id>[:<date>], event:<connection>:<id>, google_task:<connection>:<id>, booking:<uuid>
            'id' => (string) $event['id'],
            // issue | task | event | google_task | booking
            'type' => (string) $event['type'],
            // issues / tasks: the id for PATCH /me/work/{id}; null otherwise
            'work_id' => $this->text($event['work_id']),
            'title' => (string) $event['title'],
            'all_day' => (bool) $event['all_day'],
            // timed: ISO-8601 UTC; all-day: Y-m-d
            'start' => (string) $event['start'],
            // timed: ISO-8601 UTC; all-day: Y-m-d, inclusive (the last day)
            'end' => (string) $event['end'],
            'location' => $this->text($event['location']),
            // https join link of the event's video call (Google Meet, Teams …)
            'conference_url' => $this->text($event['conference_url']),
            // google_meet | teams | other
            'conference_provider' => $this->text($event['conference_provider']),
            // the calendar's colour (events), the project's colour (issues / tasks)
            'color' => $this->text($event['color']),
            // connected calendars / Google Tasks only
            'calendar' => $event['calendar'] === null ? null : [
                'connection_id' => (int) $event['calendar']['connection_id'],
                'name' => $this->text($event['calendar']['name']),
                'provider' => (string) $event['calendar']['provider'],
                'provider_label' => (string) $event['calendar']['provider_label'],
            ],
            // issues / tasks only
            'project' => $event['project'] === null ? null : [
                'id' => (int) $event['project']['id'],
                'name' => (string) $event['project']['name'],
                'key' => $this->text($event['project']['key']),
            ],
            // the web page (issues, tasks, bookings)
            'url' => $this->text($event['url']),
            // the event in its own calendar app, when the provider gives one
            'external_url' => $this->text($event['external_url']),
            'done' => (bool) $event['done'],
            // a read-only calendar (an ICS subscription) or a booking
            'read_only' => (bool) $event['read_only'],
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
