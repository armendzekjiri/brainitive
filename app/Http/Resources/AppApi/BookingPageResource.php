<?php

namespace App\Http\Resources\AppApi;

use App\Models\BookingPage;
use App\Models\BookingType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the user's booking pages, read-only (the web's booking pages list + its meeting types' share links).
 * Building pages and types (availability, rules, questions, calendars): web only (`url`).
 *
 * @mixin BookingPage
 */
class BookingPageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->uuid,
            'title' => (string) $this->title,
            // false: the public page says it isn't taking bookings
            'enabled' => (bool) $this->enabled,
            // the public page to share
            'public_url' => route('booking.public', $this->public_slug),
            'timezone' => (string) $this->timezone,
            'workspace' => ['slug' => (string) $this->workspace->slug, 'name' => (string) $this->workspace->name],
            'types_count' => (int) $this->types_count,
            // confirmed bookings from now on
            'upcoming_count' => (int) ($this->upcoming_count ?? 0),
            // the meeting types in the page's order, with their share link
            'types' => $this->types->map(fn (BookingType $t) => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'duration_minutes' => (int) $t->duration_minutes,
                'enabled' => (bool) $t->enabled,
                'public_url' => route('booking.public.type', [$this->public_slug, $t->slug]),
            ])->values()->all(),
            // the page's settings on the web
            'url' => route('booking-pages.show', $this->uuid),
        ];
    }
}
