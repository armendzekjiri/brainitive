<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Services\GoogleCalendarService;
use App\Support\Mcp\CalendarFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reschedule or edit an existing Google Calendar event (e.g. move it to a new time, rename it, change its notes). Identify it by connection_id + event_id (from list_calendar_events). To move a timed event, pass start_date + start_time (and optionally end_time); include the timezone.')]
class UpdateCalendarEventTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleCalendarService $calendar): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'required|integer',
            'event_id' => 'required|string',
            'title' => 'sometimes|string|max:500',
            'description' => 'sometimes|nullable|string|max:10000',
            'location' => 'sometimes|nullable|string|max:500',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_time' => 'sometimes|nullable|date_format:H:i',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_time' => 'sometimes|nullable|date_format:H:i',
            'timezone' => 'nullable|string|max:64',
        ]);

        try {
            $connection = $this->connectionForUser($request->user(), $validated['connection_id'], 'calendar');
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $event = $calendar->updateEvent($connection, $validated['event_id'], $validated);

        if (! $event) {
            return Response::error('Could not update the event (it may not exist, or the connection needs reauthorising).');
        }

        return Response::structured([
            'updated' => true,
            'event' => CalendarFormatter::event($event, $connection),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('The calendar id (from list_calendar_connections).')->required(),
            'event_id' => $schema->string()->description('The event id (from list_calendar_events).')->required(),
            'title' => $schema->string()->description('New title.'),
            'description' => $schema->string()->description('New description.'),
            'location' => $schema->string()->description('New location.'),
            'start_date' => $schema->string()->description('New start date YYYY-MM-DD (required to move the event).'),
            'start_time' => $schema->string()->description('New start time HH:MM (24h). Omit to make it all-day.'),
            'end_date' => $schema->string()->description('New end date YYYY-MM-DD.'),
            'end_time' => $schema->string()->description('New end time HH:MM (24h).'),
            'timezone' => $schema->string()->description('IANA timezone for the times, e.g. Europe/Zurich.'),
        ];
    }
}
