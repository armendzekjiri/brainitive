<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking as its host sees it on the web's "Bookings" tab (BookingRows::rows): the guest's name, address,
 * time zone and answers are the host's to see (they run the booking page); nobody else gets bookings.
 *
 * @property-read array{uuid: string, type: string|null, page: array{uuid: string, title: string}|null, starts_at: string, ends_at: string, guest_name: string, guest_email: string, guest_timezone: string|null, answers: list<array{0: string, 1: string}>, status: string, cancelled_by: string|null, cancel_reason: string|null, cancelled_at: string|null, conference_url: string|null, in_calendar: bool, unchecked: list<string>, card: array{id: int, key: string, url: string}|null} $resource
 */
class BookingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            'uuid' => (string) $b['uuid'],
            // the meeting type's name
            'type' => $b['type'],
            // the booking page
            'page' => $b['page'] === null ? null : ['uuid' => (string) $b['page']['uuid'], 'title' => (string) $b['page']['title']],
            'starts_at' => (string) $b['starts_at'],
            'ends_at' => (string) $b['ends_at'],
            'guest_name' => (string) $b['guest_name'],
            'guest_email' => (string) $b['guest_email'],
            'guest_timezone' => $b['guest_timezone'],
            // the booking questions' answers: [{question, answer}]
            'answers' => array_map(fn (array $a) => ['question' => (string) $a[0], 'answer' => (string) $a[1]], $b['answers']),
            // confirmed | cancelled
            'status' => (string) $b['status'],
            // host | guest
            'cancelled_by' => $b['cancelled_by'],
            'cancel_reason' => $b['cancel_reason'],
            'cancelled_at' => $b['cancelled_at'],
            // the video call link of the booking, when its type has one
            'conference_url' => $b['conference_url'],
            // an event was created in the host's calendar
            'in_calendar' => (bool) $b['in_calendar'],
            // calendars that couldn't be checked for conflicts when it was booked: look for an overlap
            'unchecked_calendars' => array_values(array_map('strval', $b['unchecked'])),
            // the issue the booking created ("also create a card"), {id, key, url}
            'card' => $b['card'] === null ? null : ['id' => (int) $b['card']['id'], 'key' => (string) $b['card']['key'], 'url' => (string) $b['card']['url']],
        ];
    }
}
