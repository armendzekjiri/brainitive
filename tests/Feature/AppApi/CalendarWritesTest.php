<?php

namespace Tests\Feature\AppApi;

use App\Models\ActivityLog;
use App\Models\CalendarConnection;
use App\Models\CalendarFeed;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/**
 * App API v1 calendar, write part (BRAI-418): events on the user's own writable calendars, online meetings of
 * issues / docs (MeetingScheduler, shared with the web) and the private ICS feed link (CalendarFeeds).
 * Providers are faked (FakeCalendarProvider); nothing calls a real calendar.
 */
class CalendarWritesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private CalendarConnection $connection;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        FakeCalendarProvider::reset();
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'UTC'));

        $this->editor->update(['timezone' => 'Europe/Berlin']);
        $this->connection = $this->calendarOf($this->editor);
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function calendarOf($user, array $attributes = []): CalendarConnection
    {
        return CalendarConnection::factory()->provider('fake')->create($attributes + [
            'user_id' => $user->id,
            'account_email' => 'me-'.$user->id.'@dav.example',
            'external_calendar_name' => 'Work',
            'credentials' => ['username' => 'me', 'password' => 'app-secret'],
        ]);
    }

    private function headers(?string $token = null): array
    {
        return $this->appHeaders($token ?? $this->token);
    }

    private function eventId(string $id, ?CalendarConnection $connection = null): string
    {
        return rawurlencode('event:'.($connection ?? $this->connection)->id.':'.$id);
    }

    // ── events ────────────────────────────────────────────────────────────────────────────────

    /** Codex review, finding 15: a calendar the user switched off is neither read nor written by explicit id. */
    public function test_a_switched_off_calendar_is_off_for_explicit_ids_too(): void
    {
        FakeCalendarProvider::$events = [NormalizedEvent::allDay('e1', 'Offsite', '2026-11-12', '2026-11-13')];
        $this->connection->update(['is_active' => false]);

        $this->postJson('/api/v1/calendar/events', ['connection_id' => $this->connection->id, 'title' => 'X', 'date' => '2026-11-12'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('connection_id');
        $this->getJson('/api/v1/calendar/events/'.$this->eventId('e1'), $this->headers())->assertNotFound();
        $this->patchJson('/api/v1/calendar/events/'.$this->eventId('e1'), ['title' => 'Y'], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/calendar/events/'.$this->eventId('e1'), [], $this->headers())->assertNotFound();
        $this->assertSame([], FakeCalendarProvider::$calls);

        // switched on again: works
        $this->connection->update(['is_active' => true]);
        $this->getJson('/api/v1/calendar/events/'.$this->eventId('e1'), $this->headers())->assertOk();
    }

    public function test_create_a_timed_event_in_the_users_zone(): void
    {
        $res = $this->postJson('/api/v1/calendar/events', [
            'connection_id' => $this->connection->id, 'title' => 'Dentist', 'date' => '2026-11-12', 'time' => '09:30',
            'end_time' => '10:15', 'location' => 'Main St', 'description' => 'Bring the card',
        ], $this->headers())->assertCreated();

        $res->assertJsonPath('data.id', 'event:'.$this->connection->id.':new-1')
            ->assertJsonPath('data.type', 'event')
            ->assertJsonPath('data.title', 'Dentist')
            ->assertJsonPath('data.all_day', false)
            ->assertJsonPath('data.start', '2026-11-12T08:30:00Z')
            ->assertJsonPath('data.end', '2026-11-12T09:15:00Z')
            ->assertJsonPath('data.description', 'Bring the card')
            ->assertJsonPath('data.calendar.connection_id', $this->connection->id)
            ->assertJsonPath('data.read_only', false);
        $this->assertSame('Europe/Berlin', FakeCalendarProvider::$calls[0][1]['timezone']);
        $this->assertArrayNotHasKey('conference', FakeCalendarProvider::$calls[0][1]);
    }

    public function test_create_an_all_day_event_and_a_request_id_creates_it_once(): void
    {
        $body = ['connection_id' => $this->connection->id, 'title' => 'Offsite', 'date' => '2026-11-20', 'end_date' => '2026-11-21', 'request_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/calendar/events', $body, $this->headers())->assertCreated()
            ->assertJsonPath('data.all_day', true)->assertJsonPath('data.start', '2026-11-20')->assertJsonPath('data.end', '2026-11-21');
        $this->postJson('/api/v1/calendar/events', $body, $this->headers())->assertOk()->assertJsonPath('data.title', 'Offsite');
        $this->assertCount(1, FakeCalendarProvider::$calls);

        $this->postJson('/api/v1/calendar/events', ['title' => 'Other'] + $body, $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'conflict');
    }

    public function test_a_video_call_only_where_the_calendar_offers_it(): void
    {
        $body = ['connection_id' => $this->connection->id, 'title' => 'Sync', 'date' => '2026-11-12', 'time' => '14:00', 'conference' => 'teams', 'attendees' => ['guest@partner.test']];
        $this->postJson('/api/v1/calendar/events', $body, $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('calendar');
        $this->assertSame([], FakeCalendarProvider::$calls);

        FakeCalendarProvider::$conference = ['teams'];
        Cache::flush(); // the conferencing answer is cached per connection
        $this->postJson('/api/v1/calendar/events', $body, $this->headers())->assertCreated()
            ->assertJsonPath('data.conference_url', 'https://meet.fake.test/abc')
            ->assertJsonPath('data.conference_provider', 'teams');
        $this->assertSame([['email' => 'guest@partner.test', 'name' => null]], FakeCalendarProvider::$calls[0][1]['attendees']);
    }

    public function test_someone_elses_or_a_read_only_calendar_is_refused(): void
    {
        $foreign = $this->calendarOf($this->viewer);
        $this->postJson('/api/v1/calendar/events', ['connection_id' => $foreign->id, 'title' => 'X', 'date' => '2026-11-12'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('connection_id');

        FakeCalendarProvider::$writable = false;
        $this->postJson('/api/v1/calendar/events', ['connection_id' => $this->connection->id, 'title' => 'X', 'date' => '2026-11-12'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.calendar.0', 'This calendar is read-only.');
        $this->deleteJson('/api/v1/calendar/events/'.$this->eventId('standup'), [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('calendar');
        $this->assertSame([], FakeCalendarProvider::$calls);

        $this->postJson('/api/v1/calendar/events', [], $this->headers())->assertUnprocessable()
            ->assertJsonValidationErrors(['connection_id', 'title', 'date']);
    }

    public function test_read_change_and_delete_an_event(): void
    {
        FakeCalendarProvider::$events = [NormalizedEvent::make([
            'id' => 'standup', 'title' => 'Standup', 'description' => 'Daily',
            'start_date' => '2026-11-10', 'start_time' => '09:00', 'end_date' => '2026-11-10', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin',
        ])];

        $this->getJson('/api/v1/calendar/events/'.$this->eventId('standup'), $this->headers())->assertOk()
            ->assertJsonPath('data.id', 'event:'.$this->connection->id.':standup')
            ->assertJsonPath('data.start', '2026-11-10T08:00:00Z')
            ->assertJsonPath('data.description', 'Daily');
        $this->getJson('/api/v1/calendar/events/'.$this->eventId('missing'), $this->headers())->assertNotFound();

        $this->patchJson('/api/v1/calendar/events/'.$this->eventId('standup'), ['title' => 'Standup (moved)', 'date' => '2026-11-11', 'time' => '10:00'], $this->headers())
            ->assertOk()->assertJsonPath('data.title', 'Standup (moved)');
        $this->assertSame(['update', ['id' => 'standup', 'title' => 'Standup (moved)', 'start_date' => '2026-11-11', 'start_time' => '10:00', 'end_date' => null, 'end_time' => null, 'timezone' => 'Europe/Berlin']], FakeCalendarProvider::$calls[0]);

        $this->patchJson('/api/v1/calendar/events/'.$this->eventId('standup'), ['location' => 'Room 2'], $this->headers())->assertOk();
        $this->assertSame(['id' => 'standup', 'location' => 'Room 2', 'timezone' => 'Europe/Berlin'], FakeCalendarProvider::$calls[1][1]);

        $this->deleteJson('/api/v1/calendar/events/'.$this->eventId('standup'), [], $this->headers())->assertNoContent();
        $this->assertSame(['delete', 'standup'], FakeCalendarProvider::$calls[2]);
    }

    public function test_other_users_calendars_and_malformed_ids_are_404(): void
    {
        $foreign = $this->calendarOf($this->viewer);
        FakeCalendarProvider::$events = [NormalizedEvent::allDay('x', 'Private', '2026-11-10')];

        $this->getJson('/api/v1/calendar/events/'.$this->eventId('x', $foreign), $this->headers())->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->patchJson('/api/v1/calendar/events/'.$this->eventId('x', $foreign), ['title' => 'Mine'], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/calendar/events/'.$this->eventId('x', $foreign), [], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/calendar/events/issue:12', [], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/calendar/events/'.$this->eventId('..'), [], $this->headers())->assertNotFound();
        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    public function test_tokens_of_other_clients_and_no_token_are_401(): void
    {
        $this->postJson('/api/v1/calendar/events', [])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $passport = $this->appHeaders($this->passportToken($this->editor));
        $this->postJson('/api/v1/calendar/events', ['connection_id' => $this->connection->id, 'title' => 'X', 'date' => '2026-11-12'], $passport)->assertUnauthorized();
        $this->getJson('/api/v1/meetings?subject_type=item&subject_id=1', $passport)->assertUnauthorized();
        $this->getJson('/api/v1/me/calendar-feed', $passport)->assertUnauthorized();
        $this->getJson('/api/v1/calendar/meeting-calendars', $passport)->assertUnauthorized();
    }

    public function test_meeting_calendars_are_the_users_writable_calendars_with_calls(): void
    {
        $this->calendarOf($this->viewer); // someone else's
        $this->getJson('/api/v1/calendar/meeting-calendars', $this->headers())->assertOk()->assertJsonCount(0, 'data');

        FakeCalendarProvider::$conference = ['google_meet', 'teams'];
        Cache::flush();
        $this->getJson('/api/v1/calendar/meeting-calendars', $this->headers())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->connection->id)
            ->assertJsonPath('data.0.conference_providers', ['google_meet', 'teams'])
            ->assertJsonMissingPath('data.0.credentials');
    }

    // ── meetings ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function meetingBody(array $overrides = []): array
    {
        return $overrides + [
            'subject_type' => 'item', 'subject_id' => (string) $this->item(['title' => 'Checkout'])->id,
            'connection_id' => $this->connection->id, 'title' => 'Checkout sync', 'date' => '2026-11-12',
            'start_time' => '14:00', 'duration' => 45, 'conference' => 'google_meet',
            'member_ids' => [$this->viewer->id], 'emails' => ['client@partner.test'],
        ];
    }

    public function test_schedule_list_and_remove_a_meeting_of_an_issue(): void
    {
        FakeCalendarProvider::$conference = ['google_meet'];
        $body = $this->meetingBody(['request_id' => (string) Str::uuid()]);

        $res = $this->postJson('/api/v1/meetings', $body, $this->headers())->assertCreated()
            ->assertJsonPath('data.title', 'Checkout sync')
            ->assertJsonPath('data.subject', ['type' => 'item', 'id' => $body['subject_id']])
            ->assertJsonPath('data.starts_at', '2026-11-12T13:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-11-12T13:45:00Z')
            ->assertJsonPath('data.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.join_url', 'https://meet.fake.test/abc')
            ->assertJsonPath('data.conference_provider', 'google_meet')
            ->assertJsonPath('data.attendees.1', ['name' => null, 'email' => 'client@partner.test'])
            ->assertJsonPath('data.can', ['remove' => true, 'cancel' => true]);
        $this->assertSame($this->viewer->email, $res->json('data.attendees.0.email'));
        $this->postJson('/api/v1/meetings', $body, $this->headers())->assertOk()->assertJsonPath('data.id', $res->json('data.id'));
        $this->assertSame(1, Meeting::count());
        $this->assertSame(1, ActivityLog::where('action', 'meeting_scheduled')->count());

        // a project viewer sees it, without the guests' addresses, and can't remove it
        $viewer = $this->appToken($this->viewer);
        $query = '/api/v1/meetings?subject_type=item&subject_id='.$body['subject_id'];
        $this->getJson($query, $this->headers($viewer))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendees.1', ['name' => 'Guest', 'email' => null])
            ->assertJsonPath('data.0.can', ['remove' => false, 'cancel' => false]);
        $id = $res->json('data.id');
        $this->deleteJson("/api/v1/meetings/{$id}", [], $this->headers($viewer))->assertForbidden();
        $this->deleteJson("/api/v1/meetings/{$id}?cancel_event=1", [], $this->headers($viewer))->assertForbidden();

        // an outsider: 404 everywhere
        $outsider = $this->appToken($this->outsider);
        $this->getJson($query, $this->headers($outsider))->assertNotFound();
        $this->deleteJson("/api/v1/meetings/{$id}", [], $this->headers($outsider))->assertNotFound();
        $this->deleteJson('/api/v1/meetings/abc', [], $this->headers())->assertNotFound();

        // the notes doc: created once, readable by the viewer
        $notes = $this->postJson("/api/v1/meetings/{$id}/notes", [], $this->headers())->assertCreated()->json('data');
        $this->assertStringStartsWith('Notes — Checkout sync', $notes['name']);
        $this->postJson("/api/v1/meetings/{$id}/notes", [], $this->headers($viewer))->assertOk()->assertJsonPath('data.uuid', $notes['uuid']);
        $this->postJson("/api/v1/meetings/{$id}/notes", [], $this->headers($outsider))->assertNotFound();
        $this->getJson($query, $this->headers($viewer))->assertJsonPath('data.0.notes_page.uuid', $notes['uuid']);

        // the organiser cancels: the calendar event goes too
        $this->deleteJson("/api/v1/meetings/{$id}?cancel_event=1", [], $this->headers())->assertNoContent();
        $this->assertSame(0, Meeting::count());
        $this->assertContains(['delete', 'new-1'], FakeCalendarProvider::$calls);
        $this->assertSame(1, ActivityLog::where('action', 'meeting_cancelled')->count());
    }

    public function test_scheduling_follows_the_web_rules(): void
    {
        // a viewer can't edit the issue → 403; an outsider can't open it → 404 (before validation)
        $this->postJson('/api/v1/meetings', $this->meetingBody(), $this->headers($this->appToken($this->viewer)))->assertForbidden();
        $this->postJson('/api/v1/meetings', $this->meetingBody(['title' => '']), $this->headers($this->appToken($this->outsider)))->assertNotFound();

        // someone else's calendar, a call the calendar can't add, a guest who isn't a project member
        $this->postJson('/api/v1/meetings', $this->meetingBody(['connection_id' => $this->calendarOf($this->viewer)->id]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('connection_id');
        $this->postJson('/api/v1/meetings', $this->meetingBody(), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('conference');
        $this->postJson('/api/v1/meetings', $this->meetingBody(['conference' => 'none', 'member_ids' => [$this->outsider->id]]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('member_ids.0');
        $this->postJson('/api/v1/meetings', ['subject_type' => 'item'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertSame(0, Meeting::count());

        // without a call, on a doc
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'published_at' => now()]);
        $this->postJson('/api/v1/meetings', $this->meetingBody(['subject_type' => 'page', 'subject_id' => $page->uuid, 'conference' => 'none', 'member_ids' => []]), $this->headers())
            ->assertCreated()->assertJsonPath('data.subject.type', 'page')->assertJsonPath('data.join_url', null)->assertJsonPath('data.conference_provider', null);
    }

    // ── the ICS feed link ─────────────────────────────────────────────────────────────────────

    public function test_turn_the_feed_on_change_reset_and_off(): void
    {
        $this->getJson('/api/v1/me/calendar-feed', $this->headers())->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.url', null)->assertJsonPath('data.options.assigned', true);
        $this->postJson('/api/v1/me/calendar-feed/reset', [], $this->headers())->assertNotFound();

        $foreignProject = Project::factory()->create();
        $on = $this->putJson('/api/v1/me/calendar-feed', ['options' => ['descriptions' => true, 'project_ids' => [$this->project->id, $foreignProject->id]]], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.options.descriptions', true)
            ->assertJsonPath('data.options.project_ids', [$this->project->id]);
        $url = $on->json('data.url');
        $this->assertStringContainsString('/calendar/feed/', $url);
        $this->assertStringStartsWith('webcal://', $on->json('data.webcal_url'));
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk();

        $this->putJson('/api/v1/me/calendar-feed', ['options' => ['tasks' => false]], $this->headers())->assertOk()
            ->assertJsonPath('data.url', $url)->assertJsonPath('data.options.tasks', false)->assertJsonPath('data.options.descriptions', false);
        $this->putJson('/api/v1/me/calendar-feed', ['options' => ['tasks' => 'maybe']], $this->headers())->assertUnprocessable();

        $reset = $this->postJson('/api/v1/me/calendar-feed/reset', [], $this->headers())->assertOk()->json('data.url');
        $this->assertNotSame($url, $reset);
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();

        $this->deleteJson('/api/v1/me/calendar-feed', [], $this->headers())->assertNoContent();
        $this->assertNull(CalendarFeed::activeFor($this->editor));
        $this->getJson('/api/v1/me/calendar-feed', $this->headers())->assertOk()->assertJsonPath('data.enabled', false);
    }
}
