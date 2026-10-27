<?php

namespace App\Support\Booking;

use RuntimeException;

/** The chosen time isn't free (any more): someone booked it, a calendar now blocks it, or it's out of range. */
final class SlotTaken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This time was just taken. Please pick another one.');
    }
}
