<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\Providers\IcsSubscriptionProvider;
use App\Support\Calendar\SafeRemoteUrl;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Calendar link (ICS)" (BRAI-141): connect a secret iCal / webcal link, read its events through
 * the cache, never write, and never let a link reach a private address (SSRF). Public IP
 * literals (93.184.216.x) pass the guard without DNS; Http::fake() answers them.
 */
class IcsSubscriptionProviderTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://93.184.216.34/calendar/ical/team%40group/private-s3cr3t/basic.ics';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['timezone' => 'Europe/Berlin']);
    }

    private function feed(string $name = 'google'): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/calendar/{$name}.ics"));
    }

    private function connect(string $url)
    {
        return $this->actingAs($this->user)->post(route('calendar.connections.manual.store', 'ics'), ['url' => $url]);
    }

    private function connection(): CalendarConnection
    {
        return CalendarConnection::where('user_id', $this->user->id)->where('provider', 'ics')->sole();
    }

    private function provider(): IcsSubscriptionProvider
    {
        return CalendarProviders::make('ics');
    }

    public function test_the_catalog_offers_a_read_only_url_provider(): void
    {
        $ics = collect(CalendarProviders::catalog())->firstWhere('key', 'ics');
        $this->assertSame('Calendar link (ICS)', $ics['label']);
        $this->assertSame('url', $ics['connect_mode']);
        $this->assertSame(['write' => false, 'tasks' => false], $ics['capabilities']);
        $this->assertSame(['url'], array_column($ics['fields'], 'name'));
    }

    public function test_connecting_a_webcal_link_stores_the_link_only_in_encrypted_credentials(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response($this->feed(), 200, ['ETag' => '"v1"'])]);

        $this->connect('webcal://93.184.216.34/calendar/ical/team%40group/private-s3cr3t/basic.ics')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHas('success', 'Calendar connected.');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::LINK && $r->method() === 'GET');
        $connection = $this->connection();
        $this->assertSame('93.184.216.34', $connection->account_email);
        $this->assertSame('93.184.216.34', $connection->server_url);
        $this->assertSame('Team Calendar', $connection->external_calendar_name);
        $this->assertSame(sha1(self::LINK), $connection->external_calendar_id);
        $this->assertSame(['url' => self::LINK], $connection->credentials);
        $this->assertStringNotContainsString('s3cr3t', (string) DB::table('calendar_connections')->where('id', $connection->id)->value('credentials'));
        $this->assertFalse($this->provider()->isWritable($connection));
    }

    public function test_events_show_on_the_calendar_page_read_only_and_the_link_never_does(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response($this->feed())]);
        $this->connect(self::LINK)->assertSessionHasNoErrors();

        $response = $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            // the month view asks for Oct 25 – Dec 7: five weekly standups (the 9th excluded, the 16th moved)
            ->where('events', fn ($events) => collect($events)->where('provider', 'ics')->pluck('title')->countBy()->sortKeys()->all() === [
                'Call with NYC' => 1, 'Sprint planning' => 1, 'Standup' => 5, 'Standup (moved)' => 1, 'Team offsite' => 1,
            ] && collect($events)->where('provider', 'ics')->every(fn ($e) => $e['read_only'] === true && $e['provider_label'] === 'Calendar link (ICS)'))
            ->where('connections.0.server_url', '93.184.216.34')
            ->where('connections.0.read_only', true)
        );
        $this->assertStringNotContainsString('s3cr3t', $response->getContent());
        Http::assertSentCount(1); // the connect download primed the cache
    }

    public function test_writes_are_refused_with_422(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response($this->feed())]);
        $this->connect(self::LINK);
        $connection = $this->connection();
        $as = $this->actingAs($this->user);

        $as->postJson(route('calendar.connections.events.create', $connection), ['title' => 'X', 'date' => '2026-11-06'])
            ->assertStatus(422)->assertJsonPath('errors.calendar.0', 'This calendar is read-only.');
        $as->patchJson(route('calendar.connections.events.update', [$connection, 'abc']), ['title' => 'X'])->assertStatus(422);
        $as->deleteJson(route('calendar.connections.events.destroy', [$connection, 'abc']))->assertStatus(422);
        $as->patchJson(route('calendar.reschedule'), ['type' => 'external_event', 'id' => 'abc', 'connection_id' => $connection->id, 'due_date' => '2026-11-06'])
            ->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_the_feed_is_cached_then_revalidated_with_etag_and_last_modified(): void
    {
        Http::fake(['93.184.216.34/*' => Http::sequence()
            ->push($this->feed(), 200, ['ETag' => '"v1"', 'Last-Modified' => 'Mon, 26 Oct 2026 10:00:00 GMT'])
            ->push('', 304, ['ETag' => '"v1"'])
            ->push(str_replace('Sprint planning', 'Sprint planning v2', $this->feed()), 200, ['ETag' => '"v2"']),
        ]);
        $this->connect(self::LINK);
        $connection = $this->connection();
        $titles = fn () => collect($this->provider()->listEvents($connection, Carbon::parse('2026-11-04'), Carbon::parse('2026-11-04'), 'Europe/Berlin'))->pluck('title')->all();

        $this->assertSame(['Sprint planning'], $titles());
        Http::assertSentCount(1); // within 15 minutes: the cached copy

        $this->travel(16)->minutes();
        $this->assertSame(['Sprint planning'], $titles()); // 304: the cached copy again
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->header('If-None-Match') === ['"v1"'] && $r->header('If-Modified-Since') === ['Mon, 26 Oct 2026 10:00:00 GMT']);
        $this->assertSame(['Sprint planning'], $titles());
        Http::assertSentCount(2); // the 304 restarted the 15 minutes

        $this->travel(16)->minutes();
        $this->assertSame(['Sprint planning v2'], $titles());
        Http::assertSentCount(3);
    }

    public function test_two_links_coexist_as_separate_connections(): void
    {
        $second = 'https://93.184.216.34/holidays/basic.ics';
        Http::fake([
            self::LINK => Http::response($this->feed()),
            $second => Http::response($this->feed('holidays')),
        ]);
        $this->connect(self::LINK)->assertSessionHasNoErrors();
        $this->connect($second)->assertSessionHasNoErrors();
        $this->connect(self::LINK)->assertSessionHasNoErrors(); // the same link again: still one row

        $rows = CalendarConnection::where('user_id', $this->user->id)->where('provider', 'ics')->orderBy('id')->get();
        $this->assertSame(['Team Calendar', 'US Holidays'], $rows->pluck('external_calendar_name')->all());

        $holidays = collect($this->provider()->listEvents($rows[1], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), 'Europe/Berlin'))->pluck('title');
        $this->assertContains('Thanksgiving', $holidays);
        $this->assertNotContains('Sprint planning', $holidays);
    }

    public function test_event_ids_round_trip_for_one_offs_and_occurrences(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response($this->feed())]);
        $this->connect(self::LINK);
        $connection = $this->connection();
        $events = collect($this->provider()->listEvents($connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), 'Europe/Berlin'));

        foreach ($events as $event) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{24}(~\d{8}(T\d{6}Z)?)?$/', $event['id']);
            $this->assertSame($event, $this->provider()->getEvent($connection, $event['id'], 'Europe/Berlin'));
        }
        $moved = $events->firstWhere('title', 'Standup (moved)');
        $this->assertStringEndsWith('~20261116T080000Z', $moved['id']);

        $this->actingAs($this->user)->getJson(route('calendar.connections.events.show', [$connection, $moved['id']]).'?timezone=Europe/Berlin')
            ->assertOk()->assertJsonPath('event.start_time', '14:00')->assertJsonPath('read_only', true);
        $this->assertNull($this->provider()->getEvent($connection, 'nope', null));
    }

    public function test_events_without_a_uid_and_with_a_shared_uid_round_trip(): void
    {
        $feed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            ."BEGIN:VEVENT\r\nUID:lesson\r\nSUMMARY:Maths\r\nDTSTART:20261102T080000Z\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:lesson\r\nSUMMARY:Physics\r\nDTSTART:20261103T080000Z\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nSUMMARY:No uid\r\nDTSTART;VALUE=DATE:20261104\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        Http::fake(['93.184.216.34/*' => Http::response($feed)]);
        $this->connect(self::LINK)->assertSessionHasNoErrors();
        $connection = $this->connection();

        $events = $this->provider()->listEvents($connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), 'UTC');
        $this->assertSame(['Maths', 'Physics', 'No uid'], array_column($events, 'title'));
        $this->assertCount(3, array_unique(array_column($events, 'id')));
        foreach ($events as $event) {
            $this->assertSame($event, $this->provider()->getEvent($connection, $event['id'], 'UTC'));
        }
    }

    public function test_private_and_non_http_links_are_refused_without_a_request(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // more attempts than the route's 10 / minute
        Http::fake();
        foreach ([
            'http://127.0.0.1/calendar.ics',
            'http://10.0.0.5/calendar.ics',
            'http://192.168.1.10/calendar.ics',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/calendar.ics',
            'http://[fd00::1]/calendar.ics',
            'webcal://127.0.0.1/calendar.ics',
            'https://93.184.216.34:22/calendar.ics',   // not an allowed port
            'https://user:pw@93.184.216.34/calendar.ics',
        ] as $url) {
            $this->connect($url)->assertSessionHasErrors('url');
        }
        $this->connect('file:///etc/passwd')->assertSessionHasErrors('url');
        $this->connect('ftp://93.184.216.34/calendar.ics')->assertSessionHasErrors('url');

        Http::assertNothingSent();
        $this->assertSame(0, CalendarConnection::where('user_id', $this->user->id)->count());
        // a link is never flashed back into the session (it carries a secret)
        $this->assertNull(session()->getOldInput('url'));
    }

    public function test_redirects_are_followed_by_hand_and_checked_again(): void
    {
        Http::fake([
            'https://93.184.216.34/start.ics' => Http::response('', 302, ['Location' => 'https://93.184.216.35/real.ics']),
            'https://93.184.216.35/real.ics' => Http::response($this->feed('holidays')),
            'https://93.184.216.34/evil.ics' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            'https://93.184.216.34/loop.ics' => Http::response('', 302, ['Location' => '/loop.ics']),
        ]);

        $this->connect('https://93.184.216.34/start.ics')->assertSessionHasNoErrors();
        $this->assertSame('US Holidays', $this->connection()->external_calendar_name);

        $this->connect('https://93.184.216.34/evil.ics')->assertSessionHasErrors(['url' => 'This address can’t be used: it must be a public http(s) address on port 80 or 443 (no localhost or private networks).']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '169.254'));

        $this->connect('https://93.184.216.34/loop.ics')->assertSessionHasErrors(['calendar' => 'The calendar server redirected too many times.']);
        Http::assertSentCount(2 + 1 + 4); // start + real, evil, loop + 3 redirects
    }

    public function test_too_big_and_non_calendar_responses_are_refused(): void
    {
        config(['calendar.max_response_bytes' => 1000]);
        Http::fake([
            'https://93.184.216.34/big.ics' => Http::response($this->feed()), // ~1.9 KB
            'https://93.184.216.34/page.html' => Http::response('<html><body>Sign in</body></html>'),
        ]);

        $this->connect('https://93.184.216.34/big.ics')->assertSessionHasErrors(['calendar' => 'This calendar is too big to load (more than 1 MB).']);
        $this->connect('https://93.184.216.34/page.html')->assertSessionHasErrors('url');
        $this->assertSame(0, CalendarConnection::where('user_id', $this->user->id)->count());
    }

    public function test_a_failing_link_becomes_a_sync_error_without_the_secret(): void
    {
        Http::fake(['93.184.216.34/*' => Http::sequence()
            ->push($this->feed())
            ->push('gone', 404)
            ->push('nope', 401)
            ->whenEmpty(Http::failedConnection()),
        ]);
        $this->connect(self::LINK);
        $connection = $this->connection();

        $this->travel(16)->minutes();
        $page = fn () => $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']));
        $page()->assertOk()->assertInertia(fn (Assert $p) => $p->where('connections.0.sync_error', 'This calendar link no longer exists. Check the link, or remove it.'));

        $this->travel(16)->minutes();
        try {
            $this->provider()->listEvents($connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
            $this->fail('expected CalendarSyncFailed');
        } catch (CalendarSyncFailed $e) {
            $this->assertSame('This calendar link is private or was reset. Copy the secret address again and reconnect.', $e->getMessage());
        }
        try {
            $this->provider()->listEvents($connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
            $this->fail('expected CalendarSyncFailed');
        } catch (CalendarSyncFailed $e) {
            $this->assertSame('Couldn’t reach the calendar server. Check the address, or try again later.', $e->getMessage());
            $this->assertStringNotContainsString('s3cr3t', $e->getMessage());
        }
        $this->assertStringNotContainsString('s3cr3t', (string) $connection->fresh()->sync_error);
    }

    public function test_the_guard(): void
    {
        $this->assertSame('https://example.com/a.ics', SafeRemoteUrl::normalize(' webcal://example.com/a.ics#x '));
        $this->assertSame('https://example.com/a.ics', SafeRemoteUrl::normalize('WEBCALS://example.com/a.ics'));
        $this->assertNull(SafeRemoteUrl::normalize('javascript:alert(1)'));
        $this->assertSame('https://a.example/x/y/c.ics', SafeRemoteUrl::resolve('https://a.example/x/y/b.ics', 'c.ics'));
        $this->assertSame('https://a.example/c/', SafeRemoteUrl::resolve('https://a.example/x/y/', '../../c/'));
        $this->assertSame('https://a.example:8443/p', SafeRemoteUrl::resolve('https://a.example:8443/x', '/p'));
        $this->assertSame('http://b.example/p', SafeRemoteUrl::resolve('https://a.example/x', 'http://b.example/p'));
        $this->assertTrue(SafeRemoteUrl::sameOrigin('https://a.example/x', 'https://A.example:443/y'));
        $this->assertFalse(SafeRemoteUrl::sameOrigin('https://a.example/x', 'http://a.example/x'));

        $this->assertNotNull(SafeRemoteUrl::options('https://93.184.216.34:5232/dav/'));  // Radicale: allowed by default
        $this->assertNull(SafeRemoteUrl::options('https://93.184.216.34:6379/'));
        // the e2e switch is ignored outside APP_ENV=e2e
        config(['calendar.allow_private_links' => true]);
        $this->assertFalse(SafeRemoteUrl::allowPrivate());
        $this->assertNull(SafeRemoteUrl::options('http://127.0.0.1:8038/calendar.ics'));
    }
}
