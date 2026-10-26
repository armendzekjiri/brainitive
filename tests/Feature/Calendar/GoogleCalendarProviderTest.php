<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Notifications\CalendarReminderNotification;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\PendingConnection;
use App\Support\Calendar\Providers\GoogleCalendarProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

/**
 * Google Calendar on the provider contract: Google JSON ↔ normalized events, errors, and the
 * Google connect flow through the shared picker (PendingConnection).
 */
class GoogleCalendarProviderTest extends TestCase
{
    use RefreshDatabase;

    private GoogleCalendarProvider $google;

    private CalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->google = app(GoogleCalendarProvider::class);
        $this->connection = CalendarConnection::factory()->create(['external_calendar_id' => 'team@group.calendar.google.com']);
    }

    public function test_all_day_end_date_is_exclusive_in_google_and_inclusive_when_normalized(): void
    {
        $event = $this->google->normalize([
            'id' => 'g1',
            'summary' => 'Offsite',
            'description' => 'Agenda',
            'location' => 'Lisbon',
            'htmlLink' => 'https://calendar.google.com/event?eid=g1',
            'start' => ['date' => '2026-11-04'],
            'end' => ['date' => '2026-11-07'],
        ], 'Europe/Berlin');

        $this->assertSame([
            'id' => 'g1',
            'title' => 'Offsite',
            'description' => 'Agenda',
            'location' => 'Lisbon',
            'all_day' => true,
            'start_date' => '2026-11-04',
            'start_time' => null,
            'end_date' => '2026-11-06',
            'end_time' => null,
            'timezone' => null,
            'external_url' => 'https://calendar.google.com/event?eid=g1',
        ], $event);

        // A one-day event (end = next day) ends the same day.
        $single = $this->google->normalize(['id' => 'g2', 'start' => ['date' => '2026-11-04'], 'end' => ['date' => '2026-11-05']], null);
        $this->assertSame(['2026-11-04', '2026-11-04', '(untitled)'], [$single['start_date'], $single['end_date'], $single['title']]);
    }

    public function test_timed_events_keep_googles_wall_clock_or_convert_to_the_given_zone(): void
    {
        $raw = [
            'id' => 'g3',
            'summary' => 'Standup',
            'start' => ['dateTime' => '2026-11-05T09:00:00+01:00', 'timeZone' => 'Europe/Berlin'],
            'end' => ['dateTime' => '2026-11-05T09:15:00+01:00'],
        ];

        $native = $this->google->normalize($raw, null);
        $this->assertSame(['2026-11-05', '09:00', '2026-11-05', '09:15', '+01:00', false], [
            $native['start_date'], $native['start_time'], $native['end_date'], $native['end_time'], $native['timezone'], $native['all_day'],
        ]);

        $newYork = $this->google->normalize($raw, 'America/New_York');
        $this->assertSame(['2026-11-05', '03:00', '03:15', 'America/New_York'], [
            $newYork['start_date'], $newYork['start_time'], $newYork['end_time'], $newYork['timezone'],
        ]);

        // Crossing midnight moves the date too.
        $tokyo = $this->google->normalize($raw, 'Asia/Tokyo');
        $this->assertSame(['2026-11-05', '17:00'], [$tokyo['start_date'], $tokyo['start_time']]);
        $late = $this->google->normalize(['id' => 'g4', 'start' => ['dateTime' => '2026-11-05T23:30:00Z'], 'end' => ['dateTime' => '2026-11-06T00:30:00Z']], 'Europe/Berlin');
        $this->assertSame(['2026-11-06', '00:30', '2026-11-06', '01:30'], [$late['start_date'], $late['start_time'], $late['end_date'], $late['end_time']]);

        // Events without a start are skipped.
        $this->assertNull($this->google->normalize(['id' => 'g5'], null));
    }

    public function test_list_events_normalizes_and_failures_throw(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*' => Http::sequence()
                ->push(['items' => [
                    ['id' => 'g1', 'summary' => 'Offsite', 'start' => ['date' => '2026-11-04'], 'end' => ['date' => '2026-11-05']],
                    ['id' => 'broken'],
                ]])
                ->push(['error' => ['message' => 'Rate limit']], 429),
        ]);

        $events = $this->google->listEvents($this->connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertCount(1, $events);
        $this->assertSame('g1', $events[0]['id']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/calendars/team%40group.calendar.google.com/events'));

        $this->expectException(CalendarSyncFailed::class);
        $this->google->listEvents($this->connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
    }

    public function test_expired_access_is_a_sync_error_on_the_calendar_page(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->connection->update(['token_expires_at' => now()->subMinute()]);

        $this->actingAs($this->connection->user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('events', 0));

        $fresh = $this->connection->fresh();
        $this->assertSame('Google access expired. Reconnect this calendar.', $fresh->sync_error);
        $this->assertFalse($fresh->is_active);

        // Switched off, it keeps telling the user why — without asking Google again.
        Http::fake(fn () => throw new \RuntimeException('Google must not be called for an inactive calendar'));
        $this->actingAs($this->connection->user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.0.id', $this->connection->id)
                ->where('connections.0.is_active', false)
                ->where('connections.0.sync_error', 'Google access expired. Reconnect this calendar.'));

        // Reconnecting the account revives it.
        (new PendingConnection('google', $fresh->account_email, null, [], [], ['access_token' => 'fresh', 'refresh_token' => 'r', 'expires_in' => 3600]))->put();
        $this->post(route('calendar.connections.store'), ['external_calendar_ids' => []])->assertRedirect();
        $revived = $this->connection->fresh();
        $this->assertTrue($revived->is_active);
        $this->assertNull($revived->sync_error);
        $this->assertSame('fresh', $revived->access_token);
    }

    public function test_the_oauth_callback_stores_the_picker_state_without_saving_a_connection(): void
    {
        $user = User::factory()->create();
        $googleUser = (new \Laravel\Socialite\Two\User)
            ->map(['email' => 'me@gmail.com', 'name' => 'Me'])
            ->setToken('access-1')
            ->setRefreshToken('refresh-1')
            ->setExpiresIn(3600);
        $driver = \Mockery::mock();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList' => Http::sequence()
                ->push(['items' => [
                    ['id' => 'me@gmail.com', 'summary' => 'Me', 'backgroundColor' => '#4285F4', 'primary' => true, 'accessRole' => 'owner'],
                    ['id' => 'holidays', 'summary' => 'Holidays', 'accessRole' => 'reader'],
                    ['id' => 'busy', 'summary' => 'Busy only', 'accessRole' => 'freeBusyReader'],
                ]])
                ->push(['error' => 'nope'], 500),
            'tasks.googleapis.com/*' => Http::response(['items' => [['id' => 'list-1', 'title' => 'My Tasks']]]),
        ]);

        $this->actingAs($user)->get(route('calendar.connections.google.callback', ['code' => 'x']))
            ->assertRedirect(route('calendar.index', ['picking' => 'google']));

        $pending = PendingConnection::current();
        $this->assertSame(['google', 'me@gmail.com', 'Me'], [$pending->provider, $pending->accountEmail, $pending->accountName]);
        $this->assertSame(['access-1', 'refresh-1', 3600], [$pending->secrets['access_token'], $pending->secrets['refresh_token'], $pending->secrets['expires_in']]);
        $this->assertSame([
            ['id' => 'me@gmail.com', 'name' => 'Me', 'color' => '#4285F4', 'primary' => true, 'read_only' => false],
            ['id' => 'holidays', 'name' => 'Holidays', 'color' => null, 'primary' => false, 'read_only' => true],
        ], $pending->calendars);
        $this->assertSame([['id' => 'list-1', 'title' => 'My Tasks']], $pending->taskLists);
        $this->assertSame(1, CalendarConnection::count()); // only setUp's row: the probe stays unsaved

        // Picking the read-only calendar stores it as read-only.
        $this->post(route('calendar.connections.store'), ['external_calendar_ids' => ['holidays']])->assertRedirect();
        $holidays = CalendarConnection::where('external_calendar_id', 'holidays')->sole();
        $this->assertFalse(CalendarProviders::for($holidays)->isWritable($holidays));
        $this->assertSame('access-1', $holidays->access_token);

        // Google refusing the calendar list: back to the calendar with a message.
        PendingConnection::forget();
        $this->get(route('calendar.connections.google.callback', ['code' => 'x']))
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHas('error', 'Could not load your Google calendars.');
        $this->assertNull(PendingConnection::current());
    }

    public function test_google_reminders_use_the_event_start_and_keep_their_keys(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-05T08:50:00+01:00'));
        $this->connection->update(['token_expires_at' => now()->addHour()]);
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*' => Http::response(['items' => [
            ['id' => 'g1', 'summary' => 'Standup', 'start' => ['dateTime' => '2026-11-05T09:00:00+01:00'], 'end' => ['dateTime' => '2026-11-05T09:15:00+01:00']],
            ['id' => 'g2', 'summary' => 'Holiday', 'start' => ['date' => '2026-11-05'], 'end' => ['date' => '2026-11-06']],
        ]])]);
        // A disconnected-looking Google row (no token) is skipped without a request.
        CalendarConnection::factory()->create(['access_token' => null]);

        $this->artisan('app:send-calendar-reminders')->assertSuccessful();
        $this->artisan('app:send-calendar-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->connection->user, CalendarReminderNotification::class, 1);
        $this->assertDatabaseHas('sent_reminders', [
            'calendar_connection_id' => $this->connection->id,
            'reminder_key' => 'event:g1:'.Carbon::parse('2026-11-05T09:00:00+01:00')->timestamp,
        ]);
        $this->assertSame(1, \DB::table('sent_reminders')->count());
    }

    public function test_google_events_on_the_calendar_page_look_as_before(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*' => Http::response(['items' => [
            ['id' => 'g1', 'summary' => 'Standup', 'htmlLink' => 'https://cal/g1', 'start' => ['dateTime' => '2026-11-05T09:00:00+01:00'], 'end' => ['dateTime' => '2026-11-05T09:15:00+01:00']],
        ]])]);
        $this->connection->user->update(['timezone' => null]);

        $this->actingAs($this->connection->user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.type', 'external_event')
                ->where('events.0.provider', 'google')
                ->where('events.0.provider_label', 'Google Calendar')
                ->where('events.0.provider_badge', 'G')
                ->where('events.0.start_date', '2026-11-05')
                ->where('events.0.start_time', '09:00')
                ->where('events.0.due_time', '09:15')
                ->where('events.0.color', '#33B679')
                ->where('events.0.external_url', 'https://cal/g1')
                ->where('events.0.read_only', false)
                ->where('events.0.account_email', $this->connection->account_email)
            );
    }

    public function test_google_tasks_stay_google_tasks(): void
    {
        $tasks = CalendarConnection::factory()->tasks()->create(['user_id' => $this->connection->user_id]);
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*' => Http::response(['items' => []]),
            'tasks.googleapis.com/*' => Http::response(['items' => [
                ['id' => 't1', 'title' => 'Pay rent', 'due' => '2026-11-05T00:00:00.000Z', 'status' => 'needsAction'],
            ]]),
        ]);

        $this->actingAs($this->connection->user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 1)
                ->where('events.0.type', 'google_task')
                ->where('events.0.connection_id', $tasks->id)
                ->where('events.0.start_date', '2026-11-05')
                ->where('events.0.start_time', null)
            );
    }

    public function test_create_and_update_send_googles_shapes(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*' => Http::response([
            'id' => 'new', 'summary' => 'Focus', 'start' => ['date' => '2026-11-06'], 'end' => ['date' => '2026-11-08'],
        ])]);

        $created = $this->google->createEvent($this->connection, [
            'title' => 'Focus', 'location' => 'Desk', 'start_date' => '2026-11-06', 'end_date' => '2026-11-07',
        ]);
        $this->assertSame(['new', '2026-11-06', '2026-11-07'], [$created['id'], $created['start_date'], $created['end_date']]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['start'] === ['date' => '2026-11-06']
            && $r['end'] === ['date' => '2026-11-08']
            && $r['location'] === 'Desk');

        $this->google->updateEvent($this->connection, 'abc_1', [
            'start_date' => '2026-11-06', 'start_time' => '09:00', 'end_time' => '08:00', 'timezone' => 'Europe/Berlin',
        ]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/events/abc_1')
            && $r['start'] === ['dateTime' => '2026-11-06T09:00:00+01:00', 'timeZone' => 'Europe/Berlin']
            // An end before the start becomes start + 30 minutes.
            && $r['end'] === ['dateTime' => '2026-11-06T09:30:00+01:00', 'timeZone' => 'Europe/Berlin']
            && ! isset($r['summary']));
    }

    public function test_rejected_writes_throw_a_user_facing_error(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*' => Http::response(['error' => 'forbidden'], 403)]);

        $this->actingAs($this->connection->user)
            ->from(route('calendar.index'))
            ->post(route('calendar.connections.events.create', $this->connection), ['title' => 'X', 'date' => '2026-11-06'])
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHasErrors(['calendar' => 'Google rejected the event. Try again, or reconnect this calendar.']);
    }

    public function test_the_google_picker_flow_creates_calendar_and_tasks_connections(): void
    {
        $user = User::factory()->create();
        $existing = CalendarConnection::factory()->create(['user_id' => $user->id, 'account_email' => 'me@gmail.com', 'external_calendar_id' => 'old']);
        // A CalDAV account with the same login must not receive Google's tokens.
        $dav = CalendarConnection::factory()->provider('caldav')->create(['user_id' => $user->id, 'account_email' => 'me@gmail.com']);

        $this->actingAs($user);
        (new PendingConnection(
            provider: 'google',
            accountEmail: 'me@gmail.com',
            accountName: 'Me',
            calendars: [['id' => 'primary@gmail.com', 'name' => 'Me', 'color' => '#4285F4', 'primary' => true, 'read_only' => false]],
            taskLists: [['id' => 'list-1', 'title' => 'My Tasks']],
            secrets: ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600],
        ))->put();

        $this->getJson(route('calendar.connections.pending'))
            ->assertOk()
            ->assertJsonPath('pending.provider_label', 'Google Calendar')
            ->assertJsonPath('pending.calendars.0.name', 'Me')
            ->assertJsonPath('pending.task_lists.0.title', 'My Tasks');

        $this->post(route('calendar.connections.store'), [
            'external_calendar_ids' => ['primary@gmail.com'],
            'external_task_list_ids' => ['list-1'],
        ])->assertRedirect(route('calendar.index'));

        $calendar = CalendarConnection::where('external_calendar_id', 'primary@gmail.com')->sole();
        $this->assertSame(['google', 'calendar', 'Me', '#4285F4', 'new-access', 'new-refresh'], [
            $calendar->provider, $calendar->kind, $calendar->external_calendar_name, $calendar->external_calendar_color, $calendar->access_token, $calendar->refresh_token,
        ]);
        $this->assertSame('tasks', CalendarConnection::where('external_calendar_id', 'list-1')->sole()->kind);
        $this->assertSame('new-access', $existing->fresh()->access_token);
        $this->assertNull($dav->fresh()->access_token);
    }

    public function test_registry_returns_the_google_adapter_for_google_rows(): void
    {
        $this->assertInstanceOf(GoogleCalendarProvider::class, CalendarProviders::for($this->connection));
        $this->assertTrue(CalendarProviders::for($this->connection)->isWritable($this->connection));
    }
}
