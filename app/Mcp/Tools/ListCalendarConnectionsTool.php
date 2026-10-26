<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Support\Calendar\CalendarProviders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the user\'s connected calendars (Google Calendar or any other connected provider) and Google Tasks lists. Returns each connection_id, name, kind (calendar or tasks), provider, account and whether it accepts new events (writable) — call this first before reading or writing calendar events / Google tasks. If nothing is returned, the user has not connected a calendar in Brainitive yet.')]
class ListCalendarConnectionsTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request): Response|ResponseFactory
    {
        $connections = $this->connectionsFor($request->user())
            ->map(fn ($c) => [
                'connection_id' => $c->id,
                'name' => (string) $c->external_calendar_name,
                'kind' => $c->kind,            // 'calendar' or 'tasks'
                'provider' => $c->kind === 'tasks'
                    ? 'Google Tasks'
                    : (CalendarProviders::has($c->provider) ? CalendarProviders::for($c)->label() : $c->provider),
                'account' => $c->account_email,
                'writable' => $c->kind === 'tasks'
                    || (CalendarProviders::has($c->provider) && CalendarProviders::for($c)->isWritable($c)),
            ])
            ->values()
            ->all();

        return Response::structured([
            'connections' => $connections,
            'count' => count($connections),
            'hint' => count($connections) === 0
                ? 'No calendars connected. The user can connect one in Brainitive under Calendar → Add calendar.'
                : 'Use a connection_id with kind "calendar" for events (writes need writable = true), or kind "tasks" for Google Tasks.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'connections' => $schema->array()->items($schema->object([
                'connection_id' => $schema->integer(),
                'name' => $schema->string(),
                'kind' => $schema->string()->description('calendar or tasks.'),
                'provider' => $schema->string()->description('Where the calendar lives, e.g. Google Calendar.'),
                'account' => $schema->string(),
                'writable' => $schema->boolean()->description('False for read-only calendars (e.g. ICS links).'),
            ])),
            'count' => $schema->integer(),
            'hint' => $schema->string(),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
