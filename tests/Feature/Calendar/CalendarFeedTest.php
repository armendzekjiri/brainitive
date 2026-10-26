<?php

namespace Tests\Feature\Calendar;

use App\Http\Controllers\CalendarFeedController;
use App\Models\Board;
use App\Models\CalendarFeed;
use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/** The private calendar feed (BRAI-142): managing it, and what calendar apps get. */
class CalendarFeedTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00', 'UTC'));
        $this->host = parse_url(config('app.url'), PHP_URL_HOST);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function feed(array $options = []): CalendarFeed
    {
        return CalendarFeed::issue($this->editor, $options);
    }

    private function fetch(CalendarFeed|string $feed, array $headers = []): TestResponse
    {
        $token = $feed instanceof CalendarFeed ? $feed->token : $feed;

        return $this->withHeaders($headers)->get(route('calendar-feed.ics', $token));
    }

    /** The body with folded lines joined again. */
    private function ics(TestResponse $response): string
    {
        return str_replace("\r\n ", '', $response->getContent());
    }

    public function test_the_owner_turns_the_feed_on_once_and_sees_the_subscribe_links(): void
    {
        $this->actingAs($this->editor)->getJson(route('calendar-feed.show'))->assertOk()->assertJsonPath('feed', null)
            ->assertJsonPath('defaults.assigned', true)->assertJsonPath('defaults.descriptions', false);

        $response = $this->postJson(route('calendar-feed.store'), ['options' => ['created' => true]])->assertCreated();
        $url = $response->json('feed.url');
        $this->assertMatchesRegularExpression('#/calendar/feed/[A-Za-z0-9]{48}\.ics$#', $url);
        $this->assertStringStartsWith('webcal://', $response->json('feed.links.apple'));
        $this->assertStringStartsWith('https://calendar.google.com/calendar/r?cid=webcal%3A%2F%2F', $response->json('feed.links.google'));
        $this->assertStringStartsWith('https://outlook.live.com/calendar/0/addfromweb?url=http', $response->json('feed.links.outlook'));
        $this->assertTrue($response->json('feed.options.created'));

        // a second "create" keeps the same URL (one active feed per user)
        $this->postJson(route('calendar-feed.store'), ['options' => ['created' => false]])->assertOk()->assertJsonPath('feed.url', $url);
        $this->assertSame(1, CalendarFeed::active()->count());

        // the token is stored hashed + encrypted, never in plain text
        $row = \DB::table('calendar_feeds')->first();
        $token = basename($url, '.ics');
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, $row->token);
    }

    public function test_the_feed_lists_assigned_issues_as_all_day_events_with_a_stable_uid(): void
    {
        $item = $this->item(['title' => 'Ship it; now, please', 'assigned_to' => $this->editor->id, 'start_date' => '2026-11-05', 'due_date' => '2026-11-07']);
        $this->item(['title' => 'Somebody else’s', 'assigned_to' => $this->viewer->id, 'due_date' => '2026-11-05']);
        $this->item(['title' => 'Too far back', 'assigned_to' => $this->editor->id, 'due_date' => '2026-08-01']);

        $response = $this->fetch($this->feed())->assertOk();

        $this->assertSame('text/calendar; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertEmpty($response->headers->getCookies(), 'no session cookie');
        $body = $this->ics($response);
        $this->assertStringContainsString("X-WR-CALNAME:Brainitive — {$this->editor->name}\r\n", $body);
        $this->assertStringContainsString("UID:item-{$item->id}@{$this->host}\r\n", $body);
        $this->assertStringContainsString("SUMMARY:[{$item->key}] Ship it\\; now\\, please\r\n", $body);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261105\r\nDTEND;VALUE=DATE:20261108\r\n", $body);
        $this->assertStringContainsString('URL:'.route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), $body);
        $this->assertStringNotContainsString('Somebody else', $body);
        $this->assertStringNotContainsString('Too far back', $body);
        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
    }

    public function test_timed_dates_are_the_owners_wall_clock_written_in_utc(): void
    {
        $this->editor->update(['timezone' => 'Europe/Berlin']); // UTC+1 in November
        $item = $this->item(['assigned_to' => $this->editor->id, 'start_date' => '2026-11-05', 'start_time' => '09:00', 'due_date' => '2026-11-05', 'due_time' => '10:30']);
        $dueOnly = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-06', 'due_time' => '17:00']);

        $body = $this->ics($this->fetch($this->feed()));

        $this->assertMatchesRegularExpression("/UID:item-{$item->id}@[^\r]+\r\nDTSTAMP:\d{8}T\d{6}Z\r\nDTSTART:20261105T080000Z\r\nDTEND:20261105T093000Z\r\n/", $body);
        $this->assertMatchesRegularExpression("/UID:item-{$dueOnly->id}@[^\r]+\r\nDTSTAMP:\d{8}T\d{6}Z\r\nDTSTART:20261106T153000Z\r\nDTEND:20261106T160000Z\r\n/", $body);
    }

    public function test_access_is_checked_again_on_every_fetch(): void
    {
        $item = $this->item(['title' => 'Secret roadmap', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-05']);
        $feed = $this->feed();
        $this->assertStringContainsString('Secret roadmap', $this->fetch($feed)->getContent());

        $this->project->users()->detach($this->editor->id);

        $body = $this->fetch($feed)->assertOk()->getContent();
        $this->assertStringNotContainsString('Secret roadmap', $body);
        $this->assertStringNotContainsString("item-{$item->id}@", $body);
    }

    public function test_reset_gives_a_new_url_and_the_old_one_stops_working(): void
    {
        $this->actingAs($this->editor)->postJson(route('calendar-feed.store'))->assertCreated();
        $old = CalendarFeed::activeFor($this->editor)->token;
        $this->fetch($old)->assertOk();

        $new = $this->actingAs($this->editor)->postJson(route('calendar-feed.reset'))->assertOk()->json('feed.url');
        $this->assertStringNotContainsString($old, $new);
        $this->fetch($old)->assertNotFound();
        $this->get($new)->assertOk();

        // turning it off: the URL 404s and the dialog offers to create one again
        $this->actingAs($this->editor)->deleteJson(route('calendar-feed.destroy'))->assertOk()->assertJsonPath('feed', null);
        $this->get($new)->assertNotFound();
        $this->fetch(str_repeat('a', 48))->assertNotFound();
        $this->get('/calendar/feed/short.ics')->assertNotFound();
        $this->assertSame(0, CalendarFeed::active()->count());
    }

    public function test_a_suspended_owner_has_no_feed(): void
    {
        $feed = $this->feed();
        $this->editor->forceFill(['suspended_at' => now()])->save();

        $this->fetch($feed)->assertNotFound();
    }

    public function test_options_choose_what_goes_in(): void
    {
        $created = $this->item(['title' => 'Made by me', 'created_by' => $this->editor->id, 'due_date' => '2026-11-05']);
        $done = $this->item(['title' => 'Finished', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-05', 'column_id' => $this->column('done')]);
        $described = $this->item(['title' => 'Has notes', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-05', 'description' => '<p>Bring the <b>contract</b></p>']);

        $defaults = $this->fetch($this->feed())->getContent();
        $this->assertStringNotContainsString('Made by me', $defaults);  // "issues I created" is off by default
        $this->assertStringNotContainsString('Finished', $defaults);    // completed work is left out
        $this->assertStringContainsString('Has notes', $defaults);
        $this->assertStringNotContainsString('contract', $defaults);    // no descriptions by default

        $all = $this->ics($this->fetch($this->feed(['created' => true, 'completed' => true, 'descriptions' => true])));
        $this->assertStringContainsString("SUMMARY:[{$created->key}] Made by me", $all);
        $this->assertStringContainsString("SUMMARY:✓ [{$done->key}] Finished", $all);
        $this->assertStringContainsString('DESCRIPTION:Bring the contract\\n\\n'.route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $described->id]), $all);

        $none = $this->fetch($this->feed(['assigned' => false, 'tasks' => false, 'my_day' => false, 'focus' => false]))->getContent();
        $this->assertSame(0, substr_count($none, 'BEGIN:VEVENT'));
    }

    public function test_the_project_filter_keeps_only_the_chosen_projects(): void
    {
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'key' => 'OTH']);
        $other->users()->attach($this->editor->id, ['role' => 'editor']);
        $otherBoard = Board::factory()->create(['project_id' => $other->id, 'created_by' => $this->editor->id]);
        $this->item(['title' => 'In BRAI', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-05']);
        Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id, 'title' => 'In OTH', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-05']);
        $foreign = Project::factory()->create(['created_by' => $this->outsider->id]);

        $this->actingAs($this->editor)->postJson(route('calendar-feed.store'), ['options' => ['project_ids' => [$other->id, $foreign->id]]])
            ->assertCreated()->assertJsonPath('feed.options.project_ids', [$other->id]); // only projects they can open

        $body = $this->fetch(CalendarFeed::activeFor($this->editor))->getContent();
        $this->assertStringContainsString('In OTH', $body);
        $this->assertStringNotContainsString('In BRAI', $body);

        $this->patchJson(route('calendar-feed.update'), ['options' => ['assigned' => 'nope']])->assertUnprocessable();
    }

    public function test_tasks_my_day_and_focus_sessions(): void
    {
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $weekly = TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'Water the plants', 'assignee_id' => $this->editor->id, 'due_date' => '2026-10-01', 'recurrence' => 'weekly']);
        $personal = TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'Personal errand', 'assignee_id' => null, 'created_by' => $this->editor->id, 'due_date' => '2026-11-03']);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'Not mine', 'assignee_id' => $this->viewer->id, 'due_date' => '2026-11-03']);
        $planned = $this->item(['title' => 'Planned today']);
        $entry = MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $planned->id, 'plan_date' => '2026-11-01', 'position' => 0]);
        $session = TimeEntry::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'item_id' => $planned->id, 'user_id' => $this->editor->id,
            'spent_on' => '2026-10-30', 'minutes' => 45, 'source' => TimeEntry::SOURCE_TIMER, 'item_key' => $planned->key, 'item_title' => $planned->title,
        ]);
        $session->forceFill(['created_at' => Carbon::parse('2026-10-30 15:00', 'UTC')])->save();

        $body = $this->ics($this->fetch($this->feed()));

        $this->assertMatchesRegularExpression("/UID:task-{$weekly->id}@[^\r]+\r\nDTSTAMP:[^\r]+\r\nDTSTART;VALUE=DATE:20261001\r\nDTEND;VALUE=DATE:20261002\r\nRRULE:FREQ=WEEKLY\r\nSUMMARY:\[BRAI\] Water the plants\r\n/", $body);
        $this->assertStringContainsString("UID:task-{$personal->id}@", $body);
        $this->assertStringContainsString('#task-'.$personal->id, $body);
        $this->assertStringNotContainsString('Not mine', $body);
        $this->assertMatchesRegularExpression("/UID:myday-{$entry->id}@[^\r]+\r\nDTSTAMP:[^\r]+\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\nSUMMARY:My Day: \[{$planned->key}\] Planned today\r\n/", $body);
        $this->assertMatchesRegularExpression("/UID:focus-{$session->id}@[^\r]+\r\nDTSTAMP:[^\r]+\r\nDTSTART:20261030T141500Z\r\nDTEND:20261030T150000Z\r\nSUMMARY:Focus: \[{$planned->key}\] Planned today\r\n/", $body);
    }

    public function test_unchanged_feeds_answer_304_with_the_etag(): void
    {
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-05']);
        $feed = $this->feed();
        $first = $this->fetch($feed)->assertOk();
        $etag = $first->headers->get('ETag');
        $lastModified = $first->headers->get('Last-Modified');
        $this->assertNotEmpty($etag);
        $this->assertNotEmpty($lastModified);

        Carbon::setTestNow(now()->addMinutes(20));
        $this->fetch($feed, ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
        $this->fetch($feed, ['If-Modified-Since' => $lastModified])->assertStatus(304);

        // a change → a new body and a new ETag
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-06']);
        $changed = $this->fetch($feed, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));

        $feed->refresh();
        $this->assertSame(4, $feed->fetch_count);
        $this->assertNotNull($feed->last_fetched_at);
    }

    public function test_the_public_route_is_throttled_with_its_own_counter_and_has_no_session(): void
    {
        $route = Route::getRoutes()->getByName('calendar-feed.ics');
        $middleware = $route->gatherMiddleware();

        $this->assertContains('throttle:600,1,calendar-feed.ics', $middleware); // per IP: Google / Outlook share a few
        $this->assertContains(StartSession::class, $route->excludedMiddleware());

        // each feed has its own limit, whatever the IP: the 31st fetch in a minute is refused…
        $feed = $this->feed();
        foreach (range(1, CalendarFeedController::FETCHES_PER_MINUTE) as $i) {
            $this->fetch($feed)->assertOk();
        }
        $this->fetch($feed)->assertStatus(429)->assertHeader('Retry-After');

        // …while another user's feed (same IP, like Google's servers) still works
        $this->fetch(CalendarFeed::issue($this->viewer))->assertOk();
    }

    public function test_guests_cannot_manage_feeds(): void
    {
        $this->getJson(route('calendar-feed.show'))->assertUnauthorized();
        $this->postJson(route('calendar-feed.store'))->assertUnauthorized();
        $this->postJson(route('calendar-feed.reset'))->assertUnauthorized();
        $this->actingAs($this->editor)->postJson(route('calendar-feed.reset'))->assertNotFound(); // nothing to reset yet
    }
}
