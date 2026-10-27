<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The calendar page, reschedule and event endpoints go through the provider registry
 * (BRAI-140): any provider's events show as `external_event`, read-only providers refuse
 * writes with a 422, and a failing provider never breaks the page.
 */
class CalendarProviderDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        FakeCalendarProvider::reset();

        $this->user = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $this->connection = CalendarConnection::factory()->provider('fake')->create([
            'user_id' => $this->user->id,
            'account_email' => 'me@dav.example',
            'external_calendar_name' => 'Home',
            'external_calendar_color' => null,
        ]);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        parent::tearDown();
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05'] + $query));
    }

    public function test_provider_events_are_external_events_with_provider_details(): void
    {
        FakeCalendarProvider::$events = [
            NormalizedEvent::allDay('a1', 'Offsite', '2026-11-04', '2026-11-06', ['location' => 'Lisbon']),
            NormalizedEvent::make(['id' => 't1', 'title' => 'Standup', 'start_date' => '2026-11-05', 'start_time' => '09:00', 'end_date' => '2026-11-05', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin']),
        ];

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Calendar/Index')
            ->has('events', 2)
            ->where('events.0.type', 'external_event')
            ->where('events.0.id', 'a1')
            ->where('events.0.start_date', '2026-11-04')
            ->where('events.0.due_date', '2026-11-06')
            ->where('events.0.start_time', null)
            ->where('events.0.all_day', true)
            ->where('events.0.provider', 'fake')
            ->where('events.0.provider_label', 'Fake DAV')
            ->where('events.0.connection_id', $this->connection->id)
            ->where('events.0.read_only', false)
            ->where('events.0.source.type', 'external')
            ->where('events.0.color', '#0E83DC')
            ->where('events.1.start_time', '09:00')
            ->where('events.1.due_time', '09:15')
            ->where('connections.0.provider_label', 'Fake DAV')
            ->where('connections.0.read_only', false)
            ->where('connections.0.sync_error', null)
            ->where('filters.sources', ['board', 'list', 'external', 'booking'])
            ->where('calendarProviders.0.key', 'google')
            ->where('calendarProviders.0.connect_mode', 'oauth')
            // registry order, then the test-only fakes (other real providers may sit in between)
            ->where('calendarProviders', fn ($providers) => collect($providers)->last()['key'] === 'fake'
                && collect($providers)->last()['connect_mode'] === 'form'
                && collect($providers)->last()['fields'][2]['type'] === 'password')
        );

        // The user's own zone is asked for (wall-clock times in it).
        $this->assertSame([['list', 'Europe/Berlin']], FakeCalendarProvider::$calls);
    }

    public function test_connections_never_expose_secrets(): void
    {
        $this->connection->update(['credentials' => ['username' => 'me', 'password' => 'app-secret'], 'server_url' => 'https://dav.example']);

        $response = $this->page()->assertOk();

        $this->assertStringNotContainsString('app-secret', $response->getContent());
        $this->assertStringNotContainsString('access-token', $response->getContent());
        $this->assertSame('app-secret', $this->connection->fresh()->credentials['password']);
    }

    public function test_read_only_provider_events_are_marked_read_only(): void
    {
        FakeCalendarProvider::$writable = false;
        FakeCalendarProvider::$events = [NormalizedEvent::allDay('a1', 'Holiday', '2026-11-05')];

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.0.read_only', true)
            ->where('connections.0.read_only', true)
        );
    }

    public function test_a_failing_provider_stores_sync_error_and_the_page_still_renders(): void
    {
        FakeCalendarProvider::$fail = 'Reconnect this calendar.';

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('events', 0)
            ->where('connections.0.sync_error', 'Reconnect this calendar.')
        );

        $fresh = $this->connection->fresh();
        $this->assertSame('Reconnect this calendar.', $fresh->sync_error);
        $this->assertNotNull($fresh->sync_error_at);

        // The next successful load clears it.
        FakeCalendarProvider::$fail = null;
        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page->where('connections.0.sync_error', null));
        $this->assertNull($this->connection->fresh()->sync_error);
    }

    public function test_an_unexpected_error_is_stored_without_internals(): void
    {
        FakeCalendarProvider::$fail = 'crash';

        $this->page()->assertOk();

        $error = $this->connection->fresh()->sync_error;
        $this->assertStringContainsString('Couldn’t load this calendar', $error);
        $this->assertStringNotContainsString('secret internals', $error);
    }

    public function test_an_unknown_provider_is_a_sync_error_not_a_crash(): void
    {
        $this->connection->update(['provider' => 'gone']);

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.read_only', true)
            ->where('connections.0.provider_label', 'gone')
        );
        $this->assertSame('This calendar provider is no longer supported.', $this->connection->fresh()->sync_error);
    }

    public function test_legacy_google_source_filter_means_external(): void
    {
        FakeCalendarProvider::$events = [NormalizedEvent::allDay('a1', 'Offsite', '2026-11-05')];

        $this->page(['sources' => ['board', 'google']])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.sources', ['board', 'external'])
            ->has('events', 1)
        );

        // Without 'external' the providers aren't even asked.
        FakeCalendarProvider::$calls = [];
        $this->page(['sources' => ['board', 'list']])->assertOk()->assertInertia(fn (Assert $page) => $page->has('events', 0));
        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    public function test_reschedule_dispatches_to_the_provider(): void
    {
        $this->actingAs($this->user)->patch(route('calendar.reschedule'), [
            'type' => 'external_event',
            'id' => 'evt-1',
            'connection_id' => $this->connection->id,
            'start_date' => '2026-11-06',
            'start_time' => '10:00',
            'due_date' => '2026-11-06',
            'due_time' => '11:00',
            'timezone' => 'Europe/Berlin',
        ])->assertRedirect();

        $this->assertSame([['update', [
            'id' => 'evt-1',
            'start_date' => '2026-11-06',
            'start_time' => '10:00',
            'end_date' => '2026-11-06',
            'end_time' => '11:00',
            'timezone' => 'Europe/Berlin',
        ]]], FakeCalendarProvider::$calls);
    }

    public function test_legacy_google_event_reschedule_type_still_works(): void
    {
        $this->actingAs($this->user)->patch(route('calendar.reschedule'), [
            'type' => 'google_event', 'id' => 'evt-1', 'connection_id' => $this->connection->id, 'due_date' => '2026-11-06',
        ])->assertRedirect();

        $this->assertSame('update', FakeCalendarProvider::$calls[0][0]);
    }

    public function test_read_only_provider_refuses_writes_with_422(): void
    {
        FakeCalendarProvider::$writable = false;
        $as = $this->actingAs($this->user);

        $as->patchJson(route('calendar.reschedule'), [
            'type' => 'external_event', 'id' => 'evt-1', 'connection_id' => $this->connection->id, 'due_date' => '2026-11-06',
        ])->assertStatus(422)->assertJsonPath('message', 'This calendar is read-only.');

        $as->postJson(route('calendar.connections.events.create', $this->connection), ['title' => 'X', 'date' => '2026-11-06'])
            ->assertStatus(422)->assertJsonPath('errors.calendar.0', 'This calendar is read-only.');
        $as->patchJson(route('calendar.connections.events.update', [$this->connection, 'evt-1']), ['title' => 'X'])->assertStatus(422);
        $as->deleteJson(route('calendar.connections.events.destroy', [$this->connection, 'evt-1']))->assertStatus(422);

        // An Inertia request gets the message as errors.calendar.
        $as->from(route('calendar.index'))
            ->patch(route('calendar.connections.events.update', [$this->connection, 'evt-1']), ['title' => 'X'])
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHasErrors(['calendar' => 'This calendar is read-only.']);

        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    public function test_event_endpoints_dispatch_create_update_delete_and_show(): void
    {
        FakeCalendarProvider::$events = [NormalizedEvent::allDay('evt.1@dav', 'Offsite', '2026-11-05')];
        $as = $this->actingAs($this->user);

        $as->post(route('calendar.connections.events.create', $this->connection), [
            'title' => 'Focus', 'date' => '2026-11-06', 'time' => '09:00', 'end_time' => '10:30', 'location' => 'Desk', 'timezone' => 'Europe/Berlin',
        ])->assertRedirect();
        $this->assertSame(['create', [
            'title' => 'Focus', 'description' => null, 'location' => 'Desk',
            'start_date' => '2026-11-06', 'start_time' => '09:00', 'end_date' => null, 'end_time' => '10:30', 'timezone' => 'Europe/Berlin',
        ]], FakeCalendarProvider::$calls[0]);

        // Event ids may hold dots and @ (CalDAV / iCal UIDs).
        $as->getJson(route('calendar.connections.events.show', [$this->connection, 'evt.1@dav']))
            ->assertOk()
            ->assertJsonPath('event.title', 'Offsite')
            ->assertJsonPath('event.all_day', true)
            ->assertJsonPath('provider_label', 'Fake DAV')
            ->assertJsonPath('read_only', false);
        $as->getJson(route('calendar.connections.events.show', [$this->connection, 'missing']))->assertNotFound();

        $as->patch(route('calendar.connections.events.update', [$this->connection, 'evt.1@dav']), ['title' => 'Renamed'])->assertRedirect();
        $this->assertSame(['update', ['id' => 'evt.1@dav', 'title' => 'Renamed']], FakeCalendarProvider::$calls[1]);

        $as->delete(route('calendar.connections.events.destroy', [$this->connection, 'evt.1@dav']))->assertRedirect();
        $this->assertSame(['delete', 'evt.1@dav'], FakeCalendarProvider::$calls[2]);
    }

    public function test_dot_segment_event_ids_never_reach_the_provider(): void
    {
        $as = $this->actingAs($this->user);
        $as->getJson(route('calendar.connections.events.show', [$this->connection, '..']))->assertNotFound();
        $as->deleteJson(route('calendar.connections.events.destroy', [$this->connection, '.']))->assertNotFound();

        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    public function test_only_http_links_of_feeds_reach_the_page(): void
    {
        FakeCalendarProvider::$events = [
            NormalizedEvent::allDay('a1', 'Bad', '2026-11-05', null, ['external_url' => 'javascript:alert(1)']),
            NormalizedEvent::allDay('a2', 'Good', '2026-11-05', null, ['external_url' => 'https://cal.example/a2']),
        ];

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.0.external_url', null)
            ->where('events.1.external_url', 'https://cal.example/a2'));
    }

    public function test_other_users_connections_are_refused(): void
    {
        $other = User::factory()->create();
        $as = $this->actingAs($other);

        $as->getJson(route('calendar.connections.events.show', [$this->connection, 'evt-1']))->assertForbidden();
        $as->post(route('calendar.connections.events.create', $this->connection), ['title' => 'X', 'date' => '2026-11-06'])->assertForbidden();
        $as->patchJson(route('calendar.reschedule'), [
            'type' => 'external_event', 'id' => 'evt-1', 'connection_id' => $this->connection->id, 'due_date' => '2026-11-06',
        ])->assertNotFound();

        $this->assertSame([], FakeCalendarProvider::$calls);
    }
}
