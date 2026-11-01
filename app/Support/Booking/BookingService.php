<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\BookingType;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingCreatedNotification;
use App\Notifications\BookingGuestNotification;
use App\Support\Boards\ItemComments;
use App\Support\Calendar\CalendarProviders;
use App\Support\Meetings\MeetingCalendars;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Booking, cancelling and rescheduling (BRAI-158) — the one place every path goes through.
 *
 * Double booking is stopped three times: the slot is re-checked against a FRESH busy read (calendars
 * outside the lock), then under a row lock on the host the confirmed bookings + daily max are checked
 * again, and finally the partial unique index (host, start) refuses a second confirmed row. The
 * calendar event, card and messages come after the commit: a failing calendar never loses a booking
 * (the guest still gets the .ics, the host is told it isn't in their calendar).
 */
final class BookingService
{
    /**
     * @param  array{name: string, email: string, timezone?: string|null, answers?: array<string, mixed>, consent_text?: string|null}  $guest
     * @return array{0: Booking, 1: string} the booking and the guest's plain manage token
     *
     * @throws SlotTaken
     */
    public static function book(BookingType $type, CarbonImmutable $start, array $guest, ?CarbonImmutable $now = null, ?Booking $replaces = null): array
    {
        $type->loadMissing('page.user');
        $page = $type->page;
        $rules = $type->bookingRules();
        $start = $start->utc();
        $end = $start->addMinutes($rules->durationMinutes);

        // 1. the slot is still free, with fresh calendar reads (outside the lock: providers are slow)
        $busy = BusyTimes::for($page, $start->subDay(), $end->addDay(), fresh: true);
        $slots = Availability::slots($rules, $page->timezone, $start, $start->addMinute(), $busy->intervals,
            self::bookedPerDay($type, $start->subDay(), $end->addDay()), $now ?? CarbonImmutable::now());
        if (! in_array($start->getTimestamp(), array_map(fn ($s) => $s->getTimestamp(), $slots), true)) {
            throw new SlotTaken;
        }
        BookingWarnings::calendarsUnreadable($page, $busy->failed);

        [$token, $hash] = Booking::newManageToken();
        try {
            $booking = DB::transaction(function () use ($type, $page, $rules, $start, $end, $guest, $hash, $busy) {
                // 2. one booking at a time per host: re-check what the lock now protects
                User::whereKey($page->user_id)->lockForUpdate()->first();
                $clash = Booking::confirmed()->where('user_id', $page->user_id)
                    ->where('starts_at', '<', $end->addMinutes($rules->bufferAfter))
                    ->where('ends_at', '>', $start->subMinutes($rules->bufferBefore))
                    ->exists();
                $date = $start->setTimezone($page->timezone)->toDateString();
                $full = $rules->dailyMax !== null && (self::bookedPerDay($type, $start->subDay(), $end->addDay())[$date] ?? 0) >= $rules->dailyMax;
                if ($clash || $full) {
                    throw new SlotTaken;
                }

                return Booking::create([
                    'booking_type_id' => $type->id,
                    'user_id' => $page->user_id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'guest_name' => $guest['name'],
                    'guest_email' => mb_strtolower($guest['email']),
                    'guest_timezone' => $guest['timezone'] ?? null,
                    'answers' => $guest['answers'] ?? [],
                    'consent_text' => $guest['consent_text'] ?? null,
                    'consent_at' => isset($guest['consent_text']) ? now() : null,
                    'status' => Booking::CONFIRMED,
                    'manage_token_hash' => $hash,
                    'unchecked_connection_ids' => array_keys($busy->failed) ?: null,
                ]);
            });
        } catch (QueryException $e) {
            // 3. the unique index caught a booking that slipped in between
            if (Booking::confirmed()->where('user_id', $page->user_id)->where('starts_at', $start)->exists()) {
                throw new SlotTaken;
            }
            throw $e;
        }

        self::writeCalendarEvent($booking);
        self::createCard($booking);

        $booking->load('type.page.user', 'type.page.workspace');
        $page->user->notify(new BookingCreatedNotification($booking, $replaces));
        Notification::route('mail', $booking->guest_email)->notify(new BookingGuestNotification($booking, $replaces ? 'rescheduled' : 'confirmed', $token));

        return [$booking, $token];
    }

    /** Cancel by the host or the guest: calendar event removed, card commented, the other side told. */
    public static function cancel(Booking $booking, string $by, ?string $reason = null): void
    {
        if ($booking->status !== Booking::CONFIRMED) {
            return;
        }
        $booking->update([
            'status' => Booking::CANCELLED,
            'cancelled_by' => $by,
            'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
            'cancelled_at' => now(),
        ]);
        self::cleanUp($booking, $by, $reason);

        if ($by === 'guest') {
            $booking->type->page->user->notify(new BookingCancelledNotification($booking));
        }
        Notification::route('mail', $booking->guest_email)->notify(new BookingGuestNotification($booking, 'cancelled'));
    }

    /** A cancelled booking leaves no trace in the calendar; its card says what happened. */
    private static function cleanUp(Booking $booking, string $by, ?string $reason): void
    {
        $booking->load('type.page.user', 'type.page.workspace', 'item');

        if ($booking->calendar_connection_id && $booking->external_event_id) {
            try {
                $connection = CalendarConnection::find($booking->calendar_connection_id);
                if ($connection) {
                    CalendarProviders::for($connection)->deleteEvent($connection, $booking->external_event_id);
                }
            } catch (Throwable $e) {
                Log::warning('Booking: calendar event not deleted', ['booking_id' => $booking->id, 'error' => get_class($e)]);
            }
        }

        if ($booking->item) {
            $host = $booking->type->page->user;
            ItemComments::add($booking->item, $host, '<p>'.e(($by === 'guest' ? $booking->guest_name : $host->name).' cancelled this booking.'
                .($reason ? ' Reason: '.$reason : '')).'</p>');
        }
    }

    /**
     * The guest moves their booking: a new booking at the new time (same answers), the old one
     * cancelled. One message each side ("rescheduled"), not "cancelled" + "new".
     *
     * @return array{0: Booking, 1: string}
     *
     * @throws SlotTaken
     */
    public static function reschedule(Booking $old, CarbonImmutable $start, ?CarbonImmutable $now = null): array
    {
        $old->load('type.page');
        // the old time no longer counts against the new one (it may even be the same slot)
        $old->update(['status' => Booking::CANCELLED, 'cancelled_by' => 'guest', 'cancel_reason' => 'Rescheduled', 'cancelled_at' => now()]);
        try {
            [$new, $token] = self::book($old->type, $start, [
                'name' => $old->guest_name,
                'email' => $old->guest_email,
                'timezone' => $old->guest_timezone,
                'answers' => $old->answers ?? [],
                'consent_text' => $old->consent_text,
            ], $now, $old);
        } catch (Throwable $e) {
            $old->update(['status' => Booking::CONFIRMED, 'cancelled_by' => null, 'cancel_reason' => null, 'cancelled_at' => null]);
            throw $e;
        }
        $reason = 'Rescheduled to '.$new->starts_at->setTimezone($old->type->page->timezone)->format('D j M, H:i');
        $old->update(['cancel_reason' => $reason]);
        self::cleanUp($old, 'guest', $reason);

        return [$new, $token];
    }

    /** @return array<string, int> local date (page zone) → confirmed bookings of this type */
    public static function bookedPerDay(BookingType $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $zone = $type->page->timezone;
        $counts = [];
        foreach ($type->bookings()->confirmed()->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->pluck('starts_at') as $startsAt) {
            $date = CarbonImmutable::parse($startsAt)->setTimezone($zone)->toDateString();
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        return $counts;
    }

    /** The event in the host's calendar, with the guest invited (+ a call when the type has one). */
    private static function writeCalendarEvent(Booking $booking): void
    {
        $type = $booking->type()->with('page.user', 'targetConnection')->first();
        $connection = $type->targetConnection;
        if (! $connection || $connection->user_id !== $type->page->user_id || ! $connection->is_active || $connection->kind !== 'calendar') {
            return;
        }

        try {
            $provider = CalendarProviders::for($connection);
            if (! $provider->isWritable($connection)) {
                return;
            }
            $kind = $type->location['kind'] ?? 'none';
            $conference = in_array($kind, ['google_meet', 'teams'], true) && in_array($kind, MeetingCalendars::conferenceProviders($connection), true) ? $kind : null;
            $zone = $type->page->timezone;
            $start = $booking->starts_at->setTimezone($zone);
            $end = $booking->ends_at->setTimezone($zone);

            $event = $provider->createEvent($connection, [
                'title' => $type->name.': '.$booking->guest_name,
                'description' => BookingText::eventDescription($booking),
                'location' => BookingText::location($type),
                'start_date' => $start->toDateString(),
                'start_time' => $start->format('H:i'),
                'end_date' => $end->toDateString(),
                'end_time' => $end->format('H:i'),
                'timezone' => $zone,
            ] + array_filter([
                'conference' => $conference,
                'attendees' => [['email' => $booking->guest_email, 'name' => $booking->guest_name]],
            ]));

            $booking->update([
                'calendar_connection_id' => $connection->id,
                'external_event_id' => $event['id'] ?? null,
                'conference_url' => $event['conference_url'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Booking: calendar event not created', ['booking_id' => $booking->id, 'connection_id' => $connection->id, 'error' => get_class($e)]);
        }
    }

    /** "Also create a card": on the page's board + column, only while the host may still add cards there. */
    private static function createCard(Booking $booking): void
    {
        $page = $booking->type->page()->with('user', 'cardBoard.project', 'cardColumn')->first();
        $board = $page->cardBoard;
        $column = $page->cardColumn;
        if (! $board || ! $column || $column->board_id !== $board->id || ! Gate::forUser($page->user)->allows('createItems', $board)) {
            return;
        }

        // cards keep wall-clock times in their owner's zone
        $start = $booking->starts_at->setTimezone($page->user->localTimezone());
        $item = $board->items()->create([
            'title' => $booking->type->name.': '.$booking->guest_name,
            'description' => BookingText::cardHtml($booking),
            'project_id' => $board->project_id,
            'column_id' => $column->id,
            'item_type_id' => ItemType::defaultIdFor($board->project),
            'priority_id' => ItemPriority::where('is_default', true)->first()?->id,
            'key' => $board->project->getNextItemKey(),
            'assigned_to' => $page->user_id,
            'due_date' => $start->toDateString(),
            'due_time' => $start->format('H:i'),
            'created_by' => $page->user_id,
            'reporter_id' => $page->user_id,
            'position' => (Item::where('column_id', $column->id)->max('position') ?? 0) + 1,
        ]);
        $booking->update(['item_id' => $item->id]);
    }
}
