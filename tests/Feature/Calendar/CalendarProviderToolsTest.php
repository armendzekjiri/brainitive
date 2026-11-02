<?php

namespace Tests\Feature\Calendar;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateCalendarEventTool;
use App\Mcp\Tools\CreateGoogleTaskTool;
use App\Mcp\Tools\DeleteCalendarEventTool;
use App\Mcp\Tools\ListCalendarConnectionsTool;
use App\Mcp\Tools\ListCalendarEventsTool;
use App\Mcp\Tools\UpdateCalendarEventTool;
use App\Mcp\Tools\UpdateGoogleTaskTool;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Notifications\CalendarReminderNotification;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * The MCP calendar tools and the reminder command work through the provider registry, so
 * every provider's calendars can be read, and every writable one written (BRAI-140).
 */
class CalendarProviderToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        FakeCalendarProvider::reset();
        $this->user = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $this->connection = CalendarConnection::factory()->provider('fake')->create([
            'user_id' => $this->user->id,
            'external_calendar_name' => 'Home',
        ]);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        parent::tearDown();
    }

    public function test_list_connections_shows_provider_and_writability(): void
    {
        FakeCalendarProvider::$writable = false;

        BrainitiveServer::actingAs($this->user)->tool(ListCalendarConnectionsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j
                ->where('count', 1)
                ->where('connections.0.provider', 'Fake DAV')
                ->where('connections.0.writable', false)
                ->etc());
    }

    public function test_list_events_reads_any_provider_and_reports_failures(): void
    {
        FakeCalendarProvider::$events = [
            NormalizedEvent::allDay('a1', 'Offsite', '2026-11-04', '2026-11-05'),
            NormalizedEvent::make(['id' => 't1', 'title' => 'Standup', 'start_date' => '2026-11-04', 'start_time' => '09:00', 'end_date' => '2026-11-04', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin']),
        ];

        BrainitiveServer::actingAs($this->user)->tool(ListCalendarEventsTool::class, ['from' => '2026-11-01', 'to' => '2026-11-07'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j
                ->where('count', 2)
                ->where('events.0.event_id', 'a1')
                ->where('events.0.start', '2026-11-04')
                ->where('events.0.end', '2026-11-05')
                ->where('events.1.start', '2026-11-04T09:00:00+01:00')
                ->where('events.1.calendar', 'Home')
                ->where('errors', [])
                ->etc());

        FakeCalendarProvider::$fail = 'Reconnect this calendar.';
        BrainitiveServer::actingAs($this->user)->tool(ListCalendarEventsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j
                ->where('count', 0)
                ->where('errors', ['Home: Reconnect this calendar.'])
                ->etc());
    }

    public function test_write_tools_dispatch_to_writable_providers(): void
    {
        BrainitiveServer::actingAs($this->user)->tool(CreateCalendarEventTool::class, [
            'title' => 'Focus', 'date' => '2026-11-06', 'time' => '09:00', 'timezone' => 'Europe/Berlin',
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $j) => $j
            ->where('created', true)
            ->where('event.start', '2026-11-06T09:00:00+01:00')
            ->where('event.end', '2026-11-06T09:30:00+01:00')
            ->etc());

        BrainitiveServer::actingAs($this->user)->tool(UpdateCalendarEventTool::class, [
            'connection_id' => $this->connection->id, 'event_id' => 'e1', 'title' => 'Renamed',
        ])->assertOk();
        BrainitiveServer::actingAs($this->user)->tool(DeleteCalendarEventTool::class, [
            'connection_id' => $this->connection->id, 'event_id' => 'e1',
        ])->assertOk();

        $this->assertSame(['create', 'update', 'delete'], array_column(FakeCalendarProvider::$calls, 0));
        $this->assertSame(['id' => 'e1', 'title' => 'Renamed'], FakeCalendarProvider::$calls[1][1]);
    }

    public function test_write_tools_refuse_read_only_calendars(): void
    {
        FakeCalendarProvider::$writable = false;

        BrainitiveServer::actingAs($this->user)->tool(CreateCalendarEventTool::class, ['title' => 'X', 'date' => '2026-11-06'])
            ->assertHasErrors(['No connected calendar accepts new events']);
        BrainitiveServer::actingAs($this->user)->tool(CreateCalendarEventTool::class, ['connection_id' => $this->connection->id, 'title' => 'X', 'date' => '2026-11-06'])
            ->assertHasErrors(['This calendar is read-only.']);
        BrainitiveServer::actingAs($this->user)->tool(DeleteCalendarEventTool::class, ['connection_id' => $this->connection->id, 'event_id' => 'e1'])
            ->assertHasErrors(['This calendar is read-only.']);

        // Someone else's calendar can't be reached.
        BrainitiveServer::actingAs(User::factory()->create())->tool(DeleteCalendarEventTool::class, ['connection_id' => $this->connection->id, 'event_id' => 'e1'])
            ->assertHasErrors(['No connected calendar found']);

        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    /** Codex review, finding 15: a calendar switched off ("turn single calendars on or off") is off for the AI too. */
    public function test_write_tools_refuse_a_switched_off_calendar_by_explicit_id(): void
    {
        $this->connection->update(['is_active' => false]);
        $tasks = CalendarConnection::factory()->tasks()->create(['user_id' => $this->user->id, 'is_active' => false]);

        BrainitiveServer::actingAs($this->user)->tool(CreateCalendarEventTool::class, ['connection_id' => $this->connection->id, 'title' => 'X', 'date' => '2026-11-06'])
            ->assertHasErrors(['No connected calendar found']);
        BrainitiveServer::actingAs($this->user)->tool(UpdateCalendarEventTool::class, ['connection_id' => $this->connection->id, 'event_id' => 'e1', 'title' => 'Y'])
            ->assertHasErrors(['No connected calendar found']);
        BrainitiveServer::actingAs($this->user)->tool(DeleteCalendarEventTool::class, ['connection_id' => $this->connection->id, 'event_id' => 'e1'])
            ->assertHasErrors(['No connected calendar found']);
        BrainitiveServer::actingAs($this->user)->tool(UpdateGoogleTaskTool::class, ['connection_id' => $tasks->id, 'task_id' => 't1', 'title' => 'Y'])
            ->assertHasErrors(['No connected Google Tasks list found']);
        BrainitiveServer::actingAs($this->user)->tool(CreateGoogleTaskTool::class, ['connection_id' => $tasks->id, 'title' => 'Y'])
            ->assertHasErrors(['No connected Google Tasks list found']);

        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    /** Codex review, finding 17: bounded reads (span, result count) and a write budget per user. */
    public function test_list_events_caps_the_span_and_the_result_count(): void
    {
        BrainitiveServer::actingAs($this->user)->tool(ListCalendarEventsTool::class, ['from' => '2026-01-01', 'to' => '2026-12-31'])
            ->assertHasErrors(['at most 62 days']);
        BrainitiveServer::actingAs($this->user)->tool(ListCalendarEventsTool::class, ['from' => '2026-11-10', 'to' => '2026-11-01'])
            ->assertHasErrors();
        $this->assertSame([], array_column(FakeCalendarProvider::$calls, 0));

        FakeCalendarProvider::$events = array_map(
            fn (int $i) => NormalizedEvent::allDay('e'.$i, 'Event '.$i, '2026-11-04', '2026-11-05'),
            range(1, 600),
        );
        BrainitiveServer::actingAs($this->user)->tool(ListCalendarEventsTool::class, ['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('count', 500)->where('truncated', true)->has('events', 500)->etc());
    }

    public function test_write_tools_have_a_per_user_budget(): void
    {
        for ($i = 0; $i < 30; $i++) {
            BrainitiveServer::actingAs($this->user)->tool(UpdateCalendarEventTool::class, ['connection_id' => $this->connection->id, 'event_id' => 'e1', 'title' => 'T'.$i])->assertOk();
        }
        BrainitiveServer::actingAs($this->user)->tool(DeleteCalendarEventTool::class, ['connection_id' => $this->connection->id, 'event_id' => 'e1'])
            ->assertHasErrors(['Too many calendar changes']);
        $this->assertCount(30, FakeCalendarProvider::$calls);

        // someone else has their own budget
        $other = User::factory()->create();
        $theirs = CalendarConnection::factory()->provider('fake')->create(['user_id' => $other->id]);
        BrainitiveServer::actingAs($other)->tool(DeleteCalendarEventTool::class, ['connection_id' => $theirs->id, 'event_id' => 'e1'])->assertOk();
    }

    public function test_reminders_come_from_any_provider_once(): void
    {
        Notification::fake();
        $this->travelTo(now()->setTimezone('Europe/Berlin')->setDate(2026, 11, 5)->setTime(8, 50));
        FakeCalendarProvider::$events = [
            NormalizedEvent::make(['id' => 't1', 'title' => 'Standup', 'start_date' => '2026-11-05', 'start_time' => '09:00', 'end_date' => '2026-11-05', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin']),
            NormalizedEvent::make(['id' => 't2', 'title' => 'Later', 'start_date' => '2026-11-05', 'start_time' => '11:00', 'end_date' => '2026-11-05', 'end_time' => '11:15', 'timezone' => 'Europe/Berlin']),
            NormalizedEvent::allDay('a1', 'Holiday', '2026-11-05'),
        ];
        // A broken calendar of someone else doesn't stop the run.
        CalendarConnection::factory()->provider('gone')->create();

        $this->artisan('app:send-calendar-reminders')->assertSuccessful();
        $this->artisan('app:send-calendar-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->user, CalendarReminderNotification::class, 1);
        Notification::assertSentTo($this->user, CalendarReminderNotification::class, fn ($n) => $n->title === 'Standup' && $n->whenLabel === 'Starts in 10 minutes');
    }
}
