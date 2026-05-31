<?php

namespace App\Support\Mcp;

use App\Models\CalendarConnection;

/**
 * Normalises raw Google Calendar event / Google Tasks JSON into the compact,
 * assistant-friendly shape the MCP calendar tools return.
 */
class CalendarFormatter
{
    /**
     * @param  array<string, mixed>  $event  raw Google Calendar event
     * @return array<string, mixed>
     */
    public static function event(array $event, ?CalendarConnection $connection = null): array
    {
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        $allDay = isset($start['date']);

        return [
            'event_id' => $event['id'] ?? null,
            'title' => $event['summary'] ?? '(no title)',
            'all_day' => $allDay,
            'start' => $start['dateTime'] ?? $start['date'] ?? null,
            'end' => $end['dateTime'] ?? $end['date'] ?? null,
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'link' => $event['htmlLink'] ?? null,
            'calendar' => $connection?->external_calendar_name,
            'connection_id' => $connection?->id,
        ];
    }

    /** JSON Schema fragment for one event() result (for tools' outputSchema). */
    public static function eventSchema($schema)
    {
        return $schema->object([
            'event_id' => $schema->string(),
            'title' => $schema->string(),
            'all_day' => $schema->boolean(),
            'start' => $schema->string()->description('RFC3339 datetime, or YYYY-MM-DD for all-day.'),
            'end' => $schema->string(),
            'description' => $schema->string(),
            'location' => $schema->string(),
            'link' => $schema->string()->description('Link to open the event in Google Calendar.'),
            'calendar' => $schema->string(),
            'connection_id' => $schema->integer(),
        ]);
    }

    /** JSON Schema fragment for one task() result (for tools' outputSchema). */
    public static function taskSchema($schema)
    {
        return $schema->object([
            'task_id' => $schema->string(),
            'title' => $schema->string(),
            'notes' => $schema->string(),
            'due' => $schema->string()->description('RFC3339 due date, if set.'),
            'completed' => $schema->boolean(),
            'list' => $schema->string(),
            'connection_id' => $schema->integer(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $task  raw Google Tasks task
     * @return array<string, mixed>
     */
    public static function task(array $task, ?CalendarConnection $connection = null): array
    {
        return [
            'task_id' => $task['id'] ?? null,
            'title' => $task['title'] ?? '(no title)',
            'notes' => $task['notes'] ?? null,
            'due' => $task['due'] ?? null,
            'completed' => ($task['status'] ?? null) === 'completed',
            'list' => $connection?->external_calendar_name,
            'connection_id' => $connection?->id,
        ];
    }
}
