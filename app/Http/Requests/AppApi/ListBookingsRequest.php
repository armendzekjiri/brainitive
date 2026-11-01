<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Validation\Rule;

/** `GET /api/v1/bookings`: the host's bookings, by state and optionally of one booking page. */
class ListBookingsRequest extends ListRequest
{
    public const STATUSES = ['upcoming', 'past', 'cancelled'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // upcoming (confirmed, not over; soonest first, default) | past (confirmed, over; latest first) | cancelled (latest first)
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            // only the bookings of this booking page (uuid of GET /booking-pages)
            'page' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function status(): string
    {
        return (string) ($this->validated('status') ?? 'upcoming');
    }
}
