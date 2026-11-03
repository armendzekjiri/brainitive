<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\CancelBookingRequest;
use App\Http\Requests\AppApi\ListBookingsRequest;
use App\Http\Resources\AppApi\BookingCollection;
use App\Http\Resources\AppApi\BookingPageResource;
use App\Http\Resources\AppApi\BookingResource;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\User;
use App\Support\Booking\BookingRows;
use App\Support\Booking\BookingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — the host's side of booking pages (BRAI-419): the user's booking pages (read) and the bookings
 * guests made on them, with the host's cancel (BookingService::cancel, as the web's "Bookings" tab). Booking
 * pages are personal (BookingPagePolicy): another user's page or booking is a 404. The public booking page,
 * the guest's manage link (cancel / reschedule by the guest) and the page builder are not in the App API.
 */
#[Group('Bookings', weight: 280)]
class BookingController extends Controller
{
    /** The user's booking pages by title, with their meeting types' share links and upcoming counts. Not paginated. */
    public function pages(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $pages = BookingRows::pages($user)->load(['types' => fn ($q) => $q->orderBy('position')->orderBy('id')]);
        $upcoming = BookingRows::upcomingCounts($user, $pages->pluck('id')->all());
        $pages->each(fn (BookingPage $p) => $p->setAttribute('upcoming_count', $upcoming[$p->id] ?? 0));

        return BookingPageResource::collection($pages);
    }

    /**
     * The bookings of the user's booking pages.
     *
     * `upcoming` (default): confirmed and not over, soonest first; `past`: confirmed and over, latest first;
     * `cancelled`: latest first. Cursor-paginated, `updated_since`.
     *
     * @throws NotFoundHttpException `page` isn't one of the user's booking pages (404)
     */
    public function index(ListBookingsRequest $request): BookingCollection
    {
        $user = $request->user();
        $pageIds = $this->pageIds($user, $request->validated('page'));
        $status = $request->status();
        $since = $request->updatedSince();
        $now = now();

        $query = BookingRows::query($pageIds)
            ->when($status === 'cancelled', fn ($q) => $q->where('status', Booking::CANCELLED), fn ($q) => $q->confirmed())
            ->when($status === 'upcoming', fn ($q) => $q->where('ends_at', '>=', $now))
            ->when($status === 'past', fn ($q) => $q->where('ends_at', '<', $now))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));
        $direction = $status === 'upcoming' ? 'asc' : 'desc';
        $page = $query->orderBy('starts_at', $direction)->orderBy('id', $direction)
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        // rows keep `id` and `starts_at` (UTC), so next_cursor reads the same keys from them
        $page->setCollection(collect(BookingRows::rows($page->getCollection())));

        return new BookingCollection($page);
    }

    /**
     * One booking of the user's booking pages.
     *
     * @throws NotFoundHttpException not a booking of the user's pages (404)
     */
    public function show(Request $request, string $booking): BookingResource
    {
        return new BookingResource(BookingRows::rows([$this->booking($request->user(), $booking)])[0]);
    }

    /**
     * Cancel a booking as its host.
     *
     * The calendar event is removed, the card (if any) gets a comment and the guest an email with the reason.
     * Cancelling a booking that is already cancelled changes nothing (200 with it). Rescheduling is the
     * guest's (their manage link); the host cancels and the guest books again.
     *
     * @throws NotFoundHttpException not a booking of the user's pages (404)
     */
    public function cancel(CancelBookingRequest $request, string $booking): BookingResource
    {
        $row = $this->booking($request->user(), $booking);
        BookingService::cancel($row, 'host', $request->validated('reason'));

        return new BookingResource(BookingRows::rows([$row->fresh(BookingRows::WITH)])[0]);
    }

    /** @return list<int> the user's booking pages (one when $uuid is given, 404 when it isn't theirs) */
    private function pageIds(User $user, mixed $uuid): array
    {
        $ids = BookingRows::pages($user)
            ->when(is_string($uuid), fn ($pages) => $pages->where('uuid', $uuid))
            ->pluck('id')->all();
        abort_if(is_string($uuid) && $ids === [], 404);

        return $ids;
    }

    private function booking(User $user, string $uuid): Booking
    {
        abort_unless(Str::isUuid($uuid), 404);
        $booking = BookingRows::query($this->pageIds($user, null))->where('uuid', $uuid)->first();
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }
}
