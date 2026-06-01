<?php

namespace Tests\Unit;

use App\Support\Mcp\CalendarFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Strict MCP clients (e.g. OpenClaw) validate tool output against the declared
 * schema and reject the whole response if a `string` field comes back `null`.
 * These guard that the formatters always emit strings for their string fields,
 * even when the raw Google payload omits everything.
 */
class CalendarFormatterTest extends TestCase
{
    /** @var list<string> string fields in event() that must never be null */
    private array $eventStringFields = [
        'event_id', 'title', 'start', 'end', 'description', 'location', 'link', 'calendar',
    ];

    /** @var list<string> string fields in task() that must never be null */
    private array $taskStringFields = ['task_id', 'title', 'notes', 'due', 'list'];

    public function test_event_string_fields_are_strings_for_empty_payload(): void
    {
        $event = CalendarFormatter::event([]);

        foreach ($this->eventStringFields as $field) {
            $this->assertIsString($event[$field], "event['$field'] must be a string, never null");
        }
    }

    public function test_task_string_fields_are_strings_for_empty_payload(): void
    {
        $task = CalendarFormatter::task([]);

        foreach ($this->taskStringFields as $field) {
            $this->assertIsString($task[$field], "task['$field'] must be a string, never null");
        }
    }

    public function test_event_passes_through_real_values(): void
    {
        $event = CalendarFormatter::event([
            'id' => 'abc',
            'summary' => 'Standup',
            'start' => ['dateTime' => '2026-06-01T09:00:00Z'],
            'end' => ['dateTime' => '2026-06-01T09:15:00Z'],
            'description' => 'Daily',
            'location' => 'Zoom',
            'htmlLink' => 'https://cal/abc',
        ]);

        $this->assertSame('Standup', $event['title']);
        $this->assertSame('2026-06-01T09:00:00Z', $event['start']);
        $this->assertSame('Daily', $event['description']);
        $this->assertFalse($event['all_day']);
    }
}
