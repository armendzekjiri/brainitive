<?php

namespace App\Support\Booking;

use App\Models\BookingPage;
use App\Models\CalendarConnection;
use App\Notifications\BookingCalendarUnreadableNotification;
use Illuminate\Support\Facades\Cache;

/**
 * A busy calendar couldn't be read while a booking page was used (BRAI-158): the page goes on without
 * it (bookings may overlap its events), so the host hears about it — at most once per calendar and
 * page per day, however many visitors look at slots.
 */
final class BookingWarnings
{
    /** @param  array<int, CalendarConnection>  $failed */
    public static function calendarsUnreadable(BookingPage $page, array $failed): void
    {
        foreach ($failed as $connection) {
            $key = 'booking-unreadable:'.$page->id.':'.$connection->id.':'.now()->toDateString();
            if (Cache::add($key, true, now()->endOfDay())) {
                $page->loadMissing('user');
                $page->user->notify(new BookingCalendarUnreadableNotification($page, $connection));
            }
        }
    }
}
