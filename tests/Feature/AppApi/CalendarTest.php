<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/**
 * App API v1 calendar, read part (BRAI-418): GET /calendar/events (the web calendar's sources through
 * the shared CalendarEvents, cut to the user's local days) and GET /calendar/connections (status only).
 */
class CalendarTest extends TestCase
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
        $this->connection = CalendarConnection::factory()->provider('fake')->create([
            'user_id' => $this->editor->id,
            'account_email' => 'me@dav.example',
            'external_calendar_name' => 'Work',
            'external_calendar_color' => '#ff0000',
            'credentials' => ['username' => 'me', 'password' => 'app-secret'],
        ]);
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function events(string $query = '', ?string $token = null)
    {
        return $this->getJson('/api/v1/calendar/events'.($query !== '' ? '?'.$query : ''), $this->appHeaders($token ?? $this->token));
    }

    public function test_connected_events_and_dated_work_in_one_list_by_start(): void
    {
        FakeCalendarProvider::$events = [
            NormalizedEvent::make([
                'id' => 'standup', 'title' => 'Standup', 'location' => 'Room 4',
                'start_date' => '2026-11-10', 'start_time' => '09:00', 'end_date' => '2026-11-10', 'end_time' => '09:15', 'timezone' => 'Europe/Berlin',
                'conference_url' => 'https://meet.google.com/abc-defg-hij', 'conference_provider' => 'google_meet',
            ]),
            NormalizedEvent::allDay('offsite', 'Offsite', '2026-11-11', '2026-11-12'),
            NormalizedEvent::allDay('later', 'Too late', '2026-11-20'),
        ];
        $issue = $this->item(['title' => 'Ship it', 'due_date' => '2026-11-10', 'due_time' => '17:00']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'Water plants', 'due_date' => '2026-11-11', 'recurrence' => 'daily', 'created_by' => $this->editor->id]);
        $hidden = Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id])->id]);
        Item::factory()->create(['board_id' => $hidden->id, 'title' => 'Secret', 'due_date' => '2026-11-10']);

        $response = $this->events('from=2026-11-10&to=2026-11-11')->assertOk()
            ->assertJsonPath('from', '2026-11-10')
            ->assertJsonPath('to', '2026-11-11')
            ->assertJsonPath('timezone', 'Europe/Berlin')
            ->assertJsonPath('errors', []);

        $this->assertSame(
            // by day; all-day first (the daily task repeats on the 10th too, as on the web calendar)
            ["task:{$task->id}:2026-11-10", 'event:'.$this->connection->id.':standup', "issue:{$issue->id}", 'event:'.$this->connection->id.':offsite', "task:{$task->id}:2026-11-11"],
            array_column($response->json('data'), 'id'),
        );
        $this->assertNotContains('Secret', array_column($response->json('data'), 'title'));

        $standup = $response->json('data.1');
        $this->assertSame([
            'id', 'type', 'work_id', 'title', 'all_day', 'start', 'end', 'location', 'conference_url', 'conference_provider', 'color', 'calendar', 'project', 'url', 'external_url', 'done', 'read_only',
        ], array_keys($standup));
        $this->assertSame(['event', false, '2026-11-10T08:00:00Z', '2026-11-10T08:15:00Z', 'Room 4', 'https://meet.google.com/abc-defg-hij', 'google_meet', '#ff0000'],
            [$standup['type'], $standup['all_day'], $standup['start'], $standup['end'], $standup['location'], $standup['conference_url'], $standup['conference_provider'], $standup['color']]);
        $this->assertSame(['connection_id' => $this->connection->id, 'name' => 'Work', 'provider' => 'fake', 'provider_label' => 'Fake DAV'], $standup['calendar']);
        $this->assertNull($standup['work_id']);

        // Brainitive's own: the user's wall clock (Berlin) → UTC
        $ship = $response->json('data.2');
        $this->assertSame(['issue', "issue:{$issue->id}", '2026-11-10T16:00:00Z', false], [$ship['type'], $ship['work_id'], $ship['start'], $ship['all_day']]);
        $this->assertSame(['id' => $this->project->id, 'name' => $this->project->name, 'key' => 'BRAI'], $ship['project']);
        $this->assertSame(route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $issue->id]), $ship['url']);

        $offsite = $response->json('data.3');
        $this->assertSame([true, '2026-11-11', '2026-11-12'], [$offsite['all_day'], $offsite['start'], $offsite['end']]);
    }

    public function test_days_are_the_users_local_days(): void
    {
        $this->editor->update(['timezone' => 'Pacific/Auckland']); // UTC+13
        FakeCalendarProvider::$events = [
            // 23:30 on the 10th in Auckland = 10:30 UTC on the 10th
            NormalizedEvent::make(['id' => 'late', 'title' => 'Late call', 'start_date' => '2026-11-10', 'start_time' => '23:30', 'end_date' => '2026-11-11', 'end_time' => '00:30', 'timezone' => 'Pacific/Auckland']),
            // 00:30 on the 12th in Auckland: not in 10th–11th
            NormalizedEvent::make(['id' => 'next', 'title' => 'Next day', 'start_date' => '2026-11-12', 'start_time' => '00:30', 'end_date' => '2026-11-12', 'end_time' => '01:00', 'timezone' => 'Pacific/Auckland']),
        ];

        $ids = array_column($this->events('from=2026-11-10&to=2026-11-11')->assertOk()->json('data'), 'id');
        $this->assertSame(['event:'.$this->connection->id.':late'], $ids);

        $late = $this->events('from=2026-11-11&to=2026-11-11')->json('data.0');
        $this->assertSame(['2026-11-10T10:30:00Z', '2026-11-10T11:30:00Z'], [$late['start'], $late['end']]); // it runs into the 11th
    }

    public function test_filters_like_the_web_calendar(): void
    {
        FakeCalendarProvider::$events = [NormalizedEvent::allDay('a', 'Holiday', '2026-11-10')];
        $mine = $this->item(['due_date' => '2026-11-10', 'assigned_to' => $this->editor->id]);
        $theirs = $this->item(['due_date' => '2026-11-10', 'assigned_to' => $this->viewer->id]);
        $done = $this->item(['due_date' => '2026-11-10', 'assigned_to' => $this->editor->id, 'column_id' => $this->column('done')]);

        $ids = fn (string $query) => array_column($this->events('from=2026-11-10&to=2026-11-10&'.$query)->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing(["issue:{$mine->id}", "issue:{$done->id}"], $ids('sources[]=board&me_only=1'));
        $this->assertEqualsCanonicalizing(["issue:{$mine->id}", "issue:{$theirs->id}"], $ids('sources[]=board&show_completed=0'));
        $this->assertSame(['event:'.$this->connection->id.':a'], $ids('sources[]=external'));
        $this->assertTrue(collect($this->events('from=2026-11-10&to=2026-11-10&sources[]=board')->json('data'))->firstWhere('id', "issue:{$done->id}")['done']);
    }

    public function test_a_failing_calendar_is_reported_not_fatal(): void
    {
        FakeCalendarProvider::$fail = 'Reconnect this calendar.';
        $this->item(['due_date' => '2026-11-10']);

        $this->events('from=2026-11-10&to=2026-11-10')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('errors', [['connection_id' => $this->connection->id, 'message' => 'Reconnect this calendar.']]);

        $this->getJson('/api/v1/calendar/connections', $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.0.sync_error', 'Reconnect this calendar.');
    }

    public function test_validates_the_range(): void
    {
        $this->events('from=2026-11-10&to=2026-11-09')->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('to');
        $this->events('from=2026-11-01&to=2027-01-15')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->events('from=10.11.2026')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->events('sources[]=birthdays')->assertUnprocessable()->assertJsonValidationErrors('sources.0');

        // defaults: today (Berlin) + 7 days
        $this->events()->assertOk()->assertJsonPath('from', '2026-11-10')->assertJsonPath('to', '2026-11-17');
    }

    public function test_connections_are_the_users_own_status_only(): void
    {
        CalendarConnection::factory()->provider('fake')->create(['user_id' => $this->viewer->id, 'external_calendar_name' => 'Not yours']);

        $response = $this->getJson('/api/v1/calendar/connections', $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'id' => $this->connection->id, 'provider' => 'fake', 'provider_label' => 'Fake DAV', 'kind' => 'calendar',
                'account_email' => 'me@dav.example', 'account_name' => $this->connection->account_name, 'name' => 'Work', 'color' => '#ff0000',
                'is_active' => true, 'read_only' => false, 'sync_error' => null, 'sync_error_at' => null,
            ]);
        $this->assertStringNotContainsString('app-secret', $response->getContent());
    }

    public function test_other_tokens_and_unverified_users_are_refused(): void
    {
        $this->getJson('/api/v1/calendar/events')->assertUnauthorized();
        $this->getJson('/api/v1/calendar/events', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->getJson('/api/v1/calendar/connections', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->getJson('/api/v1/calendar/events', $this->appHeaders($this->appToken(User::factory()->unverified()->create())))->assertForbidden();
    }
}
