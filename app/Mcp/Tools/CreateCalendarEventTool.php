<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Models\CalendarConnection;
use App\Support\Calendar\CalendarProviderException;
use App\Support\Calendar\CalendarProviders;
use App\Support\Mcp\CalendarFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create an event on one of the user\'s connected calendars (any writable one, from any provider) — the main tool for planning their week. Provide a title and date (YYYY-MM-DD). Add a time (HH:MM, 24h) for a timed event, or omit it for an all-day event. Pass the user\'s IANA timezone (e.g. Europe/Zurich) so the time lands correctly.')]
class CreateCalendarEventTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'nullable|integer',
            'title' => 'required|string|max:500',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'end_date' => 'nullable|date_format:Y-m-d',
            'end_time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:64',
        ]);

        $user = $request->user();

        try {
            $connection = $this->resolveTargetCalendar($user, $validated['connection_id'] ?? null);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        try {
            $event = CalendarProviders::writable($connection)->createEvent($connection, [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'location' => $validated['location'] ?? null,
                'start_date' => $validated['date'],
                'start_time' => $validated['time'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
            ]);
        } catch (CalendarProviderException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'created' => true,
            'event' => CalendarFormatter::normalized($event, $connection),
        ]);
    }

    /**
     * Default to the only writable connected calendar when there is exactly one;
     * otherwise require an explicit connection_id.
     *
     * @throws AuthorizationException
     */
    private function resolveTargetCalendar($user, ?int $connectionId): CalendarConnection
    {
        if ($connectionId !== null) {
            return $this->connectionForUser($user, $connectionId, 'calendar');
        }

        $calendars = $this->connectionsFor($user, 'calendar')
            ->filter(fn (CalendarConnection $c) => CalendarProviders::has($c->provider) && CalendarProviders::for($c)->isWritable($c))
            ->values();
        if ($calendars->count() === 1) {
            return $calendars->first();
        }
        if ($calendars->isEmpty()) {
            throw new AuthorizationException('No connected calendar accepts new events. Ask the user to connect one in Brainitive.');
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
            'event' => CalendarFormatter::eventSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('Target calendar id (from list_calendar_connections, one with writable = true). Optional if only one writable calendar is connected.'),
            'title' => $schema->string()->description('Event title.')->required(),
            'date' => $schema->string()->description('Start date YYYY-MM-DD.')->required(),
            'time' => $schema->string()->description('Start time HH:MM (24h). Omit for an all-day event.'),
            'end_date' => $schema->string()->description('End date YYYY-MM-DD. Defaults to start date.'),
            'end_time' => $schema->string()->description('End time HH:MM (24h). Defaults to 30 min after start.'),
            'description' => $schema->string()->description('Optional event description / notes.'),
            'location' => $schema->string()->description('Optional location.'),
            'timezone' => $schema->string()->description('IANA timezone for the times, e.g. Europe/Zurich. Defaults to the app timezone.'),
        ];
    }
}
