<?php

namespace App\Notifications;

use App\Models\BookingPage;
use App\Models\CalendarConnection;

/**
 * A calendar a booking page checks for busy time couldn't be read (BRAI-158; category: bookings).
 * The page keeps offering times without it, so bookings may overlap its events until it's reconnected.
 */
class BookingCalendarUnreadableNotification extends CatalogNotification
{
    public function __construct(public readonly BookingPage $page, public readonly CalendarConnection $calendar) {}

    public function catalogType(): string
    {
        return 'booking_calendar_unreadable';
    }

    protected function message(object $notifiable): array
    {
        $calendar = $this->calendar->external_calendar_name ?: $this->calendar->account_email ?: 'A calendar';

        return [
            'title' => "Couldn’t check “{$calendar}” for {$this->page->title}",
            'body' => 'Bookings may overlap with its events until it works again. Reconnect it under Calendar → Connected calendars.',
            'url' => route('booking-pages.show', ['bookingPage' => $this->page->uuid]),
            'project_name' => $this->page->title,
        ];
    }
}
