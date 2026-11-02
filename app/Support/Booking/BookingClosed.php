<?php

namespace App\Support\Booking;

use RuntimeException;

/**
 * The booking page no longer takes bookings (Codex web review U): the page or the type was switched off, the host
 * left the workspace or the workspace was deleted. Existing bookings can still be cancelled.
 */
final class BookingClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This booking page no longer takes bookings.');
    }
}
