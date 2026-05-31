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

#[Description('Create an event on one of the user\'s Google calendars — the main tool for planning their week. Provide a title and date (YYYY-MM-DD). Add a time (HH:MM, 24h) for a timed event, or omit it for an all-day event. Pass the user\'s IANA timezone (e.g. Europe/Zurich) so the time lands correctly.')]
class CreateCalendarEventTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleCalendarService $calendar): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'nullable|integer',
            'title' => 'required|string|max:500',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'end_date' => 'nullable|date_format:Y-m-d',
            'end_time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string|max:5000',
            'timezone' => 'nullable|string|max:64',
        ]);

        $user = $request->user();

        try {
            $connection = $this->resolveTargetCalendar($user, $validated['connection_id'] ?? null);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $event = $calendar->createEvent($connection, $validated);

        if (! $event) {
            return Response::error('Google rejected the event (the connection may need reauthorising in Brainitive).');
        }

        return Response::structured([
            'created' => true,
            'event' => CalendarFormatter::event($event, $connection),
        ]);
    }

    /**
     * Default to the only connected calendar when there is exactly one;
     * otherwise require an explicit connection_id.
     *
     * @throws AuthorizationException
     */
    private function resolveTargetCalendar($user, ?int $connectionId): \App\Models\CalendarConnection
    {
        if ($connectionId !== null) {
            return $this->connectionForUser($user, $connectionId, 'calendar');
        }

        $calendars = $this->connectionsFor($user, 'calendar');
        if ($calendars->count() === 1) {
            return $calendars->first();
        }
        if ($calendars->isEmpty()) {
            throw new AuthorizationException('No connected Google calendars. Ask the user to connect one in Brainitive.');
        }

        throw new AuthorizationException('Multiple calendars are connected — specify connection_id (see list_calendar_connections).');
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'event' => \App\Support\Mcp\CalendarFormatter::eventSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('Target calendar id (from list_calendar_connections). Optional if only one calendar is connected.'),
            'title' => $schema->string()->description('Event title.')->required(),
            'date' => $schema->string()->description('Start date YYYY-MM-DD.')->required(),
            'time' => $schema->string()->description('Start time HH:MM (24h). Omit for an all-day event.'),
            'end_date' => $schema->string()->description('End date YYYY-MM-DD. Defaults to start date.'),
            'end_time' => $schema->string()->description('End time HH:MM (24h). Defaults to 30 min after start.'),
            'description' => $schema->string()->description('Optional event description / notes.'),
            'timezone' => $schema->string()->description('IANA timezone for the times, e.g. Europe/Zurich. Defaults to the app timezone.'),
        ];
    }
}
