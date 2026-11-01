<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The host's view of booking pages and bookings (BRAI-158), shared by the web (BookingPageController: the list
 * and the "Bookings" tab) and the App API (BRAI-419). Booking pages are personal: only their owner, while still
 * a member of the page's workspace (BookingPagePolicy), sees them and their bookings.
 */
final class BookingRows
{
    /** Eager loads of a booking row. */
    public const WITH = ['type:id,name,booking_page_id,questions', 'type.page:id,uuid,title', 'item:id,key,board_id', 'item.board:id,slug,project_id', 'item.board.project:id,slug,workspace_id', 'item.board.project.workspace:id,slug'];

    /**
     * The user's booking pages they may still open, by title.
     *
     * @return Collection<int, BookingPage>
     */
    public static function pages(User $user): Collection
    {
        return BookingPage::where('user_id', $user->id)
            ->whereHas('workspace')
            ->with('workspace:id,name,slug')
            ->withCount(['types'])
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->filter(fn (BookingPage $p) => Gate::forUser($user)->allows('view', $p))
            ->values();
    }

    /** Confirmed bookings from now on, per booking page id. @param list<int> $pageIds @return array<int, int> */
    public static function upcomingCounts(User $user, array $pageIds): array
    {
        return Booking::confirmed()->where('bookings.user_id', $user->id)->where('starts_at', '>=', now())
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->whereIn('booking_types.booking_page_id', $pageIds)
            ->groupBy('booking_types.booking_page_id')
            ->selectRaw('booking_types.booking_page_id as page_id, count(*) as aggregate')
            ->pluck('aggregate', 'page_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The bookings of these booking pages (the user's own, see pages()).
     *
     * @param  list<int>  $pageIds
     * @return Builder<Booking>
     */
    public static function query(array $pageIds): Builder
    {
        return Booking::query()->whereHas('type', fn ($q) => $q->whereIn('booking_page_id', $pageIds))->with(self::WITH);
    }

    /**
     * Bookings as the host sees them: guest, answers, status, call link, calendars that couldn't be checked, the card.
     *
     * @param  iterable<Booking>  $bookings
     * @return list<array{id: int, uuid: string, type: string|null, page: array{uuid: string, title: string}|null, starts_at: string, ends_at: string, guest_name: string, guest_email: string, guest_timezone: string|null, answers: list<array{0: string, 1: string}>, status: string, cancelled_by: string|null, cancel_reason: string|null, cancelled_at: string|null, conference_url: string|null, in_calendar: bool, unchecked: list<string>, card: array{id: int, key: string, url: string}|null}>
     */
    public static function rows(iterable $bookings): array
    {
        $bookings = collect($bookings);
        $unchecked = $bookings->pluck('unchecked_connection_ids')->flatten()->filter()->unique()->values();
        $connectionNames = $unchecked->isEmpty() ? collect() : CalendarConnection::whereIn('id', $unchecked)
            ->get()->mapWithKeys(fn ($c) => [$c->id => (string) ($c->external_calendar_name ?: $c->account_email)]);

        return $bookings->map(function (Booking $b) use ($connectionNames) {
            $board = $b->item?->board;
            $page = $b->type?->page;

            return [
                // the row id: a cursor paginator of rows reads (starts_at, id) from it
                'id' => $b->id,
                'uuid' => $b->uuid,
                'type' => $b->type?->name,
                'page' => $page ? ['uuid' => $page->uuid, 'title' => $page->title] : null,
                'starts_at' => $b->starts_at->toIso8601ZuluString(),
                'ends_at' => $b->ends_at->toIso8601ZuluString(),
                'guest_name' => $b->guest_name,
                'guest_email' => $b->guest_email,
                'guest_timezone' => $b->guest_timezone,
                'answers' => BookingText::answers($b),
                'status' => $b->status,
                'cancelled_by' => $b->cancelled_by,
                'cancel_reason' => $b->cancel_reason,
                'cancelled_at' => $b->cancelled_at?->toIso8601ZuluString(),
                'conference_url' => $b->conference_url,
                'in_calendar' => $b->external_event_id !== null,
                'unchecked' => array_values(array_filter(array_map(fn ($id) => $connectionNames[$id] ?? null, (array) $b->unchecked_connection_ids))),
                'card' => $b->item && $board?->project?->workspace ? [
                    'id' => $b->item->id,
                    'key' => $b->item->key,
                    'url' => route('boards.show', [$board->project->workspace->slug, $board->project->slug, $board->slug]).'#item-'.$b->item->id,
                ] : null,
            ];
        })->values()->all();
    }
}
