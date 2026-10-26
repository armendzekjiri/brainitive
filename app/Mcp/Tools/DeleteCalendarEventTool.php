<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Support\Calendar\CalendarProviderException;
use App\Support\Calendar\CalendarProviders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a calendar event from a writable connected calendar. Identify it by connection_id + event_id (from list_calendar_events). This permanently removes it from the user\'s calendar — confirm with the user before calling.')]
class DeleteCalendarEventTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'required|integer',
            'event_id' => 'required|string|max:1024|not_in:.,..',
        ]);

        try {
            $connection = $this->connectionForUser($request->user(), $validated['connection_id'], 'calendar');
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        try {
            CalendarProviders::writable($connection)->deleteEvent($connection, $validated['event_id']);
        } catch (CalendarProviderException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(['deleted' => true, 'event_id' => $validated['event_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'deleted' => $schema->boolean(),
            'event_id' => $schema->string(),
        ];
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
