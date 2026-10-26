<?php

namespace Tests\Feature\Meetings;

use App\Models\CalendarConnection;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\NormalizedEvent;
use App\Support\Calendar\Providers\GoogleCalendarProvider;
use App\Support\Calendar\Providers\OutlookCalendarProvider;
use App\Support\Meetings\MeetingCalendars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Conferencing on the calendar provider layer (BRAI-147): Google Meet through conferenceData,
 * Teams through Graph's isOnlineMeeting, which calendars allow which kind, attendees, and the
 * optional conference keys of normalized events (https only).
 */
class MeetingConferencingProviderTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE = 'www.googleapis.com/calendar/v3';

    private const GRAPH = 'graph.microsoft.com/v1.0';

    private const MEET_ALLOWED = 'www.googleapis.com/calendar/v3/users/me/calendarList/*';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        config(['services.microsoft' => ['client_id' => 'client-123', 'client_secret' => 'secret-xyz', 'tenant' => 'common', 'redirect' => null]]);
    }

    private function google(): CalendarConnection
    {
        return CalendarConnection::factory()->create(['external_calendar_id' => 'team@group.calendar.google.com']);
    }

    private function outlook(): CalendarConnection
    {
        return CalendarConnection::factory()->create([
            'provider' => 'outlook', 'account_email' => 'me@contoso.com', 'external_calendar_id' => 'cal-1',
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->addHour(),
        ]);
    }

    private const ATTENDEES = [
        ['email' => 'max@example.com', 'name' => 'Max Member'],
        ['email' => 'guest@partner.test', 'name' => null],
        ['email' => 'MAX@example.com', 'name' => 'Duplicate'],
        ['email' => 'not-an-email', 'name' => 'Dropped'],
    ];

    public function test_google_creates_a_meet_with_attendees_and_reads_its_link(): void
    {
        $connection = $this->google();
        Http::fake([self::MEET_ALLOWED => Http::response(['conferenceProperties' => ['allowedConferenceSolutionTypes' => ['hangoutsMeet']]]), self::GOOGLE.'/calendars/*' => fn (HttpRequest $r) => Http::response([
            'id' => 'ev-1', 'summary' => $r['summary'], 'htmlLink' => 'https://calendar.google.com/event?eid=ev-1',
            'start' => ['dateTime' => '2026-11-05T14:00:00+01:00'], 'end' => ['dateTime' => '2026-11-05T14:30:00+01:00'],
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            'conferenceData' => [
                'conferenceSolution' => ['key' => ['type' => 'hangoutsMeet']],
                'entryPoints' => [
                    ['entryPointType' => 'phone', 'uri' => 'tel:+49-30-1234'],
                    ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij'],
                ],
            ],
        ])]);

        $event = app(GoogleCalendarProvider::class)->createEvent($connection, [
            'title' => 'Sprint review', 'start_date' => '2026-11-05', 'start_time' => '14:00', 'end_time' => '14:30',
            'timezone' => 'Europe/Berlin', 'conference' => 'google_meet', 'attendees' => self::ATTENDEES,
        ]);

        $this->assertSame('https://meet.google.com/abc-defg-hij', $event['conference_url']);
        $this->assertSame('google_meet', $event['conference_provider']);
        Http::assertSent(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            $create = $r['conferenceData']['createRequest'] ?? [];

            return $r->method() === 'POST'
                && $query === ['sendUpdates' => 'all', 'conferenceDataVersion' => '1']
                && $create['conferenceSolutionKey'] === ['type' => 'hangoutsMeet']
                && preg_match('/^[0-9a-f-]{36}$/', $create['requestId']) === 1
                && $r['attendees'] === [['email' => 'max@example.com', 'displayName' => 'Max Member'], ['email' => 'guest@partner.test']];
        });
    }

    public function test_google_reads_a_pending_meet_once_more_and_plain_events_send_no_conference(): void
    {
        $connection = $this->google();
        Http::fake([
            self::MEET_ALLOWED => Http::response(['conferenceProperties' => ['allowedConferenceSolutionTypes' => ['hangoutsMeet']]]),
            self::GOOGLE.'/calendars/*/events/ev-2' => Http::response([
                'id' => 'ev-2', 'start' => ['dateTime' => '2026-11-05T14:00:00Z'], 'end' => ['dateTime' => '2026-11-05T15:00:00Z'],
                'conferenceData' => ['conferenceSolution' => ['key' => ['type' => 'hangoutsMeet']], 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/later']]],
            ]),
            self::GOOGLE.'/calendars/*' => Http::response([
                'id' => 'ev-2', 'start' => ['dateTime' => '2026-11-05T14:00:00Z'], 'end' => ['dateTime' => '2026-11-05T15:00:00Z'],
                'conferenceData' => ['createRequest' => ['status' => ['statusCode' => 'pending']]],
            ]),
        ]);
        $google = app(GoogleCalendarProvider::class);

        $event = $google->createEvent($connection, ['title' => 'Later', 'start_date' => '2026-11-05', 'start_time' => '14:00', 'end_time' => '15:00', 'conference' => 'google_meet']);
        $this->assertSame('https://meet.google.com/later', $event['conference_url']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/events/ev-2'));

        // Without conference / attendees the request is the plain one (no query, no new keys).
        $plain = $google->createEvent($connection, ['title' => 'Focus', 'start_date' => '2026-11-06', 'start_time' => '09:00']);
        $this->assertArrayNotHasKey('conference_url', $plain);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['summary'] === 'Focus'
            && parse_url($r->url(), PHP_URL_QUERY) === null && ! isset($r['conferenceData']) && ! isset($r['attendees']));
    }

    public function test_google_offers_meet_only_where_the_calendar_allows_it(): void
    {
        $connection = $this->google();
        Http::fake([self::MEET_ALLOWED => Http::sequence()
            ->push(['id' => 'team', 'conferenceProperties' => ['allowedConferenceSolutionTypes' => ['hangoutsMeet']]])
            ->push(['id' => 'team', 'conferenceProperties' => ['allowedConferenceSolutionTypes' => ['eventHangout']]])
            ->push(['error' => 'down'], 500)
            ->push(['id' => 'team', 'conferenceProperties' => ['allowedConferenceSolutionTypes' => ['hangoutsMeet']]]),
        ]);
        $google = app(GoogleCalendarProvider::class);

        $this->assertSame(['google_meet'], $google->conferenceProviders($connection));
        $this->assertSame([], $google->conferenceProviders($connection));
        $this->assertSame(['google_meet'], $google->conferenceProviders($connection), 'unknown → offered; the create reports the real error');

        // Asking for Teams on a Google calendar is refused before the event is sent.
        try {
            $google->createEvent($connection, ['title' => 'x', 'start_date' => '2026-11-05', 'start_time' => '10:00', 'conference' => 'teams']);
            $this->fail('Teams must be refused on Google');
        } catch (CalendarSyncFailed) {
            Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        }
    }

    public function test_outlook_creates_a_teams_meeting_with_attendees(): void
    {
        $connection = $this->outlook();
        Http::fake([
            self::GRAPH.'/me/calendars/cal-1?*' => Http::response(['allowedOnlineMeetingProviders' => ['teamsForBusiness'], 'defaultOnlineMeetingProvider' => 'teamsForBusiness']),
            self::GRAPH.'/me/calendars/cal-1/events' => Http::response([
                'id' => 'AAMk-new', 'subject' => 'Sprint review', 'isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness',
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc'],
                'webLink' => 'https://outlook.office365.com/owa/?itemid=AAMk-new',
                'start' => ['dateTime' => '2026-11-05T13:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-11-05T13:30:00.0000000', 'timeZone' => 'UTC'],
            ], 201),
        ]);
        $outlook = app(OutlookCalendarProvider::class);

        $this->assertSame(['teams'], $outlook->conferenceProviders($connection));
        $event = $outlook->createEvent($connection, [
            'title' => 'Sprint review', 'start_date' => '2026-11-05', 'start_time' => '14:00', 'end_time' => '14:30',
            'timezone' => 'Europe/Berlin', 'conference' => 'teams', 'attendees' => self::ATTENDEES,
        ]);

        $this->assertSame('https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc', $event['conference_url']);
        $this->assertSame('teams', $event['conference_provider']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/me/calendars/cal-1/events')
            && $r['isOnlineMeeting'] === true && $r['onlineMeetingProvider'] === 'teamsForBusiness'
            && $r['attendees'] === [
                ['emailAddress' => ['address' => 'max@example.com', 'name' => 'Max Member'], 'type' => 'required'],
                ['emailAddress' => ['address' => 'guest@partner.test'], 'type' => 'required'],
            ]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), '$select=allowedOnlineMeetingProviders'));
    }

    public function test_outlook_without_teams_for_business_offers_nothing_and_refuses_teams(): void
    {
        $connection = $this->outlook();
        // a personal Microsoft account: no Teams for business
        $personal = ['allowedOnlineMeetingProviders' => ['skypeForConsumer'], 'defaultOnlineMeetingProvider' => 'none'];
        Http::fake([self::GRAPH.'/me/calendars/cal-1?*' => Http::sequence()->push($personal)->push($personal)
            ->push(['error' => ['code' => 'ErrorInternalServerError']], 500)]);
        $outlook = app(OutlookCalendarProvider::class);

        $this->assertSame([], $outlook->conferenceProviders($connection));
        try {
            $outlook->createEvent($connection, ['title' => 'x', 'start_date' => '2026-11-05', 'start_time' => '10:00', 'conference' => 'teams']);
            $this->fail('Teams must be refused');
        } catch (CalendarSyncFailed $e) {
            $this->assertSame('Teams isn’t available for this calendar.', $e->getMessage());
        }
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');

        // A failed lookup offers nothing either (and isn't cached for long by MeetingCalendars).
        $this->assertSame([], app(OutlookCalendarProvider::class)->conferenceProviders($connection));
        Http::assertSentCount(3);
    }

    public function test_outlook_reads_the_event_again_when_the_teams_link_is_missing(): void
    {
        $connection = $this->outlook();
        Http::fake([
            self::GRAPH.'/me/calendars/cal-1?*' => Http::response(['allowedOnlineMeetingProviders' => ['teamsForBusiness']]),
            self::GRAPH.'/me/calendars/cal-1/events' => Http::response(['id' => 'AAMk-2', 'isOnlineMeeting' => true, 'onlineMeeting' => null,
                'start' => ['dateTime' => '2026-11-05T13:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-05T13:30:00.0000000', 'timeZone' => 'UTC']], 201),
            self::GRAPH.'/me/calendars/cal-1/events/AAMk-2' => Http::response(['id' => 'AAMk-2', 'isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness',
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/later'],
                'start' => ['dateTime' => '2026-11-05T13:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-05T13:30:00.0000000', 'timeZone' => 'UTC']]),
        ]);

        $event = app(OutlookCalendarProvider::class)->createEvent($connection, ['title' => 'x', 'start_date' => '2026-11-05', 'start_time' => '14:00', 'timezone' => 'Europe/Berlin', 'conference' => 'teams']);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/later', $event['conference_url']);
    }

    public function test_listed_events_carry_their_join_links_and_only_https_ones(): void
    {
        $google = app(GoogleCalendarProvider::class);
        $outlook = app(OutlookCalendarProvider::class);

        $meet = $google->normalize(['id' => 'g1', 'start' => ['dateTime' => '2026-11-05T09:00:00Z'], 'end' => ['dateTime' => '2026-11-05T09:30:00Z'],
            'hangoutLink' => 'https://meet.google.com/xyz'], 'UTC');
        $this->assertSame(['https://meet.google.com/xyz', 'google_meet'], [$meet['conference_url'], $meet['conference_provider']]);

        $zoom = $google->normalize(['id' => 'g2', 'start' => ['dateTime' => '2026-11-05T09:00:00Z'], 'end' => ['dateTime' => '2026-11-05T09:30:00Z'],
            'conferenceData' => ['conferenceSolution' => ['key' => ['type' => 'addOn']], 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://zoom.us/j/1']]]], 'UTC');
        $this->assertSame(['https://zoom.us/j/1', 'other'], [$zoom['conference_url'], $zoom['conference_provider']]);

        $teams = $outlook->normalize(['id' => 'o1', 'onlineMeetingProvider' => 'teamsForBusiness', 'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/x'],
            'start' => ['dateTime' => '2026-11-05T09:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-05T09:30:00.0000000', 'timeZone' => 'UTC']], 'UTC');
        $this->assertSame(['https://teams.microsoft.com/l/x', 'teams'], [$teams['conference_url'], $teams['conference_provider']]);

        // No call, or a link that isn't https: the optional keys are absent.
        foreach (['javascript:alert(1)', 'http://meet.example/x', 'https://user:pw@evil.example/x', 'https://', "https://a.example/\nx"] as $bad) {
            $event = $outlook->normalize(['id' => 'o2', 'onlineMeeting' => ['joinUrl' => $bad],
                'start' => ['dateTime' => '2026-11-05T09:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-05T09:30:00.0000000', 'timeZone' => 'UTC']], 'UTC');
            $this->assertArrayNotHasKey('conference_url', $event, $bad);
            $this->assertArrayNotHasKey('conference_provider', $event, $bad);
        }
        $plain = NormalizedEvent::allDay('a', 'Trip', '2026-11-05');
        $this->assertSame(NormalizedEvent::KEYS, array_keys($plain));

        // Listing asks Graph for the online meeting fields.
        $connection = $this->outlook();
        Http::fake([self::GRAPH.'/me/calendars/cal-1/calendarView*' => Http::response(['value' => []])]);
        $outlook->listEvents($connection, now(), now());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['$select'] ?? '', 'onlineMeeting'));
    }

    public function test_meeting_calendars_lists_writable_conferencing_calendars_and_caches_the_kinds(): void
    {
        $google = $this->google();
        CalendarConnection::factory()->tasks()->create(['user_id' => $google->user_id]);
        CalendarConnection::factory()->create(['user_id' => $google->user_id, 'credentials' => ['read_only' => true], 'external_calendar_name' => 'Holidays']);
        CalendarConnection::factory()->create(['user_id' => $google->user_id, 'is_active' => false]);
        Http::fake([self::GOOGLE.'/users/me/calendarList/*' => Http::response(['conferenceProperties' => ['allowedConferenceSolutionTypes' => ['hangoutsMeet']]])]);

        $rows = MeetingCalendars::for($google->user);
        $this->assertSame([$google->id], array_column($rows, 'id'));
        $this->assertSame(['google_meet'], $rows[0]['conference_providers']);
        $this->assertSame('Google Calendar', $rows[0]['provider_label']);

        MeetingCalendars::for($google->user);
        Http::assertSentCount(1); // cached
    }
}
