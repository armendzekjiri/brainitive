<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingGuestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Booking pages (BRAI-158): a reminder email to the guest about a day before their booking. Only for
 * bookings made well ahead (someone who booked this morning doesn't need one), once each
 * (`reminded_at`). The manage link isn't repeated: only its hash is stored.
 */
class SendBookingReminders extends Command
{
    /** Remind this long before the start. */
    public const LEAD_HOURS = 24;

    /** …but only bookings made at least this long before their start. */
    public const MIN_BOOKED_AHEAD_HOURS = 36;

    protected $signature = 'app:send-booking-reminders';

    protected $description = 'Email booking guests a reminder about a day before their booking';

    public function handle(): int
    {
        $sent = 0;
        Booking::confirmed()
            ->whereNull('reminded_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(self::LEAD_HOURS))
            ->with('type.page.user', 'type.page.workspace')
            ->chunkById(100, function ($bookings) use (&$sent) {
                foreach ($bookings as $booking) {
                    // claim it first: a second run never sends twice
                    if (! Booking::whereKey($booking->id)->whereNull('reminded_at')->update(['reminded_at' => now()])) {
                        continue;
                    }
                    if ($booking->created_at->diffInHours($booking->starts_at) < self::MIN_BOOKED_AHEAD_HOURS || ! $booking->type?->page) {
                        continue;
                    }
                    Notification::route('mail', $booking->guest_email)->notify(new BookingGuestNotification($booking, 'reminder'));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} booking reminder(s).");

        return self::SUCCESS;
    }
}
