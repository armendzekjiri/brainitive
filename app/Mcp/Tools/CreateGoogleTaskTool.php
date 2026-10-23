<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Models\CalendarConnection;
use App\Services\GoogleTasksService;
use App\Support\Mcp\CalendarFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a task to one of the user\'s Google Tasks lists. Provide a title; optionally a due date (YYYY-MM-DD), a time, and notes. Use this for to-dos; use create_calendar_event for scheduled time blocks.')]
class CreateGoogleTaskTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleTasksService $tasks): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'nullable|integer',
            'title' => 'required|string|max:1024',
            'date' => 'nullable|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string|max:8000',
            'timezone' => 'nullable|string|max:64',
        ]);

        try {
            $connection = $this->resolveTargetList($request->user(), $validated['connection_id'] ?? null);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $task = $tasks->createTask($connection, $validated);

        if (! $task) {
            return Response::error('Google rejected the task (the connection may need reauthorising in Brainitive).');
        }

        return Response::structured([
            'created' => true,
            'task' => CalendarFormatter::task($task, $connection),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function resolveTargetList($user, ?int $connectionId): CalendarConnection
    {
        if ($connectionId !== null) {
            return $this->connectionForUser($user, $connectionId, 'tasks');
        }

        $lists = $this->connectionsFor($user, 'tasks');
        if ($lists->count() === 1) {
            return $lists->first();
        }
        if ($lists->isEmpty()) {
            throw new AuthorizationException('No connected Google Tasks lists. Ask the user to connect one in Brainitive.');
        }

        throw new AuthorizationException('Multiple task lists are connected — specify connection_id (see list_calendar_connections).');
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'task' => CalendarFormatter::taskSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('Target task list id (from list_calendar_connections). Optional if only one is connected.'),
            'title' => $schema->string()->description('Task title.')->required(),
            'date' => $schema->string()->description('Optional due date YYYY-MM-DD.'),
            'time' => $schema->string()->description('Optional due time HH:MM (24h).'),
            'description' => $schema->string()->description('Optional notes.'),
            'timezone' => $schema->string()->description('IANA timezone for the due time, e.g. Europe/Zurich.'),
        ];
    }
}
