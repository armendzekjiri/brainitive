<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\CalendarConnection;
use App\Support\Booking\BookingText;

/**
 * Someone booked a time on your booking page, or moved their booking (BRAI-158; category: bookings).
 * Warns when a busy calendar couldn't be checked, so the host looks for an overlap.
 */
class BookingCreatedNotification extends CatalogNotification
{
    public function __construct(public readonly Booking $booking, public readonly ?Booking $replaces = null) {}

    public function catalogType(): string
    {
        return 'booking_created';
    }

    protected function message(object $notifiable): array
    {
        $type = $this->booking->type;
        $page = $type->page;
        $zone = method_exists($notifiable, 'localTimezone') ? $notifiable->localTimezone() : $page->timezone;
        $when = BookingText::when($this->booking, $zone);
        $guest = self::plain($this->booking->guest_name);

        $body = $this->replaces ? "Moved from {$this->replaces->starts_at->setTimezone($zone)->format('D j M, H:i')} to {$when}." : $when.'.';
        if ($this->booking->unchecked_connection_ids) {
            $names = CalendarConnection::whereIn('id', $this->booking->unchecked_connection_ids)->pluck('external_calendar_name')->filter()->join(', ');
            $body .= ' ⚠ '.($names ?: 'A calendar').' couldn’t be checked: look for an overlap.';
        }

        return [
            'title' => $this->replaces ? "{$guest} rescheduled “{$type->name}”" : "{$guest} booked “{$type->name}”",
            'body' => $body,
            'url' => route('booking-pages.show', ['bookingPage' => $page->uuid, 'tab' => 'bookings']),
            'project_name' => $page->title,
        ];
    }
}
