<?php

namespace App\Support\Mcp;

use App\Models\CalendarConnection;
use App\Support\Calendar\NormalizedEvent;

/**
 * Turns calendar events (normalized provider events, or raw Google JSON) and Google Tasks
 * into the compact, assistant-friendly shape the MCP calendar tools return.
 */
class CalendarFormatter
{
    /**
     * A normalized provider event (App\Support\Calendar\NormalizedEvent) in the event() shape.
     * All-day: start/end are YYYY-MM-DD with an inclusive end; timed: RFC3339.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function normalized(array $event, ?CalendarConnection $connection = null): array
    {
        return [
            'event_id' => (string) ($event['id'] ?? ''),
            'title' => (string) (($event['title'] ?? '') ?: '(no title)'),
            'all_day' => (bool) ($event['all_day'] ?? false),
            'start' => NormalizedEvent::boundary($event, 'start'),
            'end' => NormalizedEvent::boundary($event, 'end'),
            'description' => (string) ($event['description'] ?? ''),
            'location' => (string) ($event['location'] ?? ''),
            'link' => (string) ($event['external_url'] ?? ''),
            'calendar' => $connection?->external_calendar_name ?? '',
            'connection_id' => $connection?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $event  raw Google Calendar event
     * @return array<string, mixed>
     */
    public static function event(array $event, ?CalendarConnection $connection = null): array
    {
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        $allDay = isset($start['date']);

        // Coerce every optional string to '' (never null) so strict MCP clients
        // that validate the output schema don't reject the whole response.
        return [
            'event_id' => $event['id'] ?? '',
            'title' => $event['summary'] ?? '(no title)',
            'all_day' => $allDay,
            'start' => $start['dateTime'] ?? $start['date'] ?? '',
            'end' => $end['dateTime'] ?? $end['date'] ?? '',
            'description' => $event['description'] ?? '',
            'location' => $event['location'] ?? '',
            'link' => $event['htmlLink'] ?? '',
            'calendar' => $connection?->external_calendar_name ?? '',
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
            'start' => $schema->string()->description('RFC3339 datetime, or YYYY-MM-DD for all-day (end date inclusive).'),
            'end' => $schema->string(),
            'description' => $schema->string(),
            'location' => $schema->string(),
            'link' => $schema->string()->description('Link to open the event in its calendar app, if any.'),
            'calendar' => $schema->string(),
            'connection_id' => $schema->integer()->nullable(),
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
            'connection_id' => $schema->integer()->nullable(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $task  raw Google Tasks task
     * @return array<string, mixed>
     */
    public static function task(array $task, ?CalendarConnection $connection = null): array
    {
        return [
            'task_id' => $task['id'] ?? '',
            'title' => $task['title'] ?? '(no title)',
            'notes' => $task['notes'] ?? '',
            'due' => $task['due'] ?? '',
            'completed' => ($task['status'] ?? null) === 'completed',
            'list' => $connection?->external_calendar_name ?? '',
            'connection_id' => $connection?->id,
        ];
    }
}
