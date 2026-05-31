<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the user\'s connected Google calendars and Google Tasks lists. Returns each connection_id, name, kind (calendar or tasks) and account — call this first before reading or writing calendar events / Google tasks. If nothing is returned, the user has not connected a Google calendar in Brainitive yet.')]
class ListCalendarConnectionsTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request): Response|ResponseFactory
    {
        $connections = $this->connectionsFor($request->user())
            ->map(fn ($c) => [
                'connection_id' => $c->id,
                'name' => $c->external_calendar_name,
                'kind' => $c->kind,            // 'calendar' or 'tasks'
                'account' => $c->account_email,
            ])
            ->values()
            ->all();

        return Response::structured([
            'connections' => $connections,
            'count' => count($connections),
            'hint' => count($connections) === 0
                ? 'No Google calendars connected. The user can connect one in Brainitive under Calendar → Connect Google.'
                : 'Use a connection_id with kind "calendar" for events, or kind "tasks" for Google Tasks.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
