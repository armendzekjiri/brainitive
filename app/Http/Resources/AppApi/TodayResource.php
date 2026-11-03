<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use App\Models\TaskListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/me/today: the TV wallboard in one call — open work due today or overdue, today's My Day
 * and today's calendar events.
 *
 * @property-read array{date: string, timezone: string, work: iterable<Item|TaskListItem>, my_day: iterable<Item|TaskListItem>, events: list<array<string, mixed>>, errors: list<array{connection_id: int, message: string}>} $resource
 */
class TodayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // the user's local date (Y-m-d) and zone
            'date' => (string) $this->resource['date'],
            'timezone' => (string) $this->resource['timezone'],
            // open work assigned to the user, overdue first, then due today (at most 100), as GET /me/work?due=overdue,today
            'work' => WorkItemResource::collection($this->resource['work']),
            // today's plan in the user's order, as GET /me/my-day
            'my_day' => WorkItemResource::collection($this->resource['my_day']),
            // today's events of connected calendars and bookings, as GET /calendar/events?sources[]=external&sources[]=booking
            'events' => CalendarEventResource::collection($this->resource['events']),
            // calendars that failed this time
            'errors' => CalendarErrorResource::collection($this->resource['errors']),
        ];
    }
}
