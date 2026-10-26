<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\CalendarReadOnly;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\EventInput;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Calendar\PendingConnection;
use App\Support\Calendar\Providers\GoogleCalendarProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The provider registry, the generic "manual" connect route for form / url providers, the
 * shared picker flow (PendingConnection) and the connection columns other providers need.
 */
class CalendarProvidersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        FakeCalendarProvider::reset();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        parent::tearDown();
    }

    public function test_registry_resolves_providers_and_builds_the_catalog(): void
    {
        $google = CalendarConnection::factory()->create(['user_id' => $this->user->id]);
        $this->assertInstanceOf(GoogleCalendarProvider::class, CalendarProviders::for($google));
        $this->assertTrue(CalendarProviders::has('google'));
        $this->assertFalse(CalendarProviders::has('nope'));

        $catalog = collect(CalendarProviders::catalog())->keyBy('key');
        $this->assertSame([
            'key' => 'google',
            'label' => 'Google Calendar',
            'badge' => 'G',
            'color' => '#4285F4',
            'connect_mode' => 'oauth',
            'redirect_url' => route('calendar.connections.google.redirect'),
            'fields' => [],
            'capabilities' => ['write' => true, 'tasks' => true, 'conferencing' => true],
        ], $catalog['google']);
        $this->assertSame('form', $catalog['fake']['connect_mode']);
        $this->assertNull($catalog['fake']['redirect_url']);
        $this->assertSame(['name' => 'server_url', 'label' => 'Server URL', 'type' => 'url', 'help' => 'The CalDAV address.', 'placeholder' => 'https://dav.example.com', 'required' => true], $catalog['fake']['fields'][0]);

        $this->expectExceptionObject(new CalendarSyncFailed('This calendar provider is no longer supported.'));
        CalendarProviders::make('nope');
    }

    public function test_writable_refuses_read_only_providers_and_tasks_lists(): void
    {
        $fake = CalendarConnection::factory()->provider('fake')->create(['user_id' => $this->user->id]);
        $this->assertInstanceOf(FakeCalendarProvider::class, CalendarProviders::writable($fake));

        $tasks = CalendarConnection::factory()->tasks()->create(['user_id' => $this->user->id]);
        try {
            CalendarProviders::writable($tasks);
            $this->fail('A Google Tasks list is not an event calendar.');
        } catch (CalendarReadOnly) {
        }

        FakeCalendarProvider::$writable = false;
        $this->expectException(CalendarReadOnly::class);
        CalendarProviders::writable($fake);
    }

    public function test_manual_connect_validates_the_declared_fields(): void
    {
        $this->actingAs($this->user)
            ->from(route('calendar.index'))
            ->post(route('calendar.connections.manual.store', 'fake'), ['server_url' => 'not a url', 'username' => ''])
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHasErrors(['server_url', 'username', 'password']);

        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_manual_connect_creates_the_connection_with_encrypted_credentials(): void
    {
        $this->actingAs($this->user)
            ->post(route('calendar.connections.manual.store', 'fake'), [
                'server_url' => 'https://dav.example.com/',
                'username' => 'me@example.com',
                'password' => 'app-secret',
                'ignored' => 'dropped',
            ])
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHas('success', 'Calendar connected.');

        $connection = CalendarConnection::sole();
        $this->assertSame('fake', $connection->provider);
        $this->assertSame('me@example.com', $connection->account_email);
        $this->assertSame('https://dav.example.com/', $connection->server_url);
        $this->assertSame(['username' => 'me@example.com', 'password' => 'app-secret'], $connection->credentials);
        $this->assertArrayNotHasKey('credentials', $connection->toArray());

        $raw = (string) \DB::table('calendar_connections')->value('credentials');
        $this->assertStringNotContainsString('app-secret', $raw);

        // Connecting again reuses the row (also after a disconnect).
        $connection->delete();
        $this->actingAs($this->user)->post(route('calendar.connections.manual.store', 'fake'), [
            'server_url' => 'https://dav.example.com/', 'username' => 'me@example.com', 'password' => 'new-secret',
        ])->assertRedirect();
        $this->assertSame(1, CalendarConnection::withTrashed()->count());
        $this->assertSame('new-secret', CalendarConnection::sole()->credentials['password']);
    }

    public function test_secret_fields_are_not_flashed_back_after_an_error(): void
    {
        $this->actingAs($this->user)
            ->from(route('calendar.index'))
            ->post(route('calendar.connections.manual.store', 'fake'), [
                'server_url' => 'https://dav.example.com/?token=secret-token',
                'username' => 'me',
                'password' => 'wrong',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionHasInput('username', 'me')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.server_url');
    }

    public function test_manual_connect_shows_provider_errors(): void
    {
        $as = $this->actingAs($this->user)->from(route('calendar.index'));
        $fields = ['server_url' => 'https://dav.example.com', 'username' => 'me'];

        $as->post(route('calendar.connections.manual.store', 'fake'), $fields + ['password' => 'wrong'])
            ->assertSessionHasErrors(['password' => 'The server refused this username or password.']);
        $as->post(route('calendar.connections.manual.store', 'fake'), $fields + ['password' => 'down'])
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHasErrors(['calendar' => 'The server did not answer.']);
        $as->postJson(route('calendar.connections.manual.store', 'fake'), $fields + ['password' => 'down'])
            ->assertStatus(422)->assertJsonPath('message', 'The server did not answer.');

        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_manual_connect_only_for_form_and_url_providers(): void
    {
        $as = $this->actingAs($this->user);
        $as->post(route('calendar.connections.manual.store', 'google'), [])->assertNotFound();
        $as->post(route('calendar.connections.manual.store', 'unknown'), [])->assertNotFound();
        $this->post(route('logout'));
        $this->post(route('calendar.connections.manual.store', 'fake'), [])->assertRedirect(route('login'));
    }

    public function test_manual_connect_can_open_the_picker_and_store_the_picked_calendars(): void
    {
        FakeCalendarProvider::$pick = true;
        $as = $this->actingAs($this->user);

        $as->post(route('calendar.connections.manual.store', 'fake'), [
            'server_url' => 'https://dav.example.com', 'username' => 'me', 'password' => 'app-secret',
        ])->assertRedirect(route('calendar.index', ['picking' => 'fake']));

        // The session keeps the secrets encrypted; the picker never sees them.
        $this->assertStringNotContainsString('app-secret', json_encode(session(PendingConnection::SESSION_KEY)));
        $as->getJson(route('calendar.connections.pending'))
            ->assertOk()
            ->assertJsonPath('pending.provider', 'fake')
            ->assertJsonPath('pending.provider_label', 'Fake DAV')
            ->assertJsonPath('pending.calendars.1.name', 'Work')
            ->assertJsonMissingPath('pending.secrets');
        $this->assertStringNotContainsString('app-secret', $as->getJson(route('calendar.connections.pending'))->getContent());

        $as->post(route('calendar.connections.store'), ['external_calendar_ids' => ['/cal/work/', '/cal/unknown/']])
            ->assertRedirect(route('calendar.index'));

        $connection = CalendarConnection::sole();
        $this->assertSame(['fake', 'me', '/cal/work/', 'Work', 'https://dav.example.com'], [
            $connection->provider, $connection->account_email, $connection->external_calendar_id, $connection->external_calendar_name, $connection->server_url,
        ]);
        $this->assertSame('app-secret', $connection->credentials['password']);
        $this->assertNull($connection->access_token);
        $this->assertNull(session(PendingConnection::SESSION_KEY));
    }

    public function test_two_ics_style_links_of_one_user_coexist_but_not_twice_the_same(): void
    {
        $make = fn (string $externalId) => CalendarConnection::factory()->provider('fake')->create([
            'user_id' => $this->user->id,
            'account_email' => 'ICS link',
            'external_calendar_id' => $externalId,
        ]);

        $make(sha1('https://a.example/cal.ics?token=1'));
        $make(sha1('https://b.example/cal.ics?token=2'));
        $this->assertSame(2, CalendarConnection::count());

        $this->expectException(QueryException::class);
        $make(sha1('https://a.example/cal.ics?token=1'));
    }

    public function test_disconnect_clears_credentials(): void
    {
        $connection = CalendarConnection::factory()->provider('fake')->create([
            'user_id' => $this->user->id,
            'credentials' => ['password' => 'app-secret'],
        ]);

        $this->actingAs($this->user)->delete(route('calendar.connections.destroy', $connection))->assertRedirect();

        $trashed = CalendarConnection::withTrashed()->find($connection->id);
        $this->assertTrue($trashed->trashed());
        $this->assertNull($trashed->credentials);
    }

    public function test_event_input_applies_the_shared_write_rules(): void
    {
        $allDay = EventInput::from(['start_date' => '2026-11-06', 'end_date' => '2026-11-04', 'timezone' => 'Not/AZone']);
        $this->assertTrue($allDay->isAllDay());
        $this->assertSame('2026-11-06', $allDay->endDate()); // an end before the start is clamped
        $this->assertSame('2026-11-07', $allDay->endDateExclusive());
        $this->assertSame(config('app.timezone'), $allDay->timezone());

        $timed = EventInput::from(['start_date' => '2026-11-06', 'start_time' => '23:45', 'timezone' => 'Europe/Berlin']);
        $this->assertSame('2026-11-06T23:45:00+01:00', $timed->start()->toRfc3339String());
        $this->assertSame('2026-11-07T00:15:00+01:00', $timed->end()->toRfc3339String());
        $this->assertFalse(EventInput::from(['title' => 'X'])->changesTimes());
        $this->assertFalse(EventInput::from(['title' => 'X'])->has('description'));

        $event = NormalizedEvent::make(['id' => 5, 'start_date' => '2026-11-06', 'extra' => 'dropped']);
        $this->assertSame(NormalizedEvent::KEYS, array_keys($event));
        $this->assertSame(['5', '(untitled)', '2026-11-06'], [$event['id'], $event['title'], $event['end_date']]);
        $this->assertNull(NormalizedEvent::startsAt($event));
    }
}
