<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesCalendarConnections;
use App\Services\GoogleTasksService;
use App\Support\Mcp\CalendarFormatter;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the user\'s Google Tasks across their connected task lists, optionally filtered to a due-date range (YYYY-MM-DD). Returns task_id, title, due date and completion state.')]
class ListGoogleTasksTool extends Tool
{
    use ResolvesCalendarConnections;

    public function handle(Request $request, GoogleTasksService $tasks): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'connection_id' => 'nullable|integer',
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : null;

        $connections = $this->connectionsFor($request->user(), 'tasks');
        if (isset($validated['connection_id'])) {
            $connections = $connections->where('id', $validated['connection_id'])->values();
        }

        if ($connections->isEmpty()) {
            return Response::error('No connected Google Tasks lists. Call list_calendar_connections first.');
        }

        $items = [];
        foreach ($connections as $connection) {
            foreach ($tasks->listTasks($connection, $from, $to) as $raw) {
                $items[] = CalendarFormatter::task($raw, $connection);
            }
        }

        return Response::structured([
            'tasks' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'tasks' => $schema->array()->items(CalendarFormatter::taskSchema($schema)),
            'count' => $schema->integer(),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Only tasks due on/after this date YYYY-MM-DD.'),
            'to' => $schema->string()->description('Only tasks due on/before this date YYYY-MM-DD.'),
            'connection_id' => $schema->integer()->description('Optional: limit to one task list (from list_calendar_connections).'),
        ];
    }
}
