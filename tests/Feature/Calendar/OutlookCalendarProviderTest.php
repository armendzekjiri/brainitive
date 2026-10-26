<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\CalendarReadOnly;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\PendingConnection;
use App\Support\Calendar\Providers\OutlookCalendarProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Outlook / Microsoft 365 on the provider contract (BRAI-140): the OAuth flow (state + PKCE),
 * the picker hand-over, Graph JSON ↔ normalized events, writes, token refresh and errors.
 * Every Microsoft endpoint is faked; stray requests fail the test.
 */
class OutlookCalendarProviderTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPH = 'graph.microsoft.com/v1.0';

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private OutlookCalendarProvider $outlook;

    private CalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Sleep::fake();
        config(['services.microsoft' => [
            'client_id' => 'client-123',
            'client_secret' => 'secret-xyz',
            'tenant' => 'common',
            'redirect' => 'https://app.test/calendar/connections/outlook/callback',
        ]]);
        $this->outlook = app(OutlookCalendarProvider::class);
        $this->connection = CalendarConnection::factory()->create([
            'provider' => 'outlook',
            'account_email' => 'me@outlook.com',
            'external_calendar_id' => 'cal-1',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_the_catalog_offers_outlook_only_when_configured(): void
    {
        $catalog = collect(CalendarProviders::catalog())->keyBy('key');
        $this->assertSame([
            'key' => 'outlook',
            'label' => 'Outlook / Microsoft 365',
            'badge' => 'O',
            'color' => '#0078D4',
            'connect_mode' => 'oauth',
            'redirect_url' => route('calendar.connections.outlook.redirect'),
            'fields' => [],
            'capabilities' => ['write' => true, 'tasks' => false, 'conferencing' => true],
        ], $catalog['outlook']);
        $this->assertSame(['google', 'outlook'], array_slice(array_keys(CalendarProviders::PROVIDERS), 0, 2));
        $this->assertInstanceOf(OutlookCalendarProvider::class, CalendarProviders::for($this->connection));

        config(['services.microsoft.client_secret' => null]);
        $this->assertArrayNotHasKey('outlook', collect(CalendarProviders::catalog())->keyBy('key')->all());
        $this->actingAs($this->connection->user)->get(route('calendar.connections.outlook.redirect'))->assertNotFound();
        $this->get(route('calendar.connections.outlook.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();

        // Existing connections keep resolving (the provider is registered, just not offered).
        $this->assertInstanceOf(OutlookCalendarProvider::class, CalendarProviders::for($this->connection));
    }

    public function test_the_redirect_sends_state_and_a_pkce_challenge_bound_to_the_session(): void
    {
        $response = $this->actingAs($this->connection->user)->get(route('calendar.connections.outlook.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $flow = session('calendar_outlook_oauth');
        $this->assertSame(40, strlen($flow['state']));
        $this->assertGreaterThanOrEqual(43, strlen($flow['verifier']));
        $this->assertSame([
            'client_id' => 'client-123',
            'response_type' => 'code',
            'redirect_uri' => 'https://app.test/calendar/connections/outlook/callback',
            'response_mode' => 'query',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
            'state' => $flow['state'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], $query);
        $this->assertStringNotContainsString('secret-xyz', $location);

        // A single-tenant app uses its tenant; junk in the setting falls back to "common".
        config(['services.microsoft.tenant' => 'contoso.onmicrosoft.com']);
        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?',
            $this->get(route('calendar.connections.outlook.redirect'))->headers->get('Location'));
        config(['services.microsoft.tenant' => 'evil.example/../x?']);
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?',
            $this->get(route('calendar.connections.outlook.redirect'))->headers->get('Location'));
    }

    public function test_the_callback_rejects_a_wrong_or_missing_state_and_a_cancelled_sign_in(): void
    {
        Http::fake();
        $user = $this->connection->user;
        $flow = ['calendar_outlook_oauth' => ['state' => 'good-state', 'verifier' => str_repeat('v', 64)]];

        $this->actingAs($user)->withSession($flow)
            ->get(route('calendar.connections.outlook.callback', ['code' => 'c', 'state' => 'bad-state']))
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHas('error', 'The Microsoft sign-in expired. Try connecting again.');
        $this->assertNull(session('calendar_outlook_oauth')); // used up

        // No flow in the session (a replay, or another browser).
        $this->get(route('calendar.connections.outlook.callback', ['code' => 'c', 'state' => 'good-state']))
            ->assertSessionHas('error', 'The Microsoft sign-in expired. Try connecting again.');

        // No code.
        $this->withSession($flow)->get(route('calendar.connections.outlook.callback', ['state' => 'good-state']))
            ->assertSessionHas('error', 'The Microsoft sign-in expired. Try connecting again.');

        $this->withSession($flow)
            ->get(route('calendar.connections.outlook.callback', ['error' => 'access_denied', 'error_description' => 'The user cancelled']))
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHas('error', 'Connecting your Outlook calendar was cancelled.');

        Http::assertNothingSent();
        $this->assertNull(PendingConnection::current());
    }

    public function test_the_callback_exchanges_the_code_and_opens_the_picker(): void
    {
        $user = User::factory()->create();
        $verifier = str_repeat('k', 64);
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response([
                'token_type' => 'Bearer', 'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3599,
            ]),
            self::GRAPH.'/me/calendars?*' => Http::response([
                'value' => [
                    ['id' => 'AAMk-cal-main=', 'name' => 'Calendar', 'hexColor' => '#0078d4', 'canEdit' => true, 'isDefaultCalendar' => true],
                    ['id' => 'AAMk-cal-holidays=', 'name' => 'Holidays', 'hexColor' => '', 'canEdit' => false, 'isDefaultCalendar' => false],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/page2?$skip=2',
            ]),
            self::GRAPH.'/me/calendars/page2*' => Http::response(['value' => [
                ['id' => 'AAMk-cal-team=', 'name' => 'Team', 'hexColor' => 'javascript:x', 'canEdit' => true],
            ]]),
            self::GRAPH.'/me?*' => Http::response(['displayName' => 'Mia Muster', 'mail' => null, 'userPrincipalName' => 'mia@outlook.com']),
        ]);

        $this->actingAs($user)
            ->withSession(['calendar_outlook_oauth' => ['state' => 'st', 'verifier' => $verifier]])
            ->get(route('calendar.connections.outlook.callback', ['code' => 'auth-code', 'state' => 'st']))
            ->assertRedirect(route('calendar.index', ['picking' => 'outlook']));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::TOKEN_URL
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'auth-code'
            && $r['code_verifier'] === $verifier
            && $r['redirect_uri'] === 'https://app.test/calendar/connections/outlook/callback'
            && $r['client_id'] === 'client-123'
            && $r['client_secret'] === 'secret-xyz'
            && $r['scope'] === 'offline_access User.Read Calendars.ReadWrite');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/me/calendars') && $r->hasHeader('Authorization', 'Bearer new-access'));

        $pending = PendingConnection::current();
        $this->assertSame(['outlook', 'mia@outlook.com', 'Mia Muster'], [$pending->provider, $pending->accountEmail, $pending->accountName]);
        $this->assertSame(['new-access', 'new-refresh', 3599], [$pending->secrets['access_token'], $pending->secrets['refresh_token'], $pending->secrets['expires_in']]);
        $this->assertSame([
            ['id' => 'AAMk-cal-main=', 'name' => 'Calendar', 'color' => '#0078d4', 'primary' => true, 'read_only' => false],
            ['id' => 'AAMk-cal-holidays=', 'name' => 'Holidays', 'color' => null, 'primary' => false, 'read_only' => true],
            ['id' => 'AAMk-cal-team=', 'name' => 'Team', 'color' => null, 'primary' => false, 'read_only' => false],
        ], $pending->calendars);
        $this->assertSame(0, CalendarConnection::where('user_id', $user->id)->count()); // nothing saved yet

        // The picker creates the rows, with encrypted tokens and the read-only flag.
        $this->getJson(route('calendar.connections.pending'))->assertJsonPath('pending.provider_label', 'Outlook / Microsoft 365')
            ->assertJsonMissingPath('pending.secrets');
        $this->post(route('calendar.connections.store'), ['external_calendar_ids' => ['AAMk-cal-main=', 'AAMk-cal-holidays=']])->assertRedirect();

        $rows = CalendarConnection::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame(['AAMk-cal-main=', 'AAMk-cal-holidays='], $rows->pluck('external_calendar_id')->all());
        $this->assertSame(['outlook', 'mia@outlook.com', 'new-access', 'new-refresh'], [$rows[0]->provider, $rows[0]->account_email, $rows[0]->access_token, $rows[0]->refresh_token]);
        $this->assertNotSame('new-access', DB::table('calendar_connections')->where('id', $rows[0]->id)->value('access_token'));
        $this->assertTrue(CalendarProviders::for($rows[0])->isWritable($rows[0]));
        $this->assertFalse(CalendarProviders::for($rows[1])->isWritable($rows[1]));
    }

    public function test_a_refused_code_or_calendar_list_returns_to_the_calendar_with_a_message(): void
    {
        $flow = ['calendar_outlook_oauth' => ['state' => 'st', 'verifier' => str_repeat('k', 64)]];
        Http::fake([
            'login.microsoftonline.com/*' => Http::sequence()
                ->push(['error' => 'invalid_grant', 'error_description' => 'AADSTS70008: expired'], 400)
                ->push(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
            self::GRAPH.'/me?*' => Http::response(['mail' => 'me@contoso.com', 'displayName' => 'Me']),
            self::GRAPH.'/me/calendars?*' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 500),
        ]);

        $this->actingAs($this->connection->user)->withSession($flow)
            ->get(route('calendar.connections.outlook.callback', ['code' => 'c', 'state' => 'st']))
            ->assertRedirect(route('calendar.index'))
            ->assertSessionHas('error', 'Microsoft didn’t accept the sign-in. Try connecting again.');

        $this->withSession($flow)
            ->get(route('calendar.connections.outlook.callback', ['code' => 'c', 'state' => 'st']))
            ->assertSessionHas('error', 'Could not load your Outlook calendars.');
        $this->assertNull(PendingConnection::current());
    }

    public function test_graph_events_are_normalized(): void
    {
        $allDay = $this->outlook->normalize([
            'id' => 'AAMkAllDay=',
            'subject' => 'Offsite',
            'isAllDay' => true,
            'body' => ['contentType' => 'html', 'content' => '<p>Agenda &amp; <b>notes</b></p>'],
            'location' => ['displayName' => ' Lisbon '],
            'webLink' => 'https://outlook.live.com/calendar/item/AAMkAllDay%3D',
            'start' => ['dateTime' => '2026-11-04T00:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-11-07T00:00:00.0000000', 'timeZone' => 'UTC'],
        ], 'Europe/Berlin');
        $this->assertSame([
            'id' => 'AAMkAllDay=',
            'title' => 'Offsite',
            'description' => 'Agenda & notes',
            'location' => 'Lisbon',
            'all_day' => true,
            'start_date' => '2026-11-04',
            'start_time' => null,
            'end_date' => '2026-11-06',
            'end_time' => null,
            'timezone' => null,
            'external_url' => 'https://outlook.live.com/calendar/item/AAMkAllDay%3D',
        ], $allDay);

        // A one-day all-day event ends the same day; empty subject → "(untitled)".
        $single = $this->outlook->normalize(['id' => 'd1', 'isAllDay' => true, 'subject' => '',
            'start' => ['dateTime' => '2026-11-04T00:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-11-05T00:00:00.0000000', 'timeZone' => 'UTC']], null);
        $this->assertSame(['2026-11-04', '2026-11-04', '(untitled)'], [$single['start_date'], $single['end_date'], $single['title']]);

        // Timed: UTC wall clock (our Prefer header) → the asked zone, across midnight too.
        $timed = ['id' => 't1', 'subject' => 'Standup', 'isAllDay' => false, 'bodyPreview' => 'Daily',
            'webLink' => 'http://not-https.example/x',
            'start' => ['dateTime' => '2026-11-05T23:30:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-11-06T00:15:00.0000000', 'timeZone' => 'UTC']];
        $utc = $this->outlook->normalize($timed, null);
        $this->assertSame(['2026-11-05', '23:30', '2026-11-06', '00:15', 'UTC', 'Daily', null], [
            $utc['start_date'], $utc['start_time'], $utc['end_date'], $utc['end_time'], $utc['timezone'], $utc['description'], $utc['external_url'],
        ]);
        $berlin = $this->outlook->normalize($timed, 'Europe/Berlin');
        $this->assertSame(['2026-11-06', '00:30', '2026-11-06', '01:15', 'Europe/Berlin'], [
            $berlin['start_date'], $berlin['start_time'], $berlin['end_date'], $berlin['end_time'], $berlin['timezone'],
        ]);

        // Graph ignoring the Prefer header: a Windows zone name is understood.
        $windows = $this->outlook->normalize(['id' => 't2',
            'start' => ['dateTime' => '2026-11-05T09:00:00.0000000', 'timeZone' => 'W. Europe Standard Time'],
            'end' => ['dateTime' => '2026-11-05T10:00:00.0000000', 'timeZone' => 'W. Europe Standard Time']], 'UTC');
        $this->assertSame(['08:00', '09:00'], [$windows['start_time'], $windows['end_time']]);

        // Cancelled occurrences and events without an id / start are skipped.
        $this->assertNull($this->outlook->normalize(['id' => 'x', 'isCancelled' => true, 'start' => ['dateTime' => '2026-11-05T09:00:00', 'timeZone' => 'UTC']], null));
        $this->assertNull($this->outlook->normalize(['id' => 'x'], null));
        $this->assertNull($this->outlook->normalize(['start' => ['dateTime' => '2026-11-05T09:00:00', 'timeZone' => 'UTC']], null));

        // Ids with "/" travel base64url-encoded (the event routes refuse slashes) and round-trip.
        $slashy = $this->outlook->normalize(['id' => 'AAMk/abc+d==', 'start' => ['dateTime' => '2026-11-05T09:00:00', 'timeZone' => 'UTC']], null);
        $this->assertStringNotContainsString('/', $slashy['id']);
        $this->assertStringStartsWith('b64.', $slashy['id']);
        $this->assertSame('AAMk/abc+d==', OutlookCalendarProvider::graphId($slashy['id']));
        $this->assertSame('AAMk-plain=', OutlookCalendarProvider::graphId('AAMk-plain='));
        $this->assertSame('b64.Li4', OutlookCalendarProvider::graphId('b64.Li4')); // "..": not decoded
    }

    public function test_list_events_reads_the_calendar_view_pages_in_utc(): void
    {
        $occurrence = fn (string $id, string $day) => [
            'id' => $id, 'subject' => 'Weekly sync', 'isAllDay' => false,
            'start' => ['dateTime' => "{$day}T09:00:00.0000000", 'timeZone' => 'UTC'],
            'end' => ['dateTime' => "{$day}T09:30:00.0000000", 'timeZone' => 'UTC'],
        ];
        Http::fake([
            self::GRAPH.'/me/calendars/cal-1/calendarView?*' => Http::response([
                'value' => [$occurrence('occ-1', '2026-11-02'), $occurrence('occ-2', '2026-11-09'), ['id' => 'gone', 'isCancelled' => true, 'start' => ['dateTime' => '2026-11-03T09:00:00', 'timeZone' => 'UTC']]],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView/next?$skiptoken=abc',
            ]),
            self::GRAPH.'/me/calendars/cal-1/calendarView/next*' => Http::response([
                'value' => [$occurrence('occ-3', '2026-11-16')],
                // never followed: not Graph (the token must not leave Microsoft)
                '@odata.nextLink' => 'https://evil.example/steal?$skiptoken=x',
            ]),
        ]);

        $events = $this->outlook->listEvents($this->connection, Carbon::parse('2026-11-01', 'Europe/Berlin'), Carbon::parse('2026-11-30', 'Europe/Berlin'), 'Europe/Berlin');

        $this->assertSame(['occ-1', 'occ-2', 'occ-3'], array_column($events, 'id'));
        $this->assertSame(['10:00', '10:30'], [$events[0]['start_time'], $events[0]['end_time']]);
        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), '/calendarView?')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['startDateTime'] === '2026-10-31T23:00:00Z'
                && $q['endDateTime'] === '2026-11-30T23:00:00Z'
                && str_contains($q['$select'], 'isAllDay')
                && $r->hasHeader('Prefer', 'outlook.timezone="UTC", outlook.body-content-type="text"')
                && $r->hasHeader('Authorization', 'Bearer access-1');
        });
    }

    public function test_list_events_follows_at_most_twenty_pages(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::response([
            'value' => [['id' => 'e', 'start' => ['dateTime' => '2026-11-05T09:00:00', 'timeZone' => 'UTC']]],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView?$skiptoken=again',
        ])]);

        $this->assertCount(20, $this->outlook->listEvents($this->connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
        Http::assertSentCount(20);
    }

    public function test_get_event_and_not_found(): void
    {
        Http::fake([
            self::GRAPH.'/me/calendars/cal-1/events/AAMk%2Fx%2By%3D' => Http::response([
                'id' => 'AAMk/x+y=', 'subject' => 'Review', 'start' => ['dateTime' => '2026-11-05T09:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-11-05T10:00:00.0000000', 'timeZone' => 'UTC'],
            ]),
            self::GRAPH.'/me/calendars/cal-1/events/missing' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404),
            self::GRAPH.'/me/calendars/cal-1/events/broken' => Http::response(['error' => ['code' => 'InternalServerError']], 500),
        ]);

        $publicId = OutlookCalendarProvider::publicId('AAMk/x+y=');
        $event = $this->outlook->getEvent($this->connection, $publicId, 'America/New_York');
        $this->assertSame([$publicId, 'Review', '04:00', 'America/New_York'], [$event['id'], $event['title'], $event['start_time'], $event['timezone']]);

        $this->assertNull($this->outlook->getEvent($this->connection, 'missing'));
        $this->expectExceptionObject(new CalendarSyncFailed('Outlook didn’t answer. Try again later.'));
        $this->outlook->getEvent($this->connection, 'broken');
    }

    public function test_create_update_and_delete_send_graph_payloads(): void
    {
        $echo = fn (HttpRequest $r) => Http::response([
            'id' => 'new-1', 'subject' => $r['subject'] ?? 'Kept',
            'start' => ['dateTime' => '2026-11-05T08:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-11-05T09:00:00.0000000', 'timeZone' => 'UTC'],
        ], 201);
        Http::fake([
            self::GRAPH.'/me/calendars/cal-1/events' => $echo,
            self::GRAPH.'/me/calendars/cal-1/events/ev-1' => fn (HttpRequest $r) => $r->method() === 'DELETE' ? Http::response(null, 204) : $echo($r),
            self::GRAPH.'/me/calendars/cal-1/events/gone' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404),
            self::GRAPH.'/me/calendars/cal-1/events/locked' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403),
        ]);

        // Timed, in the user's zone.
        $created = $this->outlook->createEvent($this->connection, [
            'title' => 'Planning', 'description' => 'Bring notes', 'location' => 'Room 4',
            'start_date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '10:30', 'timezone' => 'Europe/Berlin',
        ]);
        $this->assertSame('new-1', $created['id']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/me/calendars/cal-1/events') && $r->data() === [
            'subject' => 'Planning',
            'isAllDay' => false,
            'start' => ['dateTime' => '2026-11-05T09:00:00', 'timeZone' => 'Europe/Berlin'],
            'end' => ['dateTime' => '2026-11-05T10:30:00', 'timeZone' => 'Europe/Berlin'],
            'body' => ['contentType' => 'text', 'content' => 'Bring notes'],
            'location' => ['displayName' => 'Room 4'],
        ]);

        // All-day: the inclusive end becomes Graph's exclusive midnight.
        $this->outlook->createEvent($this->connection, ['title' => 'Trip', 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'timezone' => 'Europe/Berlin']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && ($r['subject'] ?? null) === 'Trip' && $r['isAllDay'] === true
            && $r['start'] === ['dateTime' => '2026-11-10T00:00:00', 'timeZone' => 'Europe/Berlin']
            && $r['end'] === ['dateTime' => '2026-11-13T00:00:00', 'timeZone' => 'Europe/Berlin']);

        // Update: only the keys sent change.
        $this->outlook->updateEvent($this->connection, 'ev-1', ['title' => 'Renamed', 'timezone' => 'Europe/Berlin']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r->data() === ['subject' => 'Renamed']);
        $this->outlook->updateEvent($this->connection, 'ev-1', ['description' => null, 'start_date' => '2026-11-06', 'start_time' => '14:00', 'end_time' => '15:00', 'timezone' => 'Europe/Berlin']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r->data() === [
            'body' => ['contentType' => 'text', 'content' => ''],
            'isAllDay' => false,
            'start' => ['dateTime' => '2026-11-06T14:00:00', 'timeZone' => 'Europe/Berlin'],
            'end' => ['dateTime' => '2026-11-06T15:00:00', 'timeZone' => 'Europe/Berlin'],
        ]);

        // Delete: done, and "already gone" counts as done.
        $this->outlook->deleteEvent($this->connection, 'ev-1');
        $this->outlook->deleteEvent($this->connection, 'gone');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/ev-1'));

        // Outlook refusing a write on a calendar shared read-only with the account.
        $this->expectException(CalendarReadOnly::class);
        $this->outlook->updateEvent($this->connection, 'locked', ['title' => 'x']);
    }

    public function test_a_read_only_calendar_refuses_writes_without_asking_outlook(): void
    {
        Http::fake();
        $this->connection->update(['credentials' => ['read_only' => true]]);

        $this->assertFalse($this->outlook->isWritable($this->connection));
        foreach ([
            fn () => $this->outlook->createEvent($this->connection, ['title' => 'x', 'start_date' => '2026-11-05']),
            fn () => $this->outlook->updateEvent($this->connection, 'ev-1', ['title' => 'x']),
            fn () => $this->outlook->deleteEvent($this->connection, 'ev-1'),
        ] as $write) {
            try {
                $write();
                $this->fail('expected CalendarReadOnly');
            } catch (CalendarReadOnly) {
                // expected
            }
        }
        Http::assertNothingSent();

        $this->actingAs($this->connection->user)
            ->patchJson(route('calendar.connections.events.update', [$this->connection, 'ev-1']), ['title' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This calendar is read-only.');
    }

    public function test_an_expired_token_is_refreshed_and_the_rotated_tokens_are_saved(): void
    {
        $this->connection->update(['token_expires_at' => now()->subMinute()]);
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
            self::GRAPH.'/*' => Http::response(['value' => []]),
        ]);

        $this->assertSame([], $this->outlook->listEvents($this->connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::TOKEN_URL && $r['grant_type'] === 'refresh_token'
            && $r['refresh_token'] === 'refresh-1' && $r['client_secret'] === 'secret-xyz');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'calendarView') && $r->hasHeader('Authorization', 'Bearer access-2'));
        $fresh = $this->connection->fresh();
        $this->assertSame(['access-2', 'refresh-2'], [$fresh->access_token, $fresh->refresh_token]);
        $this->assertTrue($fresh->token_expires_at->isFuture());
        $this->assertNotSame('refresh-2', DB::table('calendar_connections')->where('id', $fresh->id)->value('refresh_token'));
    }

    public function test_a_401_refreshes_once_and_retries(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-2', 'expires_in' => 3600]),
            self::GRAPH.'/*' => Http::sequence()
                ->push(['error' => ['code' => 'InvalidAuthenticationToken']], 401)
                ->push(['value' => [['id' => 'e1', 'start' => ['dateTime' => '2026-11-05T09:00:00', 'timeZone' => 'UTC']]]]),
        ]);

        $this->assertCount(1, $this->outlook->listEvents($this->connection, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
        $fresh = $this->connection->fresh();
        $this->assertSame(['access-2', 'refresh-1'], [$fresh->access_token, $fresh->refresh_token]); // no rotation sent: kept
    }

    public function test_a_revoked_refresh_token_is_a_reconnect_warning_and_the_connection_stays(): void
    {
        $this->connection->update(['token_expires_at' => now()->subMinute()]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'AADSTS70000: revoked'], 400),
        ]);

        $this->actingAs($this->connection->user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 0)
                ->where('connections.0.id', $this->connection->id)
                ->where('connections.0.sync_error', 'Outlook access expired. Reconnect Outlook.'));

        $fresh = $this->connection->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_active);
        $this->assertSame('refresh-1', $fresh->refresh_token);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.microsoft.com'));
    }

    public function test_throttling_waits_once_for_a_short_retry_after_and_otherwise_fails_kindly(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::sequence()
            ->push(['error' => ['code' => 'TooManyRequests']], 429, ['Retry-After' => '1'])
            ->push(['value' => []])
            ->push(['error' => ['code' => 'TooManyRequests']], 429, ['Retry-After' => '30'])
            ->push(['error' => ['code' => 'ServiceUnavailable']], 503),
        ]);
        $range = [Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')];

        $this->assertSame([], $this->outlook->listEvents($this->connection, ...$range));
        Sleep::assertSleptTimes(1);

        try {
            $this->outlook->listEvents($this->connection, ...$range);
            $this->fail('expected CalendarSyncFailed');
        } catch (CalendarSyncFailed $e) {
            $this->assertSame('Outlook is busy right now. Try again in 30 seconds.', $e->getMessage());
        }

        $this->expectExceptionObject(new CalendarSyncFailed('Outlook is busy right now. Try again in a minute.'));
        $this->outlook->listEvents($this->connection, ...$range);
    }

    public function test_outlook_events_on_the_calendar_page(): void
    {
        Http::fake([self::GRAPH.'/me/calendars/cal-1/calendarView*' => Http::response(['value' => [
            ['id' => 'o1', 'subject' => 'Standup', 'webLink' => 'https://outlook.office365.com/owa/?itemid=o1',
                'start' => ['dateTime' => '2026-11-05T08:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-11-05T08:15:00.0000000', 'timeZone' => 'UTC']],
        ]])]);
        $this->connection->user->update(['timezone' => 'Europe/Berlin']);

        $this->actingAs($this->connection->user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.type', 'external_event')
                ->where('events.0.provider', 'outlook')
                ->where('events.0.provider_label', 'Outlook / Microsoft 365')
                ->where('events.0.provider_badge', 'O')
                ->where('events.0.start_time', '09:00')
                ->where('events.0.external_url', 'https://outlook.office365.com/owa/?itemid=o1')
                ->where('events.0.read_only', false));
        $this->assertNull($this->connection->fresh()->sync_error);
    }
}
