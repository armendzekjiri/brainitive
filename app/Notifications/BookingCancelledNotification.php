<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Booking\BookingText;

/** A guest cancelled their booking (BRAI-158; category: bookings). The host's own cancels don't notify them. */
class BookingCancelledNotification extends CatalogNotification
{
    public function __construct(public readonly Booking $booking) {}

    public function catalogType(): string
    {
        return 'booking_cancelled';
    }

    protected function message(object $notifiable): array
    {
        $type = $this->booking->type;
        $page = $type->page;
        $zone = method_exists($notifiable, 'localTimezone') ? $notifiable->localTimezone() : $page->timezone;
        $reason = $this->booking->cancel_reason ? ' Reason: '.$this->booking->cancel_reason : '';

        return [
            'title' => self::plain($this->booking->guest_name)." cancelled “{$type->name}”",
            'body' => BookingText::when($this->booking, $zone).'.'.$reason,
            'url' => route('booking-pages.show', ['bookingPage' => $page->uuid, 'tab' => 'bookings']),
            'project_name' => $page->title,
        ];
    }
}
