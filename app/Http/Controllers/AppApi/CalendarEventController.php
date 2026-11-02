<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\StoreCalendarEventRequest;
use App\Http\Requests\AppApi\UpdateCalendarEventRequest;
use App\Http\Resources\AppApi\CalendarEventDetailResource;
use App\Http\Resources\AppApi\MeetingCalendarResource;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\AgendaEvent;
use App\Support\Calendar\CalendarEvents;
use App\Support\Calendar\CalendarProviders;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use App\Support\Meetings\MeetingCalendars;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — events of the user's connected calendars, the write part (BRAI-418): what the web calendar's
 * quick create and event dialog do (CalendarConnectionController), through the same provider layer
 * (CalendarProviders::writable). Calendars are personal: only the user's own connections, every provider.
 * Provider errors (read-only calendar, refused, gone) are a 422 with `errors.calendar`, as on the web.
 * Connecting / removing calendars: web only.
 */
#[Group('Calendar', weight: 140)]
class CalendarEventController extends Controller
{
    /**
     * Create an event in one of the user's writable calendars.
     *
     * Without `time` it's an all-day event (`end_date` inclusive); a timed one without `end_time` lasts 30
     * minutes. `conference` adds a Google Meet / Teams call when the calendar offers it (422 `calendar`
     * otherwise); `attendees` are invited by the provider. Answers 201 with the event as the provider saved
     * it, or 200 with the same event on a replayed `request_id`. A calendar that isn't the user's or that they
     * switched off → 422 `connection_id`; a read-only one (ICS link) → 422 `calendar`.
     */
    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $connection = CalendarConnection::query()->where('user_id', $user->id)->where('kind', 'calendar')->where('is_active', true)->whereKey((int) $validated['connection_id'])->first();
        if (! $connection || ! CalendarProviders::has($connection->provider)) {
            throw ValidationException::withMessages(['connection_id' => 'Pick one of your connected calendars.']);
        }

        $write = function () use ($connection, $validated, $user): array {
            $provider = CalendarProviders::writable($connection);
            $conference = $validated['conference'] ?? null;
            if ($conference !== null && ! in_array($conference, MeetingCalendars::conferenceProviders($connection), true)) {
                throw ValidationException::withMessages(['calendar' => $conference === 'teams'
                    ? 'Teams isn’t available for this account.'
                    : 'Google Meet isn’t available for this calendar.']);
            }

            $event = $provider->createEvent($connection, [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'location' => $validated['location'] ?? null,
                'start_date' => $validated['date'],
                'start_time' => $validated['time'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
                'timezone' => $validated['timezone'] ?? $user->localTimezone(),
            ] + array_filter([
                'conference' => $conference,
                'attendees' => array_map(fn (string $email) => ['email' => trim($email), 'name' => null], $validated['attendees'] ?? []),
            ]));

            return ['connection_id' => $connection->id, 'event' => $event];
        };

        $requestId = $validated['request_id'] ?? null;
        if (! is_string($requestId)) {
            $created = $write();

            return CalendarEventDetailResource::make($this->event($connection, $created['event'], $user))->response()->setStatusCode(201);
        }

        try {
            $run = IdempotentRequest::run($user, 'app-api:calendar.events.store', $requestId, collect($validated)->except('request_id')->all(), $write);
        } catch (RequestIdConflict $e) {
            abort(409, $e->getMessage());
        }
        $resource = CalendarEventDetailResource::make($this->event($connection, (array) $run['result']['event'], $user));
        if ($run['replayed']) {
            return $resource->response();
        }

        return $resource->response()->setStatusCode(201);
    }

    /**
     * One event of a connected calendar, with its description.
     *
     * `id` is the event's `id` from `GET /calendar/events` (`event:<connection>:<event id>`, URL-encoded).
     *
     * @throws NotFoundHttpException not one of the user's calendars, or the provider doesn't know the event (404)
     */
    public function show(Request $request, string $event): CalendarEventDetailResource
    {
        $user = $request->user();
        [$connection, $eventId] = $this->resolve($user, $event);
        $found = CalendarProviders::for($connection)->getEvent($connection, $eventId, $user->localTimezone());
        abort_if($found === null, 404);

        return CalendarEventDetailResource::make($this->event($connection, $found, $user));
    }

    /**
     * Change an event of one of the user's writable calendars.
     *
     * Only the sent fields change. Moving it: send `date` (+ `time`, `end_date`, `end_time`); without `time`
     * it becomes all-day. A read-only calendar → 422 `calendar`.
     *
     * @throws NotFoundHttpException not one of the user's calendars (404)
     */
    public function update(UpdateCalendarEventRequest $request, string $event): CalendarEventDetailResource
    {
        $user = $request->user();
        [$connection, $eventId] = $this->resolve($user, $event);
        $validated = $request->validated();

        $data = [];
        foreach (['title' => 'title', 'description' => 'description', 'location' => 'location', 'date' => 'start_date', 'time' => 'start_time', 'end_date' => 'end_date', 'end_time' => 'end_time'] as $in => $out) {
            if (array_key_exists($in, $validated)) {
                $data[$out] = $validated[$in];
            }
        }
        if (array_key_exists('date', $validated)) {
            // a move replaces all of the times (EventInput: a missing start_time = all-day)
            $data += ['start_time' => null, 'end_date' => null, 'end_time' => null];
        }
        $data['timezone'] = $validated['timezone'] ?? $user->localTimezone();

        $updated = CalendarProviders::writable($connection)->updateEvent($connection, $eventId, $data);

        return CalendarEventDetailResource::make($this->event($connection, $updated, $user));
    }

    /**
     * Delete an event of one of the user's writable calendars.
     *
     * The provider tells its attendees. A read-only calendar → 422 `calendar`.
     *
     * @throws NotFoundHttpException not one of the user's calendars (404)
     */
    public function destroy(Request $request, string $event): Response
    {
        [$connection, $eventId] = $this->resolve($request->user(), $event);
        CalendarProviders::writable($connection)->deleteEvent($connection, $eventId);

        return response()->noContent();
    }

    /**
     * The calendars the user can add a video call to (Google Meet / Teams), with the kinds each allows.
     *
     * For `conference` of `POST /calendar/events` and `POST /meetings`. Not paginated.
     */
    public function meetingCalendars(Request $request): AnonymousResourceCollection
    {
        return MeetingCalendarResource::collection(MeetingCalendars::for($request->user()));
    }

    /**
     * `event:<connection id>:<provider event id>` → the user's own, switched-on calendar connection + the event id,
     * else 404 (a calendar turned off is off for the apps too — Codex review).
     *
     * @return array{0: CalendarConnection, 1: string}
     */
    private function resolve(User $user, string $id): array
    {
        abort_unless(preg_match('/^event:([1-9][0-9]{0,17}):(.{1,3000})$/s', $id, $m) === 1, 404);
        abort_if($m[2] === '.' || $m[2] === '..', 404);
        $connection = CalendarConnection::query()->where('user_id', $user->id)->where('kind', 'calendar')->where('is_active', true)->whereKey((int) $m[1])->first();
        abort_unless($connection instanceof CalendarConnection && CalendarProviders::has($connection->provider), 404);

        return [$connection, $m[2]];
    }

    /**
     * A provider event (NormalizedEvent) in the API's event shape (as `GET /calendar/events` lists it).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function event(CalendarConnection $connection, array $event, User $user): array
    {
        $provider = CalendarProviders::for($connection);
        $agenda = AgendaEvent::from(CalendarEvents::mapExternalEvent($connection, $provider, $event, ! $provider->isWritable($connection)), $user->localTimezone());
        abort_if($agenda === null, 404);

        return $agenda + ['description' => $event['description'] ?? null];
    }
}
