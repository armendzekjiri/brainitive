<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Services\GoogleTasksService;
use App\Support\Mcp\CalendarFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a Google Task: rename it, change its due date/time or notes, or mark it completed/not completed. Identify it by connection_id + task_id (from list_google_tasks).')]
class UpdateGoogleTaskTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleTasksService $tasks): Response|ResponseFactory
    {
        $validated = $request->validate([
            'connection_id' => 'required|integer',
            'task_id' => 'required|string',
            'title' => 'sometimes|string|max:1024',
            'description' => 'sometimes|nullable|string|max:8000',
            'date' => 'sometimes|nullable|date_format:Y-m-d',
            'time' => 'sometimes|nullable|date_format:H:i',
            'completed' => 'sometimes|boolean',
            'timezone' => 'nullable|string|max:64',
        ]);

        try {
            $connection = $this->connectionForUser($request->user(), $validated['connection_id'], 'tasks');
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $task = $tasks->updateTask($connection, $validated['task_id'], $validated);

        if (! $task) {
            return Response::error('Could not update the task (it may not exist, or the connection needs reauthorising).');
        }

        return Response::structured([
            'updated' => true,
            'task' => CalendarFormatter::task($task, $connection),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'task' => \App\Support\Mcp\CalendarFormatter::taskSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('The task list id (from list_calendar_connections).')->required(),
            'task_id' => $schema->string()->description('The task id (from list_google_tasks).')->required(),
            'title' => $schema->string()->description('New title.'),
            'description' => $schema->string()->description('New notes.'),
            'date' => $schema->string()->description('New due date YYYY-MM-DD (pass empty to clear).'),
            'time' => $schema->string()->description('New due time HH:MM (24h).'),
            'completed' => $schema->boolean()->description('Mark the task completed (true) or not completed (false).'),
            'timezone' => $schema->string()->description('IANA timezone for the due time, e.g. Europe/Zurich.'),
        ];
    }
}
