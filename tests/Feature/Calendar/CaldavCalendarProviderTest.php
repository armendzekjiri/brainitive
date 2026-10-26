<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\Caldav\CaldavClient;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\Providers\CaldavCalendarProvider;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Apple iCloud / CalDAV" (BRAI-141) against a fake CalDAV server (Http::fake + XML fixtures in
 * tests/Fixtures/calendar/caldav): discovery + picker, calendar-query, writes with ETags,
 * occurrence overrides / EXDATEs, error mapping and where the app password may go.
 */
class CaldavCalendarProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://93.184.216.34/';

    private const CALENDAR = 'https://93.184.216.34/123456/calendars/home/';

    private User $user;

    /** @var list<HttpRequest> */
    private array $requests = [];

    /** @var array<string, mixed> */
    private array $routes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['timezone' => 'Europe/Berlin']);
    }

    private function fixture(string $name, array $replace = []): string
    {
        $text = (string) file_get_contents(base_path("tests/Fixtures/calendar/caldav/{$name}"));
        foreach ($replace as $key => $value) {
            $text = str_replace('{{'.$key.'}}', $value, $text);
        }

        return $text;
    }

    private function report(): string
    {
        return $this->fixture('report.xml', [
            'one' => htmlspecialchars($this->fixture('one.ics'), ENT_XML1),
            'series' => htmlspecialchars($this->fixture('series.ics'), ENT_XML1),
        ]);
    }

    /**
     * A fake CalDAV server. Keys: "METHOD /path" (host 93.184.216.34) or "METHOD https://host/path";
     * values: a response or a closure (HttpRequest → response). Anything else is a 404.
     *
     * @param  array<string, mixed>  $routes
     */
    private function server(array $routes): void
    {
        $fresh = ! isset($this->routes);
        $this->routes = $routes;
        if (! $fresh) {
            return; // the fake below reads the new routes
        }
        Http::fake(function (HttpRequest $request) {
            $this->requests[] = $request;
            $parts = parse_url($request->url());
            $path = $parts['path'] ?? '/';
            $answer = $this->routes[$request->method().' '.$parts['scheme'].'://'.$parts['host'].$path]
                ?? ($parts['host'] === '93.184.216.34' ? ($this->routes[$request->method().' '.$path] ?? null) : null);

            return $answer instanceof Closure || $answer instanceof ResponseSequence ? $answer($request) : ($answer ?? Http::response('not here', 404));
        });
    }

    private function discovery(array $extra = []): array
    {
        return $extra + [
            'PROPFIND /' => Http::response($this->fixture('principal.xml'), 207),
            'PROPFIND /123456/principal/' => Http::response($this->fixture('home-set.xml', ['home' => 'https://93.184.216.34:443/123456/calendars/']), 207),
            'PROPFIND /123456/calendars/' => Http::response($this->fixture('calendars.xml'), 207),
        ];
    }

    private function connection(array $credentials = []): CalendarConnection
    {
        return CalendarConnection::factory()->provider('caldav')->create([
            'user_id' => $this->user->id,
            'account_email' => 'me@icloud.com',
            'external_calendar_id' => self::CALENDAR,
            'external_calendar_name' => 'Home',
            'server_url' => self::SERVER,
            'credentials' => ['username' => 'me@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'] + $credentials,
        ]);
    }

    private function provider(): CaldavCalendarProvider
    {
        return CalendarProviders::make('caldav');
    }

    private function id(string $path, ?string $key = null): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=').($key ? '~'.$key : '');
    }

    private function connect(array $input)
    {
        return $this->actingAs($this->user)->post(route('calendar.connections.manual.store', 'caldav'), $input + [
            'server_url' => self::SERVER, 'username' => 'me@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop',
        ]);
    }

    public function test_the_catalog_offers_the_caldav_form(): void
    {
        $caldav = collect(CalendarProviders::catalog())->firstWhere('key', 'caldav');
        $this->assertSame('Apple iCloud / CalDAV', $caldav['label']);
        $this->assertSame('form', $caldav['connect_mode']);
        $this->assertSame(['write' => true, 'tasks' => false], $caldav['capabilities']);
        $this->assertSame(['server_url', 'username', 'password'], array_column($caldav['fields'], 'name'));
        $this->assertFalse($caldav['fields'][0]['required']);
        $this->assertSame('https://caldav.icloud.com', $caldav['fields'][0]['placeholder']);
        $this->assertSame('password', $caldav['fields'][2]['type']);
        $this->assertStringContainsString('appleid.apple.com → Sign-In and Security', $caldav['fields'][2]['help']);
    }

    public function test_connect_discovers_the_calendars_and_the_picker_creates_the_rows(): void
    {
        $this->server($this->discovery());

        $this->connect([])->assertSessionHasNoErrors()->assertRedirect(route('calendar.index', ['picking' => 'caldav']));

        $pending = $this->actingAs($this->user)->getJson(route('calendar.connections.pending'))->assertOk();
        $this->assertStringNotContainsString('abcd-efgh', $pending->getContent());
        $pending->assertJsonPath('pending.provider', 'caldav')
            ->assertJsonPath('pending.provider_label', 'Apple iCloud / CalDAV')
            ->assertJsonPath('pending.account_email', 'me@icloud.com')
            ->assertJsonPath('pending.account_name', 'Erin Example')
            ->assertJsonPath('pending.calendars', [
                ['id' => 'https://93.184.216.34:443/123456/calendars/home/', 'name' => 'Home', 'color' => '#FF2968', 'primary' => true, 'read_only' => false],
                ['id' => 'https://93.184.216.34:443/123456/calendars/shared%20team/', 'name' => 'shared team', 'color' => null, 'primary' => false, 'read_only' => true],
            ]); // the tasks list and the inbox aren't event calendars

        // discovery sent the app password (basic auth) and the WebDAV headers
        $this->assertCount(3, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertSame(['Basic '.base64_encode('me@icloud.com:abcd-efgh-ijkl-mnop')], $request->header('Authorization'));
        }
        $this->assertSame(['0'], $this->requests[0]->header('Depth'));
        $this->assertSame(['1'], $this->requests[2]->header('Depth'));
        $this->assertStringContainsString('current-user-principal', $this->requests[0]->body());

        $this->actingAs($this->user)->post(route('calendar.connections.store'), ['external_calendar_ids' => [
            'https://93.184.216.34:443/123456/calendars/home/', 'https://93.184.216.34:443/123456/calendars/shared%20team/',
        ]])->assertRedirect(route('calendar.index'));

        $rows = CalendarConnection::where('user_id', $this->user->id)->where('provider', 'caldav')->orderBy('id')->get();
        $this->assertSame(['Home', 'shared team'], $rows->pluck('external_calendar_name')->all());
        $this->assertSame(self::SERVER, $rows[0]->server_url);
        $this->assertSame(['username' => 'me@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'], $rows[0]->credentials);
        $this->assertTrue($this->provider()->isWritable($rows[0]));
        $this->assertFalse($this->provider()->isWritable($rows[1])); // read-only shared calendar
        $this->assertStringNotContainsString('abcd-efgh', (string) DB::table('calendar_connections')->where('id', $rows[0]->id)->value('credentials'));
    }

    public function test_discovery_falls_back_to_well_known_and_follows_a_same_origin_redirect(): void
    {
        $this->server($this->discovery([
            'PROPFIND /' => Http::response('', 405),
            'PROPFIND /.well-known/caldav' => Http::response('', 301, ['Location' => '/remote.php/dav/']),
            'PROPFIND /remote.php/dav/' => Http::response($this->fixture('principal.xml'), 207),
        ]));

        $this->connect(['server_url' => 'https://93.184.216.34'])->assertSessionHasNoErrors()->assertRedirect(route('calendar.index', ['picking' => 'caldav']));
        $redirected = collect($this->requests)->first(fn (HttpRequest $r) => str_ends_with($r->url(), '/remote.php/dav/'));
        $this->assertNotEmpty($redirected->header('Authorization')); // same origin: still signed in
    }

    public function test_a_wrong_password_is_a_field_error(): void
    {
        $this->server(['PROPFIND /' => Http::response('', 401)]);

        $this->connect(['password' => 'wrong'])->assertSessionHasErrors(['password' => 'Check your username and app password.']);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame(0, CalendarConnection::where('provider', 'caldav')->count());
    }

    public function test_the_app_password_never_goes_to_another_host(): void
    {
        // a redirect to another origin: followed without credentials, and its answer isn't trusted
        $this->server([
            'PROPFIND /' => Http::response('', 302, ['Location' => 'https://93.184.216.35/dav/']),
            'PROPFIND https://93.184.216.35/dav/' => Http::response($this->fixture('principal.xml'), 207),
        ]);
        $this->connect([])->assertSessionHasErrors(['server_url' => 'The calendar server sent us to another host. This server isn’t supported.']);
        $this->assertSame([], $this->requests[1]->header('Authorization'));
        $this->assertCount(2, $this->requests);

        // a calendar home on another site isn't asked at all
        $this->requests = [];
        $this->server($this->discovery([
            'PROPFIND /123456/principal/' => Http::response($this->fixture('home-set.xml', ['home' => 'https://93.184.216.99/123456/calendars/']), 207),
        ]));
        $this->connect([])->assertSessionHasErrors(['server_url' => 'The calendar server sent us to another host. This server isn’t supported.']);
        $this->assertCount(2, $this->requests);
    }

    public function test_private_servers_are_refused_without_a_request(): void
    {
        $this->server([]);
        $this->connect(['server_url' => 'http://10.0.0.1/dav/'])->assertSessionHasErrors('server_url');
        $this->connect(['server_url' => 'https://169.254.169.254/'])->assertSessionHasErrors('server_url');
        // the app password never travels over plain http
        $this->connect(['server_url' => 'http://93.184.216.34/dav/'])->assertSessionHasErrors(['server_url' => 'Use an https:// address: your password must not be sent unencrypted.']);
        $this->assertSame([], $this->requests);

        // nor one the server points to
        $this->server($this->discovery([
            'PROPFIND /123456/principal/' => Http::response($this->fixture('home-set.xml', ['home' => 'http://127.0.0.1/123456/calendars/']), 207),
        ]));
        $this->connect([])->assertSessionHasErrors('server_url');
        $this->assertCount(2, $this->requests);
    }

    public function test_list_events_reads_the_range_and_expands_series(): void
    {
        $this->server(['REPORT /123456/calendars/home/' => Http::response($this->report(), 207)]);

        $events = $this->provider()->listEvents($this->connection(), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), 'Europe/Berlin');

        $this->assertSame([
            'Dentist 2026-11-04 10:00',
            'R&D sync 2026-11-02 09:00', 'R&D sync 2026-11-09 09:00', 'R&D sync 2026-11-16 09:00', 'R&D sync 2026-11-23 09:00', 'R&D sync 2026-11-30 09:00',
        ], array_map(fn ($e) => "{$e['title']} {$e['start_date']} {$e['start_time']}", $events)); // the object outside the calendar is ignored
        $this->assertSame($this->id('/123456/calendars/home/one.ics'), $events[0]['id']);
        $this->assertSame($this->id('/123456/calendars/home/series.ics', '20261102T080000Z'), $events[1]['id']);
        $this->assertSame('Main St 1', $events[0]['location']);

        $report = $this->requests[0];
        $this->assertSame('REPORT', $report->method());
        $this->assertSame(['1'], $report->header('Depth'));
        $this->assertStringContainsString('<c:time-range start="20261030T230000Z" end="20261201T230000Z"/>', $report->body());
    }

    public function test_the_page_shows_caldav_events_and_a_401_becomes_a_sync_error(): void
    {
        $connection = $this->connection();
        $this->server(['REPORT /123456/calendars/home/' => Http::sequence()->push($this->report(), 207)->push('', 401)]);
        $page = fn () => $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']));

        $page()->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('events', fn ($events) => collect($events)->where('provider', 'caldav')->where('title', 'Dentist')->where('read_only', false)->count() === 1)
            ->where('connections.0.sync_error', null)
        );
        $page()->assertOk()->assertInertia(fn (Assert $p) => $p->where('connections.0.sync_error', 'Check your username and app password.'));
        $this->assertTrue($connection->fresh()->is_active); // not switched off: fixing the password is enough
    }

    public function test_get_event_and_ids_outside_the_calendar(): void
    {
        $connection = $this->connection();
        $this->server([
            'GET /123456/calendars/home/one.ics' => Http::response($this->fixture('one.ics'), 200, ['ETag' => '"e1"']),
            'GET /123456/calendars/home/series.ics' => Http::response($this->fixture('series.ics'), 200, ['ETag' => '"s1"']),
        ]);

        $event = $this->provider()->getEvent($connection, $this->id('/123456/calendars/home/one.ics'), 'UTC');
        $this->assertSame(['Dentist', '2026-11-04', '09:00', '10:00', 'UTC'], [$event['title'], $event['start_date'], $event['start_time'], $event['end_time'], $event['timezone']]);
        $occurrence = $this->provider()->getEvent($connection, $this->id('/123456/calendars/home/series.ics', '20261123T080000Z'), 'Europe/Berlin');
        $this->assertSame('2026-11-23', $occurrence['start_date']);
        $this->assertNull($this->provider()->getEvent($connection, $this->id('/123456/calendars/home/missing.ics'), null));
        $this->assertCount(3, $this->requests);

        foreach ([
            $this->id('/123456/other/elsewhere.ics'),
            $this->id('/123456/calendars/home/../../other/x.ics'),
            $this->id('/123456/calendars/home/'),
            $this->id('//evil.example/x.ics'),
            'not base64!',
        ] as $forged) {
            $this->assertNull($this->provider()->getEvent($connection, $forged, null), $forged);
        }
        $this->assertCount(3, $this->requests);
    }

    public function test_create_puts_a_new_object(): void
    {
        Http::fake(function (HttpRequest $request) { // any PUT: the path holds a new uuid
            $this->requests[] = $request;

            return Http::response('', 201);
        });

        $connection = $this->connection();
        $event = $this->provider()->createEvent($connection, [
            'title' => 'Lunch, with team', 'location' => 'Canteen', 'start_date' => '2026-11-06', 'start_time' => '12:00', 'end_time' => '13:00', 'timezone' => 'Europe/Berlin',
        ]);

        $put = $this->requests[0];
        $this->assertSame('PUT', $put->method());
        $this->assertMatchesRegularExpression('~^https://93\.184\.216\.34/123456/calendars/home/[0-9a-f-]{36}\.ics$~', $put->url());
        $this->assertSame(['*'], $put->header('If-None-Match'));
        $this->assertStringStartsWith('text/calendar', $put->header('Content-Type')[0]);
        $this->assertStringContainsString("SUMMARY:Lunch\\, with team\r\n", $put->body());
        $this->assertStringContainsString("DTSTART:20261106T110000Z\r\nDTEND:20261106T120000Z\r\n", $put->body());
        $this->assertStringContainsString("PRODID:-//Brainitive//Calendar//EN\r\n", $put->body());

        $this->assertSame(['Lunch, with team', '2026-11-06', '12:00', '13:00', 'Europe/Berlin', 'Canteen'], [$event['title'], $event['start_date'], $event['start_time'], $event['end_time'], $event['timezone'], $event['location']]);
        $this->assertSame($this->id((string) parse_url($put->url(), PHP_URL_PATH)), $event['id']);

        // all-day: DATE values, exclusive end
        $this->requests = [];
        $this->provider()->createEvent($connection, ['title' => 'Trip', 'start_date' => '2026-11-10', 'end_date' => '2026-11-12']);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261110\r\nDTEND;VALUE=DATE:20261113\r\n", $this->requests[0]->body());
    }

    public function test_update_uses_if_match_and_keeps_what_it_does_not_change(): void
    {
        $connection = $this->connection();
        $this->server([
            'GET /123456/calendars/home/one.ics' => Http::response($this->fixture('one.ics'), 200, ['ETag' => '"e1"']),
            'PUT /123456/calendars/home/one.ics' => Http::response('', 204),
        ]);

        $this->actingAs($this->user)->patch(route('calendar.connections.events.update', [$connection, $this->id('/123456/calendars/home/one.ics')]), [
            'title' => 'Dentist (moved)', 'start_date' => '2026-11-05', 'start_time' => '15:00', 'end_time' => '15:45', 'timezone' => 'Europe/Berlin',
        ])->assertSessionHasNoErrors();

        $put = $this->requests[1];
        $this->assertSame(['"e1"'], $put->header('If-Match'));
        $body = $put->body();
        $this->assertStringContainsString("SUMMARY:Dentist (moved)\r\n", $body);
        $this->assertStringContainsString("DTSTART:20261105T140000Z\r\nDTEND:20261105T144500Z\r\n", $body);
        $this->assertStringContainsString("LOCATION:Main St 1\r\n", $body);
        $this->assertStringContainsString("X-APPLE-TRAVEL-ADVISORY-BEHAVIOR:AUTOMATIC\r\n", $body);
        $this->assertStringContainsString("BEGIN:VALARM\r\n", $body);
        $this->assertStringContainsString("SEQUENCE:1\r\n", $body);
        $this->assertStringNotContainsString('TZID=Europe/Berlin:20261104', $body);
    }

    public function test_changing_one_occurrence_adds_an_override_and_keeps_the_series(): void
    {
        $this->server([
            'GET /123456/calendars/home/series.ics' => Http::response($this->fixture('series.ics'), 200, ['ETag' => '"s1"']),
            'PUT /123456/calendars/home/series.ics' => Http::response('', 204),
        ]);
        $id = $this->id('/123456/calendars/home/series.ics', '20261116T080000Z');

        $event = $this->provider()->updateEvent($this->connection(), $id, ['start_date' => '2026-11-17', 'start_time' => '14:00', 'end_time' => '14:30', 'timezone' => 'Europe/Berlin']);

        $body = $this->requests[1]->body();
        $this->assertSame(1, substr_count($body, 'RRULE:FREQ=WEEKLY;BYDAY=MO'));
        $this->assertStringContainsString("RECURRENCE-ID;TZID=Europe/Berlin:20261116T090000\r\n", $body);
        $this->assertStringContainsString("DTSTART:20261117T130000Z\r\nDTEND:20261117T133000Z\r\n", $body);
        $this->assertSame(2, substr_count($body, 'SUMMARY:R&D sync'));
        $this->assertSame([$id, '2026-11-17', '14:00', 'R&D sync'], [$event['id'], $event['start_date'], $event['start_time'], $event['title']]);
    }

    public function test_delete_uses_if_match_and_an_occurrence_becomes_an_exdate(): void
    {
        $connection = $this->connection();
        $this->server([
            'GET /123456/calendars/home/one.ics' => Http::response($this->fixture('one.ics'), 200, ['ETag' => '"e1"']),
            'DELETE /123456/calendars/home/one.ics' => Http::response('', 204),
            'GET /123456/calendars/home/series.ics' => Http::response($this->fixture('series.ics'), 200, ['ETag' => '"s1"']),
            'PUT /123456/calendars/home/series.ics' => Http::response('', 204),
        ]);

        $this->actingAs($this->user)->delete(route('calendar.connections.events.destroy', [$connection, $this->id('/123456/calendars/home/one.ics')]))->assertSessionHasNoErrors();
        $this->assertSame('DELETE', $this->requests[1]->method());
        $this->assertSame(['"e1"'], $this->requests[1]->header('If-Match'));

        $this->provider()->deleteEvent($connection, $this->id('/123456/calendars/home/series.ics', '20261123T080000Z'));
        $this->assertSame(['"s1"'], $this->requests[3]->header('If-Match'));
        $this->assertStringContainsString("EXDATE;TZID=Europe/Berlin:20261123T090000\r\n", $this->requests[3]->body());

        // already gone: nothing else to do
        $this->provider()->deleteEvent($connection, $this->id('/123456/calendars/home/gone.ics'));
        $this->assertCount(5, $this->requests);
    }

    public function test_server_refusals_have_clear_messages(): void
    {
        $connection = $this->connection();
        $this->server([
            'GET /123456/calendars/home/one.ics' => Http::response($this->fixture('one.ics'), 200, ['ETag' => '"e1"']),
            'PUT /123456/calendars/home/one.ics' => Http::sequence()->push('', 412)->push('', 403),
        ]);
        $id = $this->id('/123456/calendars/home/one.ics');

        foreach (['This event was changed in another app. Reload the calendar and try again.', 'The calendar server didn’t allow this. Check that you may change this calendar.'] as $message) {
            try {
                $this->provider()->updateEvent($connection, $id, ['title' => 'X']);
                $this->fail('expected CalendarSyncFailed');
            } catch (CalendarSyncFailed $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->actingAs($this->user)->patchJson(route('calendar.connections.events.update', [$connection, $this->id('/123456/calendars/home/missing.ics')]), ['title' => 'X'])
            ->assertStatus(422)->assertJsonPath('message', 'This event no longer exists.');
    }

    public function test_a_read_only_calendar_refuses_writes_without_asking_the_server(): void
    {
        $connection = $this->connection(['read_only' => true]);
        $this->server([]);
        $as = $this->actingAs($this->user);

        $as->postJson(route('calendar.connections.events.create', $connection), ['title' => 'X', 'date' => '2026-11-06'])->assertStatus(422);
        $as->patchJson(route('calendar.connections.events.update', [$connection, $this->id('/123456/calendars/home/one.ics')]), ['title' => 'X'])
            ->assertStatus(422)->assertJsonPath('message', 'This calendar is read-only.');
        $as->deleteJson(route('calendar.connections.events.destroy', [$connection, $this->id('/123456/calendars/home/one.ics')]))->assertStatus(422);
        $this->assertSame([], $this->requests);
    }

    public function test_a_username_too_long_for_the_account_column_is_a_field_error(): void
    {
        $this->server($this->discovery());
        $this->connect(['username' => str_repeat('a', 250)])->assertSessionHasErrors(['username' => 'This username is too long.']);
    }

    public function test_update_without_an_etag_sends_no_precondition(): void
    {
        $this->server([
            'GET /123456/calendars/home/one.ics' => Http::response($this->fixture('one.ics')), // no ETag header
            'PUT /123456/calendars/home/one.ics' => Http::response('', 204),
        ]);
        $this->provider()->updateEvent($this->connection(), $this->id('/123456/calendars/home/one.ics'), ['title' => 'Dentist!']);

        $this->assertSame([], $this->requests[1]->header('If-Match'));
        $this->assertSame([], $this->requests[1]->header('If-None-Match'));
    }

    public function test_moving_a_whole_series_is_refused_but_its_title_can_change(): void
    {
        $this->server([
            'GET /123456/calendars/home/series.ics' => Http::response($this->fixture('series.ics'), 200, ['ETag' => '"s1"']),
            'PUT /123456/calendars/home/series.ics' => Http::response('', 204),
        ]);
        $id = $this->id('/123456/calendars/home/series.ics');
        $connection = $this->connection();
        try {
            $this->provider()->updateEvent($connection, $id, ['start_date' => '2026-11-03', 'start_time' => '10:00']);
            $this->fail('expected CalendarSyncFailed');
        } catch (CalendarSyncFailed $e) {
            $this->assertSame('Move one occurrence at a time, or change the whole series in your calendar app.', $e->getMessage());
        }
        $this->provider()->updateEvent($connection, $id, ['title' => 'Sync']);
        $this->assertStringContainsString("DTSTART;TZID=Europe/Berlin:20261005T090000\r\n", $this->requests[2]->body());
    }

    public function test_deleting_one_occurrence_of_an_object_without_a_series_keeps_the_others(): void
    {
        $invite = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            ."BEGIN:VEVENT\r\nUID:inv-1\r\nRECURRENCE-ID:20261102T080000Z\r\nDTSTART:20261102T080000Z\r\nDTEND:20261102T090000Z\r\nSUMMARY:Invite A\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:inv-1\r\nRECURRENCE-ID:20261109T080000Z\r\nDTSTART:20261109T080000Z\r\nDTEND:20261109T090000Z\r\nSUMMARY:Invite B\r\nEND:VEVENT\r\n"
            ."END:VCALENDAR\r\n";
        $this->server([
            'GET /123456/calendars/home/inv.ics' => Http::response($invite, 200, ['ETag' => '"i1"']),
            'PUT /123456/calendars/home/inv.ics' => Http::response('', 204),
        ]);
        $this->provider()->deleteEvent($this->connection(), $this->id('/123456/calendars/home/inv.ics', '20261102T080000Z'));

        $this->assertSame('PUT', $this->requests[1]->method());
        $this->assertStringNotContainsString('Invite A', $this->requests[1]->body());
        $this->assertStringContainsString('Invite B', $this->requests[1]->body());
    }

    public function test_floating_series_occurrences_are_keyed_by_wall_clock_in_any_zone(): void
    {
        $floating = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:float-1\r\nDTSTART:20261102T090000\r\nDTEND:20261102T093000\r\nRRULE:FREQ=WEEKLY\r\nSUMMARY:Gym\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->server([
            'REPORT /123456/calendars/home/' => Http::response('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/123456/calendars/home/float.ics</d:href><d:propstat><d:prop><c:calendar-data>'.htmlspecialchars($floating, ENT_XML1).'</c:calendar-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>', 207),
            'GET /123456/calendars/home/float.ics' => Http::response($floating, 200, ['ETag' => '"f1"']),
            'PUT /123456/calendars/home/float.ics' => Http::response('', 204),
        ]);
        $connection = $this->connection();
        $ids = fn (string $zone) => array_column($this->provider()->listEvents($connection, Carbon::parse('2026-11-09'), Carbon::parse('2026-11-09'), $zone), 'id');
        $this->assertSame($ids('Europe/Berlin'), $ids('America/New_York'));
        $this->assertSame([$this->id('/123456/calendars/home/float.ics', '20261109T090000')], $ids('UTC'));

        $this->provider()->updateEvent($connection, $this->id('/123456/calendars/home/float.ics', '20261109T090000'), ['title' => 'Gym (late)']);
        $this->assertStringContainsString("RECURRENCE-ID:20261109T090000\r\n", $this->requests[4]->body());
    }

    public function test_encoded_dot_segments_and_document_types_are_refused(): void
    {
        $connection = $this->connection();
        $this->server([
            'REPORT /123456/calendars/home/' => Http::response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><d:multistatus xmlns:d="DAV:"/>', 207),
        ]);
        $this->assertNull($this->provider()->getEvent($connection, $this->id('/123456/calendars/home/%2e%2e/%2E%2E/other/x.ics'), null));
        $this->assertNull($this->provider()->getEvent($connection, $this->id('/123456/calendars/home/a\\b.ics'), null));
        $this->assertSame([], $this->requests);

        $this->expectExceptionObject(new CalendarSyncFailed('The calendar server sent an answer we couldn’t read.'));
        $this->provider()->listEvents($connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
    }

    public function test_the_app_password_site(): void
    {
        $this->assertSame('icloud.com', CaldavClient::site('p42-caldav.icloud.com'));
        $this->assertSame('icloud.com', CaldavClient::site('caldav.icloud.com'));
        $this->assertSame('example.co.uk', CaldavClient::site('dav.example.co.uk'));
        $this->assertSame('fastmail.com', CaldavClient::site('fastmail.com'));
        $this->assertSame('93.184.216.34', CaldavClient::site('93.184.216.34'));
    }
}
