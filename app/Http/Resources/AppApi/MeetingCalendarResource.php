<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A calendar the user can schedule a video call in (MeetingCalendars::for): their own active, writable
 * calendars whose provider does conferencing, with the kinds it allows.
 *
 * @property-read array{id: int, provider: string, provider_label: string, name: string, account_email: string, color: string|null, conference_providers: list<string>} $resource
 */
class MeetingCalendarResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            // the connection id (connection_id of POST /calendar/events and POST /meetings)
            'id' => (int) $c['id'],
            'provider' => (string) $c['provider'],
            'provider_label' => (string) $c['provider_label'],
            'name' => (string) $c['name'],
            'account_email' => (string) $c['account_email'],
            'color' => is_string($c['color']) ? $c['color'] : null,
            // google_meet and / or teams; empty = the calendar can't add a call right now (events without one still work)
            'conference_providers' => array_values(array_map('strval', $c['conference_providers'])),
        ];
    }
}
