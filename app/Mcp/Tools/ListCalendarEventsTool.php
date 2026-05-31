<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Services\GoogleCalendarService;
use App\Support\Mcp\CalendarFormatter;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List Google Calendar events in a date range across the user\'s connected calendars — use this to see what is already on their schedule before planning. Defaults to the next 7 days if no dates are given. Dates are YYYY-MM-DD.')]
class ListCalendarEventsTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleCalendarService $calendar): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'connection_id' => 'nullable|integer',
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : Carbon::today();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : $from->copy()->addDays(7);

        $connections = $this->connectionsFor($request->user(), 'calendar');
        if (isset($validated['connection_id'])) {
            $connections = $connections->where('id', $validated['connection_id'])->values();
        }

        if ($connections->isEmpty()) {
            return Response::error('No connected Google calendars. Call list_calendar_connections first.');
        }

        $events = [];
        foreach ($connections as $connection) {
            foreach ($calendar->listEvents($connection, $from, $to) as $raw) {
                $events[] = CalendarFormatter::event($raw, $connection);
            }
        }

        // Sort by start so the week reads chronologically.
        usort($events, fn ($a, $b) => ($a['start'] ?? '') <=> ($b['start'] ?? ''));

        return Response::structured([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => $events,
            'count' => count($events),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date YYYY-MM-DD. Defaults to today.'),
            'to' => $schema->string()->description('End date YYYY-MM-DD (inclusive). Defaults to 7 days after "from".'),
            'connection_id' => $schema->integer()->description('Optional: limit to one calendar (from list_calendar_connections). Defaults to all connected calendars.'),
        ];
    }
}
