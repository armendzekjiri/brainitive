<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\BookingType;
use App\Models\CalendarConnection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Support\Booking\BusyTimes;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/** What blocks a booking page's time (BRAI-158): bookings of all pages + the page's own busy sources. */
class BusyTimesTest extends TestCase
{
    use InteractsWithBoards, RefreshDatabase;

    private BookingPage $page;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        FakeCalendarProvider::reset();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);

        $this->editor->update(['timezone' => 'Europe/Berlin']);
        $this->page = $this->bookingPage();
        $this->from = CarbonImmutable::parse('2027-01-04 00:00', 'Europe/Berlin');
        $this->to = CarbonImmutable::parse('2027-01-05 00:00', 'Europe/Berlin');
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        parent::tearDown();
    }

    private function bookingPage(array $attributes = []): BookingPage
    {
        return BookingPage::create($attributes + [
            'user_id' => $this->editor->id,
            'workspace_id' => $this->workspace->id,
            'public_slug' => BookingPage::freeSlug('erin'),
            'title' => 'Book Erin',
            'timezone' => 'Europe/Berlin',
        ]);
    }

    private function connection(string $name = 'Work'): CalendarConnection
    {
        return CalendarConnection::factory()->create(['user_id' => $this->editor->id, 'provider' => 'fake', 'external_calendar_name' => $name]);
    }

    /** @return list<string> "H:i-H:i" in Berlin time, sorted */
    private function busy(?BookingPage $page = null, bool $fresh = false): array
    {
        $busy = BusyTimes::for($page ?? $this->page->fresh(), $this->from, $this->to, $fresh);
        $out = array_map(fn ($i) => $i[0]->setTimezone('Europe/Berlin')->format('H:i').'-'.$i[1]->setTimezone('Europe/Berlin')->format('H:i'), $busy->intervals);
        sort($out);

        return $out;
    }

    private function booking(BookingPage $page, string $start, string $status = Booking::CONFIRMED): Booking
    {
        $type = BookingType::create([
            'booking_page_id' => $page->id, 'slug' => 'call-'.$page->id, 'name' => 'Call', 'duration_minutes' => 30,
            'availability' => ['weekly' => []],
        ]);
        $starts = CarbonImmutable::parse($start, 'Europe/Berlin');

        return Booking::create([
            'booking_type_id' => $type->id, 'user_id' => $page->user_id, 'starts_at' => $starts, 'ends_at' => $starts->addMinutes(30),
            'guest_name' => 'Gus Guest', 'guest_email' => 'gus@example.test', 'status' => $status,
            'manage_token_hash' => Booking::newManageToken()[1],
        ]);
    }

    public function test_bookings_of_every_page_of_the_host_block_but_cancelled_ones_dont(): void
    {
        $other = $this->bookingPage(['public_slug' => 'erin-emergency', 'title' => 'Emergency']);
        $this->booking($other, '2027-01-04 10:00');
        $this->booking($this->page, '2027-01-04 14:00', Booking::CANCELLED);

        $this->assertSame(['10:00-10:30'], $this->busy());
    }

    public function test_calendar_events_block_in_absolute_time_and_all_day_only_when_asked(): void
    {
        $this->connection();
        FakeCalendarProvider::$events = [
            NormalizedEvent::timed('e1', 'Dentist', CarbonImmutable::parse('2027-01-04 08:00', 'UTC'), CarbonImmutable::parse('2027-01-04 09:00', 'UTC'), 'UTC'),
            NormalizedEvent::allDay('e2', 'Holiday', '2027-01-04'),
        ];

        $this->assertSame(['09:00-10:00'], $this->busy());
        $this->assertSame([['list', 'UTC']], FakeCalendarProvider::$calls);

        $this->page->update(['busy_sources' => ['all_day_blocks' => true] + BookingPage::DEFAULT_BUSY_SOURCES]);
        $this->assertContains('00:00-00:00', $this->busy(fresh: true)); // the whole local day
    }

    public function test_a_deselected_calendar_doesnt_block(): void
    {
        $work = $this->connection('Work');
        $this->connection('Personal');
        FakeCalendarProvider::$events = [NormalizedEvent::timed('e1', 'x', CarbonImmutable::parse('2027-01-04 08:00', 'UTC'), CarbonImmutable::parse('2027-01-04 09:00', 'UTC'), 'UTC')];

        $this->page->update(['busy_sources' => ['connections' => [$work->id]] + BookingPage::DEFAULT_BUSY_SOURCES]);
        $this->assertSame(['09:00-10:00'], $this->busy());
        $this->assertCount(1, FakeCalendarProvider::$calls); // Personal isn't even asked

        $this->page->update(['busy_sources' => ['connections' => []] + BookingPage::DEFAULT_BUSY_SOURCES]);
        $this->assertSame([], $this->busy());
    }

    public function test_another_users_calendar_in_the_list_is_ignored(): void
    {
        $foreign = CalendarConnection::factory()->create(['provider' => 'fake']);
        FakeCalendarProvider::$events = [NormalizedEvent::timed('e1', 'x', CarbonImmutable::parse('2027-01-04 08:00', 'UTC'), CarbonImmutable::parse('2027-01-04 09:00', 'UTC'), 'UTC')];

        $this->page->update(['busy_sources' => ['connections' => [$foreign->id]] + BookingPage::DEFAULT_BUSY_SOURCES]);

        $this->assertSame([], $this->busy());
        $this->assertSame([], FakeCalendarProvider::$calls);
    }

    public function test_an_unreadable_calendar_is_skipped_reported_and_marked(): void
    {
        $broken = $this->connection();
        FakeCalendarProvider::$fail = 'The server did not answer.';
        $this->booking($this->page, '2027-01-04 10:00');

        $busy = BusyTimes::for($this->page, $this->from, $this->to);

        $this->assertCount(1, $busy->intervals); // the booking still blocks
        $this->assertSame([$broken->id], array_keys($busy->failed));
        $this->assertSame('The server did not answer.', $broken->fresh()->sync_error);
    }

    public function test_calendar_reads_are_cached_unless_fresh(): void
    {
        $this->connection();

        $this->busy();
        $this->busy();
        $this->assertCount(1, FakeCalendarProvider::$calls);

        $this->busy(fresh: true);
        $this->assertCount(2, FakeCalendarProvider::$calls);
    }

    public function test_timed_issues_assigned_to_the_host_block_unless_done_or_switched_off(): void
    {
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2027-01-04', 'due_time' => '15:00']);
        $this->item(['assigned_to' => $this->editor->id, 'start_date' => '2027-01-04', 'start_time' => '11:00', 'due_date' => '2027-01-04', 'due_time' => '12:30']);
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2027-01-04']); // no time: doesn't block
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2027-01-04', 'due_time' => '16:00', 'completed_at' => now()]);
        $this->item(['assigned_to' => $this->outsider->id, 'due_date' => '2027-01-04', 'due_time' => '17:00']);

        $this->assertSame(['11:00-12:30', '15:00-15:30'], $this->busy());

        $this->page->update(['busy_sources' => ['issues' => false] + BookingPage::DEFAULT_BUSY_SOURCES]);
        $this->assertSame([], $this->busy());
    }

    public function test_timed_tasks_of_the_host_block_including_own_unassigned_ones(): void
    {
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'assignee_id' => $this->editor->id, 'due_date' => '2027-01-04', 'due_time' => '09:00']);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'assignee_id' => null, 'created_by' => $this->editor->id, 'due_date' => '2027-01-04', 'due_time' => '13:00']);
        TaskListItem::factory()->done()->create(['task_list_id' => $list->id, 'assignee_id' => $this->editor->id, 'due_date' => '2027-01-04', 'due_time' => '14:00']);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'assignee_id' => $this->outsider->id, 'created_by' => $this->editor->id, 'due_date' => '2027-01-04', 'due_time' => '15:00']);

        $this->assertSame(['09:00-09:30', '13:00-13:30'], $this->busy());

        $this->page->update(['busy_sources' => ['tasks' => false] + BookingPage::DEFAULT_BUSY_SOURCES]);
        $this->assertSame([], $this->busy());
    }
}
