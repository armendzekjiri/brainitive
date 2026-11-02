<?php

namespace Tests\Feature\AppApi;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\BookingType;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Notifications\BookingGuestNotification;
use App\Support\Calendar\CalendarProviders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Calendar\FakeCalendarProvider;
use Tests\TestCase;

/**
 * App API v1 bookings, host side (BRAI-419): the user's booking pages (read), their bookings by state, one
 * booking, and the host's cancel (BookingService, shared with the web). Booking pages are personal: another
 * user's page or booking is a 404, as is a page in a workspace the host has left.
 */
class BookingsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private BookingPage $page;

    private BookingType $type;

    private CalendarConnection $calendar;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        FakeCalendarProvider::reset();
        CalendarProviders::fake('fake', FakeCalendarProvider::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2027-01-04 08:00', 'UTC'));

        $this->calendar = CalendarConnection::factory()->provider('fake')->create(['user_id' => $this->editor->id]);
        [$this->page, $this->type] = $this->bookingPage($this->editor, 'max-member');
        $this->token = $this->appToken($this->editor);
    }

    protected function tearDown(): void
    {
        CalendarProviders::fake('fake', null);
        parent::tearDown();
    }

    /** @return array{0: BookingPage, 1: BookingType} */
    private function bookingPage(User $host, string $slug): array
    {
        $page = BookingPage::create([
            'user_id' => $host->id, 'workspace_id' => $this->workspace->id, 'public_slug' => $slug,
            'title' => 'Book '.$slug, 'timezone' => 'Europe/Berlin', 'settings' => ['consent' => 'off'],
        ]);
        $type = $page->types()->create([
            'slug' => 'intro', 'name' => 'Intro call', 'duration_minutes' => 30,
            'availability' => ['weekly' => ['mon' => [['09:00', '11:00']]], 'overrides' => []],
            'location' => ['kind' => 'none', 'value' => null],
            'questions' => [['id' => 'topic', 'label' => 'Topic', 'type' => 'text', 'required' => true, 'options' => []]],
        ]);

        return [$page, $type];
    }

    private function booking(BookingType $type, string $start, array $attributes = []): Booking
    {
        $start = CarbonImmutable::parse($start, 'UTC');

        return Booking::create($attributes + [
            'booking_type_id' => $type->id, 'user_id' => $type->page->user_id,
            'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
            'guest_name' => 'Gina Guest', 'guest_email' => 'gina@client.test', 'guest_timezone' => 'Europe/Paris',
            'answers' => ['topic' => 'Pricing'], 'status' => Booking::CONFIRMED,
            'manage_token_hash' => hash('sha256', uniqid('', true)),
        ]);
    }

    public function test_booking_pages_with_types_and_upcoming_counts(): void
    {
        $this->booking($this->type, '2027-01-04 09:00');
        $this->booking($this->type, '2027-01-03 09:00'); // over
        $this->bookingPage($this->viewer, 'someone-else');

        $this->getJson('/api/v1/booking-pages', $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $this->page->uuid)
            ->assertJsonPath('data.0.upcoming_count', 1)
            ->assertJsonPath('data.0.types_count', 1)
            ->assertJsonPath('data.0.types.0.name', 'Intro call')
            ->assertJsonPath('data.0.types.0.public_url', route('booking.public.type', ['max-member', 'intro']))
            ->assertJsonPath('data.0.workspace.slug', $this->workspace->slug);
    }

    public function test_bookings_by_state_paginated(): void
    {
        $soon = $this->booking($this->type, '2027-01-04 09:00');
        $later = $this->booking($this->type, '2027-01-11 09:00');
        $past = $this->booking($this->type, '2027-01-01 09:00');
        $cancelled = $this->booking($this->type, '2027-01-05 09:00', ['status' => Booking::CANCELLED, 'cancelled_by' => 'guest']);
        [, $otherType] = $this->bookingPage($this->viewer, 'someone-else');
        $this->booking($otherType, '2027-01-04 10:00');

        $first = $this->getJson('/api/v1/bookings?limit=1', $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $soon->uuid)
            ->assertJsonPath('data.0.guest_email', 'gina@client.test')
            ->assertJsonPath('data.0.answers', [['question' => 'Topic', 'answer' => 'Pricing']])
            ->assertJsonPath('data.0.page.uuid', $this->page->uuid);
        $this->getJson('/api/v1/bookings?limit=1&cursor='.urlencode($first->json('next_cursor')), $this->appHeaders($this->token))
            ->assertOk()->assertJsonPath('data.0.uuid', $later->uuid)->assertJsonPath('next_cursor', null);

        $this->getJson('/api/v1/bookings?status=past', $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $past->uuid);
        $this->getJson('/api/v1/bookings?status=cancelled', $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $cancelled->uuid)->assertJsonPath('data.0.cancelled_by', 'guest');
        $this->getJson('/api/v1/bookings?page='.$this->page->uuid, $this->appHeaders($this->token))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/bookings?page='.$otherType->page->uuid, $this->appHeaders($this->token))->assertNotFound();
        $this->getJson('/api/v1/bookings?status=soon', $this->appHeaders($this->token))->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_one_booking_and_the_hosts_cancel(): void
    {
        $booking = $this->booking($this->type, '2027-01-04 09:00', ['calendar_connection_id' => $this->calendar->id, 'external_event_id' => 'ev-1']);

        $this->getJson("/api/v1/bookings/{$booking->uuid}", $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.in_calendar', true);

        $this->postJson("/api/v1/bookings/{$booking->uuid}/cancel", ['reason' => 'Sick today'], $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'host')
            ->assertJsonPath('data.cancel_reason', 'Sick today');
        $this->assertContains(['delete', 'ev-1'], FakeCalendarProvider::$calls);
        Notification::assertSentOnDemand(BookingGuestNotification::class);

        // again: nothing changes, no second email
        $this->postJson("/api/v1/bookings/{$booking->uuid}/cancel", [], $this->appHeaders($this->token))->assertOk()->assertJsonPath('data.cancel_reason', 'Sick today');
        Notification::assertSentOnDemandTimes(BookingGuestNotification::class, 1);

        $this->postJson("/api/v1/bookings/{$booking->uuid}/cancel", ['reason' => str_repeat('x', 1001)], $this->appHeaders($this->token))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_other_hosts_bookings_and_left_workspaces_are_404(): void
    {
        $booking = $this->booking($this->type, '2027-01-04 09:00');
        $other = $this->appToken($this->viewer);

        $this->getJson("/api/v1/bookings/{$booking->uuid}", $this->appHeaders($other))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson("/api/v1/bookings/{$booking->uuid}/cancel", [], $this->appHeaders($other))->assertNotFound();
        $this->getJson('/api/v1/bookings', $this->appHeaders($other))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/bookings/not-a-uuid', $this->appHeaders($this->token))->assertNotFound();
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);

        // the host left the workspace: the page and its bookings are gone for them (as on the web)
        $this->workspace->users()->detach($this->editor->id);
        $this->getJson('/api/v1/booking-pages', $this->appHeaders($this->token))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/bookings/{$booking->uuid}", $this->appHeaders($this->token))->assertNotFound();
    }

    public function test_tokens_of_other_clients_and_no_token_are_401(): void
    {
        $this->getJson('/api/v1/bookings')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/booking-pages', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
