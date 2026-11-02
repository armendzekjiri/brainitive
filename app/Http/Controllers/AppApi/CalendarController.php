<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\CalendarEventsRequest;
use App\Http\Resources\AppApi\CalendarConnectionResource;
use App\Http\Resources\AppApi\CalendarErrorResource;
use App\Http\Resources\AppApi\CalendarEventResource;
use App\Support\Calendar\AgendaEvent;
use App\Support\Calendar\CalendarEvents;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * App API v1 — calendar, read part (BRAI-418): what the web calendar shows (CalendarEvents, shared
 * with CalendarController) for a range of days, and the status of the user's connected calendars.
 * Creating / changing events, meetings and the ICS feed link come later; connecting providers is
 * web only.
 */
#[Group('Calendar', weight: 140)]
class CalendarController extends Controller
{
    /**
     * The user's calendar for a range of days.
     *
     * The events of their connected calendars (any provider) and Google Tasks, their bookings and the
     * dated issues / list tasks of the projects they can open — the web calendar's sources — for the
     * user's local days `from` … `to` (inclusive, Y-m-d, at most 62 days; default today + 7), sorted by
     * start. Not paginated. A calendar that can't be loaded is left out and listed in `errors` (its
     * `sync_error` also shows in `GET /calendar/connections`).
     */
    public function events(CalendarEventsRequest $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->from();
        $to = $request->to();
        $agenda = AgendaEvent::forDays($user, $from, $to, $request->sources(), $request->boolean('me_only'), $request->boolean('show_completed', true));

        return response()->json([
            'data' => CalendarEventResource::collection($agenda['events']),
            'from' => $from,
            'to' => $to,
            // the zone of `from` / `to` (the user's effective time zone)
            'timezone' => $user->localTimezone(),
            // calendars that failed this time: [{connection_id, message}]
            'errors' => CalendarErrorResource::collection($agenda['errors']),
        ]);
    }

    /** The user's connected calendars and Google Tasks lists: name, colour, provider, status. */
    public function connections(Request $request): AnonymousResourceCollection
    {
        return CalendarConnectionResource::collection(
            CalendarEvents::connections($request->user())->map(fn ($connection) => CalendarEvents::connectionRow($connection))->values(),
        );
    }
}
