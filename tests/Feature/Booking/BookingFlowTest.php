<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\BookingType;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\User;
use App\Notifications\BookingCalendarUnreadableNotification;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingCreatedNotification;
use App\Notifications\BookingGuestNotification;
use App\Support\Booking\BookingService;
use App\Support\Booking\SlotTaken;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\NormalizedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/** Booking pages end to end (BRAI-158): host setup, public slots, booking, guest cancel / reschedule. */
class BookingFlowTest extends TestCase
{
    use InteractsWithBoards, RefreshDatabase;

    private User $host;

    private BookingPage $page;

    private BookingType $type;

    private CalendarConnection $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        FakeCalendarProvider::reset();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 08:00', 'UTC')); // Friday; Monday is 2027-01-04

        $this->host = $this->editor;
        $this->host->update(['timezone' => 'Europe/Berlin']);
        $this->calendar = CalendarConnection::factory()->create(['user_id' => $this->host->id, 'provider' => 'fake', 'external_calendar_name' => 'Work']);
        $this->page = BookingPage::create([
            'user_id' => $this->host->id, 'workspace_id' => $this->workspace->id, 'public_slug' => 'max-member',
            'title' => 'Book Max', 'timezone' => 'Europe/Berlin', 'settings' => ['consent' => 'off'],
        ]);
        $this->type = $this->page->types()->create([
            'slug' => 'intro', 'name' => 'Intro call', 'duration_minutes' => 30,
            'availability' => ['weekly' => ['mon' => [['09:00', '11:00']]], 'overrides' => []],
            'rules' => ['min_notice_minutes' => 60],
            'location' => ['kind' => 'none', 'value' => null],
            'target_connection_id' => $this->calendar->id,
            'questions' => [['id' => 'topic', 'label' => 'Topic', 'type' => 'text', 'required' => true, 'options' => []]],
        ]);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        parent::tearDown();
    }

    private function slots(array $query = []): array
    {
        return $this->getJson(route('booking.slots', ['max-member', 'intro']).'?'.http_build_query($query + ['from' => '2027-01-04', 'to' => '2027-01-04', 'timezone' => 'Europe/Berlin']))
            ->assertOk()->json('slots');
    }

    private function book(string $start = '2027-01-04T08:00:00Z', array $data = []): TestResponse
    {
        return $this->postJson(route('booking.store', ['max-member', 'intro']), $data + [
            'start' => $start, 'name' => 'Gus Guest', 'email' => 'Gus@Example.test', 'timezone' => 'America/New_York',
            'answers' => ['topic' => 'Pricing', 'unknown' => 'dropped'],
        ]);
    }

    // ── public page + slots ─────────────────────────────────────────────

    public function test_the_public_page_shows_the_enabled_types_and_never_the_hosts_events(): void
    {
        $this->get('/book/max-member')->assertOk()->assertInertia(fn ($p) => $p->component('Booking/Public')
            ->where('page.title', 'Book Max')->has('page.types', 1)->where('page.types.0.slug', 'intro')->where('selected', 'intro'));

        FakeCalendarProvider::$events = [NormalizedEvent::timed('e1', 'Secret dentist', CarbonImmutable::parse('2027-01-04 08:30', 'UTC'), CarbonImmutable::parse('2027-01-04 09:00', 'UTC'), 'UTC')];
        $response = $this->getJson(route('booking.slots', ['max-member', 'intro']).'?from=2027-01-04&to=2027-01-04&timezone=Europe/Berlin');

        $this->assertSame(['2027-01-04T08:00:00Z', '2027-01-04T09:00:00Z', '2027-01-04T09:30:00Z'], $response->json('slots'));
        $this->assertStringNotContainsString('dentist', $response->getContent());
    }

    public function test_off_pages_off_types_and_hosts_who_left_the_workspace_are_404(): void
    {
        $this->type->update(['enabled' => false]);
        $this->get('/book/max-member/intro')->assertNotFound();

        $this->type->update(['enabled' => true]);
        $this->page->update(['enabled' => false]);
        $this->get('/book/max-member')->assertNotFound();

        $this->page->update(['enabled' => true]);
        $this->workspace->users()->detach($this->host->id);
        $this->get('/book/max-member')->assertNotFound();
    }

    public function test_a_slot_range_is_limited(): void
    {
        $this->getJson(route('booking.slots', ['max-member', 'intro']).'?from=2027-01-01&to=2027-03-01')->assertUnprocessable();
    }

    // ── booking ───────────────────────────────────────────────────────

    public function test_a_guest_books_the_event_lands_in_the_calendar_and_both_sides_are_told(): void
    {
        $this->book()->assertOk()->assertJsonPath('booking.type', 'Intro call')->assertJsonStructure(['manage_url']);

        $booking = Booking::sole();
        $this->assertSame('gus@example.test', $booking->guest_email);
        $this->assertSame(['topic' => 'Pricing'], $booking->answers);
        $this->assertSame('2027-01-04 08:00', $booking->starts_at->format('Y-m-d H:i'));
        $this->assertSame('new-1', $booking->external_event_id);

        [$call, $data] = FakeCalendarProvider::$calls[array_key_last(FakeCalendarProvider::$calls)];
        $this->assertSame('create', $call);
        $this->assertSame('Intro call: Gus Guest', $data['title']);
        $this->assertSame(['2027-01-04', '09:00', '09:30', 'Europe/Berlin'], [$data['start_date'], $data['start_time'], $data['end_time'], $data['timezone']]);
        $this->assertSame([['email' => 'gus@example.test', 'name' => 'Gus Guest']], $data['attendees']);
        $this->assertStringContainsString('Topic: Pricing', $data['description']);

        Notification::assertSentTo($this->host, BookingCreatedNotification::class);
        Notification::assertSentTo(new AnonymousNotifiable, BookingGuestNotification::class, fn ($n, $channels, $notifiable) => $n->kind === 'confirmed' && $notifiable->routes['mail'] === 'gus@example.test');

        // the slot is gone for the next visitor
        $this->assertNotContains('2027-01-04T08:00:00Z', $this->slots());
    }

    public function test_the_same_or_an_overlapping_time_cant_be_booked_twice(): void
    {
        $this->book()->assertOk();
        $this->book()->assertStatus(409);
        $this->assertSame(1, Booking::count());
    }

    public function test_a_second_page_of_the_same_host_cant_double_book(): void
    {
        $this->book()->assertOk();
        $other = BookingPage::create(['user_id' => $this->host->id, 'workspace_id' => $this->workspace->id, 'public_slug' => 'max-emergency', 'title' => 'SOS', 'timezone' => 'Europe/Berlin']);
        $type = $other->types()->create(['slug' => 'sos', 'name' => 'SOS', 'duration_minutes' => 15, 'availability' => ['weekly' => ['mon' => [['09:00', '10:00']]]]]);

        $this->expectException(SlotTaken::class);
        BookingService::book($type, CarbonImmutable::parse('2027-01-04 08:15', 'UTC'), ['name' => 'Ann', 'email' => 'ann@example.test']);
    }

    public function test_times_outside_the_hours_or_not_on_the_grid_are_refused(): void
    {
        $this->book('2027-01-04T07:00:00Z')->assertStatus(409); // 08:00 Berlin: before opening
        $this->book('2027-01-04T08:10:00Z')->assertStatus(409); // not a slot start
        $this->book('2027-01-01T08:30:00Z')->assertStatus(409); // within the notice (and a Friday)
    }

    public function test_answers_consent_honeypot_and_per_email_limit(): void
    {
        $this->book(data: ['answers' => []])->assertUnprocessable()->assertJsonValidationErrors('answers.topic');

        $this->page->update(['settings' => ['consent' => 'required', 'consent_text' => '{organization} may store my data.']]);
        $this->book()->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->book(data: ['consent' => true])->assertOk();
        $this->assertStringEndsWith('may store my data.', Booking::sole()->consent_text);

        $this->book('2027-01-04T08:30:00Z', ['website' => 'http://spam.example'])->assertOk();
        $this->assertSame(1, Booking::count()); // the bot got "ok", nothing was booked
    }

    public function test_a_failing_calendar_write_keeps_the_booking(): void
    {
        FakeCalendarProvider::$writable = false;

        $this->book()->assertOk();

        $this->assertNull(Booking::sole()->external_event_id);
        Notification::assertSentTo(new AnonymousNotifiable, BookingGuestNotification::class);
    }

    public function test_an_unreadable_calendar_is_ignored_marked_and_reported_once_a_day(): void
    {
        FakeCalendarProvider::$fail = 'The server did not answer.';

        $this->assertCount(4, $this->slots()); // the page still offers times
        $this->slots();
        Notification::assertSentToTimes($this->host, BookingCalendarUnreadableNotification::class, 1);

        $this->book()->assertOk();
        $this->assertSame([$this->calendar->id], Booking::sole()->unchecked_connection_ids);
    }

    public function test_also_create_a_card_puts_the_booking_on_the_board(): void
    {
        $column = $this->board->columns()->orderBy('position')->first();
        $this->page->update(['card_board_id' => $this->board->id, 'card_column_id' => $column->id]);

        $this->book()->assertOk();

        $item = Item::sole();
        $this->assertSame('Intro call: Gus Guest', $item->title);
        $this->assertSame($column->id, $item->column_id);
        $this->assertSame($this->host->id, $item->assigned_to);
        $this->assertSame('09:00', substr($item->due_time, 0, 5)); // the host's wall clock
        $this->assertSame($item->id, Booking::sole()->item_id);
        $this->assertStringContainsString('Pricing', $item->description);
    }

    // ── the guest's manage link ──────────────────────────────────────────

    public function test_the_guest_cancels_by_link_the_event_goes_and_the_host_is_told(): void
    {
        $url = $this->book()->json('manage_url');
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('manage.can_change', true));

        $this->postJson($url.'/cancel', ['reason' => 'Sick'])->assertOk()->assertJsonPath('booking.status', 'cancelled');

        $booking = Booking::sole();
        $this->assertSame([Booking::CANCELLED, 'guest', 'Sick'], [$booking->status, $booking->cancelled_by, $booking->cancel_reason]);
        $this->assertContains(['delete', 'new-1'], FakeCalendarProvider::$calls);
        Notification::assertSentTo($this->host, BookingCancelledNotification::class);
        $this->postJson($url.'/cancel')->assertStatus(410);
        $this->assertContains('2027-01-04T08:00:00Z', $this->slots()); // free again
    }

    public function test_the_guest_reschedules_by_link(): void
    {
        $url = $this->book()->json('manage_url');

        $this->postJson($url.'/reschedule', ['start' => '2027-01-04T09:30:00Z'])->assertOk()->assertJsonStructure(['manage_url']);

        $this->assertSame(1, Booking::confirmed()->count());
        $this->assertSame('2027-01-04 09:30', Booking::confirmed()->sole()->starts_at->format('Y-m-d H:i'));
        $old = Booking::where('status', Booking::CANCELLED)->sole();
        $this->assertStringStartsWith('Rescheduled to Mon 4 Jan', $old->cancel_reason);
        Notification::assertSentTo($this->host, BookingCreatedNotification::class, fn ($n) => $n->replaces?->is($old));
        Notification::assertNotSentTo($this->host, BookingCancelledNotification::class);
    }

    public function test_a_reschedule_to_a_taken_time_keeps_the_old_booking(): void
    {
        $url = $this->book()->json('manage_url');
        $this->book('2027-01-04T09:00:00Z', ['email' => 'ann@example.test'])->assertOk();

        $this->postJson($url.'/reschedule', ['start' => '2027-01-04T09:00:00Z'])->assertStatus(409);

        $this->assertSame(2, Booking::confirmed()->count());
    }

    public function test_a_past_booking_cant_be_changed_and_a_bad_token_is_404(): void
    {
        $url = $this->book()->json('manage_url');
        $this->travelTo(CarbonImmutable::parse('2027-01-05 08:00', 'UTC'));

        $this->postJson($url.'/cancel')->assertStatus(410);
        $this->get('/book/manage/'.str_repeat('a', 48))->assertNotFound();
    }

    // ── host side ──────────────────────────────────────────────────────

    public function test_the_host_creates_a_page_from_a_template_with_its_types(): void
    {
        $this->actingAs($this->host)->post(route('booking-pages.store'), ['workspace_id' => $this->workspace->id, 'template' => 'interview'])->assertRedirect();

        $page = BookingPage::where('title', 'Interview with us')->sole();
        $this->assertSame(['First interview', 'Technical interview'], $page->types->pluck('name')->all());
        $this->assertSame([30, 45], $page->types->pluck('duration_minutes')->all());
        $this->assertSame($this->calendar->id, $page->types->first()->target_connection_id);
        $this->assertSame('Europe/Berlin', $page->timezone);
    }

    public function test_only_the_owner_sees_and_changes_a_page(): void
    {
        $this->actingAs($this->host)->get(route('booking-pages.show', $this->page->uuid))->assertOk();
        $this->actingAs($this->viewer)->get(route('booking-pages.show', $this->page->uuid))->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('booking-pages.destroy', $this->page->uuid))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('booking-pages.store'), ['workspace_id' => User::factory()->create()->id, 'template' => 'blank'])->assertForbidden();
    }

    public function test_busy_sources_keep_only_the_hosts_own_calendars_and_slugs_are_checked(): void
    {
        $foreign = CalendarConnection::factory()->create(['provider' => 'fake']);
        $payload = [
            'title' => 'Book Max', 'intro' => null, 'timezone' => 'Europe/Berlin', 'enabled' => true, 'public_slug' => 'max-member',
            'busy_sources' => ['connections' => [$this->calendar->id, $foreign->id], 'issues' => false, 'tasks' => true, 'all_day_blocks' => false],
            'card_board_id' => null, 'card_column_id' => null, 'consent' => 'off', 'consent_text' => null,
        ];

        $this->actingAs($this->host)->put(route('booking-pages.update', $this->page->uuid), $payload)->assertSessionHasNoErrors();
        $this->assertSame([$this->calendar->id], $this->page->fresh()->busySources()['connections']);
        $this->assertFalse($this->page->fresh()->busySources()['issues']);

        $this->actingAs($this->host)->put(route('booking-pages.update', $this->page->uuid), ['public_slug' => 'manage'] + $payload)->assertSessionHasErrors('public_slug');
    }

    public function test_meeting_types_are_the_users_own_lengths_and_hours(): void
    {
        $this->actingAs($this->host)->post(route('booking-types.store', $this->page->uuid), ['name' => 'Quick question', 'duration_minutes' => 15])->assertSessionHasNoErrors();
        $quick = BookingType::where('name', 'Quick question')->sole();
        $this->assertSame(15, $quick->duration_minutes);

        $update = [
            'name' => 'Workshop', 'slug' => 'workshop', 'description' => null, 'duration_minutes' => 45, 'enabled' => true,
            'target_connection_id' => null, 'location' => ['kind' => 'link', 'value' => 'https://meet.example.com/max'],
            'availability' => ['weekly' => ['tue' => [['13:00', '17:00']]], 'overrides' => ['2027-01-05' => []]],
            'rules' => ['step_minutes' => 15, 'buffer_before' => 0, 'buffer_after' => 10, 'min_notice_minutes' => 60, 'horizon_days' => 30, 'daily_max' => 3],
            'questions' => [['label' => 'Team size', 'type' => 'choice', 'options' => ['1–5', '6+'], 'required' => true]],
        ];
        $this->actingAs($this->host)->put(route('booking-types.update', [$this->page->uuid, $quick->id]), $update)->assertSessionHasNoErrors();
        $quick->refresh();
        $this->assertSame([45, 'workshop', 3], [$quick->duration_minutes, $quick->slug, $quick->rules['daily_max']]);
        $this->assertSame([['13:00', '17:00']], $quick->availability['weekly']['tue']);

        $bad = ['availability' => ['weekly' => ['mon' => [['17:00', '09:00']]]]] + $update;
        $this->actingAs($this->host)->put(route('booking-types.update', [$this->page->uuid, $quick->id]), $bad)->assertSessionHasErrors('availability.weekly.mon');
        $foreign = CalendarConnection::factory()->create(['provider' => 'fake']);
        $this->actingAs($this->host)->put(route('booking-types.update', [$this->page->uuid, $quick->id]), ['target_connection_id' => $foreign->id] + $update)->assertSessionHasErrors('target_connection_id');
    }

    public function test_the_host_cancels_and_the_guest_is_emailed(): void
    {
        $this->book()->assertOk();

        $this->actingAs($this->host)->post(route('bookings.cancel', Booking::sole()->uuid), ['reason' => 'Ill'])->assertRedirect();

        $this->assertSame('host', Booking::sole()->cancelled_by);
        Notification::assertSentTo(new AnonymousNotifiable, BookingGuestNotification::class, fn ($n) => $n->kind === 'cancelled');
        Notification::assertNotSentTo($this->host, BookingCancelledNotification::class);

        $this->actingAs($this->viewer)->post(route('bookings.cancel', Booking::sole()->uuid))->assertForbidden();
    }

    public function test_the_host_preview_lists_the_next_free_times(): void
    {
        $this->actingAs($this->host)->getJson(route('booking-types.preview', [$this->page->uuid, $this->type->id]))
            ->assertOk()->assertJsonPath('slots.0', '2027-01-04T08:00:00Z');
    }

    public function test_guests_get_one_reminder_a_day_before_but_not_for_last_minute_bookings(): void
    {
        $this->book()->assertOk(); // booked Friday 08:00 UTC for Monday 08:00 UTC: 72 h ahead
        $this->travelTo(CarbonImmutable::parse('2027-01-03 09:00', 'UTC')); // 23 h before

        $this->artisan('app:send-booking-reminders')->assertSuccessful();
        $this->artisan('app:send-booking-reminders')->assertSuccessful();

        Notification::assertSentTimes(BookingGuestNotification::class, 2); // the confirmation + one reminder
        $this->assertNotNull(Booking::sole()->reminded_at);

        // booked 30 h ahead: no reminder
        $this->travelTo(CarbonImmutable::parse('2027-01-03 02:00', 'UTC'));
        $this->book('2027-01-04T08:30:00Z', ['email' => 'ann@example.test'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2027-01-03 09:00', 'UTC'));
        $this->artisan('app:send-booking-reminders')->assertSuccessful();
        Notification::assertSentTimes(BookingGuestNotification::class, 3); // only Ann's confirmation
    }

    public function test_bookings_show_in_the_hosts_calendar_once(): void
    {
        $this->book()->assertOk(); // written to the fake calendar as event "new-1"
        $this->type->update(['target_connection_id' => null]);
        $this->book('2027-01-04T09:00:00Z', ['email' => 'ann@example.test', 'name' => 'Ann'])->assertOk(); // only in Brainitive

        FakeCalendarProvider::$events = [NormalizedEvent::timed('new-1', 'Intro call: Gus Guest', CarbonImmutable::parse('2027-01-04 08:00', 'UTC'), CarbonImmutable::parse('2027-01-04 08:30', 'UTC'), 'UTC')];
        $this->actingAs($this->host)->get(route('calendar.index', ['view' => 'week', 'date' => '2027-01-04']))
            ->assertInertia(fn ($p) => $p
                ->where('events', function ($events) {
                    $bookings = collect($events)->where('type', 'booking')->values();
                    $this->assertSame(['Intro call: Gus Guest'], collect($events)->where('type', 'external_event')->pluck('title')->values()->all());
                    $this->assertSame([['Intro call: Ann', '10:00']], $bookings->map(fn ($e) => [$e['title'], $e['start_time']])->all());

                    return true;
                }));

        // switched off in the filter
        $this->actingAs($this->host)->get(route('calendar.index', ['view' => 'week', 'date' => '2027-01-04', 'sources' => ['board', 'list', 'external']]))
            ->assertInertia(fn ($p) => $p->where('events', fn ($events) => collect($events)->where('type', 'booking')->isEmpty()));
    }
}
