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
