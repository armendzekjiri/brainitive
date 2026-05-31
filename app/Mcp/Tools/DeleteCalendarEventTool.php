<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Services\GoogleCalendarService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a Google Calendar event. Identify it by connection_id + event_id (from list_calendar_events). This permanently removes it from the user\'s Google calendar — confirm with the user before calling.')]
class DeleteCalendarEventTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleCalendarService $calendar): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'required|integer',
            'event_id' => 'required|string',
        ]);

        try {
            $connection = $this->connectionForUser($request->user(), $validated['connection_id'], 'calendar');
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $ok = $calendar->deleteEvent($connection, $validated['event_id']);

        return $ok
            ? Response::structured(['deleted' => true, 'event_id' => $validated['event_id']])
            : Response::error('Could not delete the event (it may not exist, or the connection needs reauthorising).');
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('The calendar id (from list_calendar_connections).')->required(),
            'event_id' => $schema->string()->description('The event id (from list_calendar_events).')->required(),
        ];
    }
}
