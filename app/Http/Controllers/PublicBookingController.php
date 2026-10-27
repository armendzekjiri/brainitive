<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\BookingType;
use App\Support\Booking\Availability;
use App\Support\Booking\BookingInput;
use App\Support\Booking\BookingService;
use App\Support\Booking\BookingText;
use App\Support\Booking\BookingWarnings;
use App\Support\Booking\BusyTimes;
use App\Support\Booking\SlotTaken;
use App\Support\Legal\PublicOperator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public side of booking pages (BRAI-158), no login: the page at /book/{slug}, its free slots,
 * booking, and the guest's manage link (cancel / reschedule). The slot list carries only free start
 * times — never anything about the host's events. Off / deleted pages and types are 404.
 */
class PublicBookingController extends Controller
{
    /** Days one slot request may cover (a month view plus its edges). */
    public const MAX_RANGE_DAYS = 42;

    /** Bookings per guest email per hour, on top of the per-IP route throttle. */
    public const PER_EMAIL_PER_HOUR = 5;

    public function show(Request $request, string $publicSlug, ?string $typeSlug = null): Response
    {
        $page = $this->page($publicSlug);
        $types = $page->types->where('enabled', true)->values();
        $selected = $typeSlug !== null ? $types->firstWhere('slug', $typeSlug) : ($types->count() === 1 ? $types->first() : null);
        abort_if($typeSlug !== null && ! $selected, 404);
        $embed = $request->boolean('embed');

        $response = Inertia::render('Booking/Public', [
            'page' => $this->pageProps($page, $types),
            'selected' => $selected?->slug,
            'operator' => PublicOperator::for($page->workspace, nameFallback: true),
            'embed' => $embed,
            'manage' => null,
        ])->toResponse($request);
        if ($embed) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors *');
        }

        return $response;
    }

    public function slots(Request $request, string $publicSlug, string $typeSlug): JsonResponse
    {
        $page = $this->page($publicSlug);
        $type = $this->type($page, $typeSlug);

        return response()->json($this->freeSlots($request, $page, $type));
    }

    public function store(Request $request, string $publicSlug, string $typeSlug): JsonResponse
    {
        $page = $this->page($publicSlug);
        $type = $this->type($page, $typeSlug);
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))],
            'answers' => ['nullable', 'array'],
            'consent' => ['nullable', 'boolean'],
            'website' => ['nullable'], // honeypot: people never see it, bots fill it in
        ]);
        if (filled($validated['website'] ?? null)) {
            return response()->json(['ok' => true]); // looks like success, nothing is booked
        }

        $answers = BookingInput::answers($type, $validated['answers'] ?? []);
        $consentText = $this->consentText($page);
        $consent = $page->settings['consent'] ?? 'off';
        if ($consent === 'required' && ! ($validated['consent'] ?? false)) {
            throw ValidationException::withMessages(['consent' => 'Please confirm to book.']);
        }

        $limiter = 'booking-email:'.sha1(mb_strtolower($validated['email']));
        if (RateLimiter::tooManyAttempts($limiter, self::PER_EMAIL_PER_HOUR)) {
            throw ValidationException::withMessages(['email' => 'Too many bookings with this email address. Please try again later.']);
        }
        RateLimiter::hit($limiter, 3600);

        try {
            [$booking, $token] = BookingService::book($type, CarbonImmutable::parse($validated['start'])->utc(), [
                'name' => trim($validated['name']),
                'email' => trim($validated['email']),
                'timezone' => $validated['timezone'] ?? null,
                'answers' => $answers,
                'consent_text' => $consent !== 'off' && ($validated['consent'] ?? false) ? $consentText : null,
            ]);
        } catch (SlotTaken $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'booking' => $this->bookingProps($booking), 'manage_url' => route('booking.manage', $token)]);
    }

    public function manage(Request $request, string $token): Response
    {
        $booking = $this->booking($token);
        $page = $booking->type->page;

        return Inertia::render('Booking/Public', [
            'page' => $this->pageProps($page, collect([$booking->type])),
            'selected' => $booking->type->slug,
            'operator' => PublicOperator::for($page->workspace, nameFallback: false),
            'embed' => false,
            'manage' => [
                'token' => $token,
                'booking' => $this->bookingProps($booking),
                'can_change' => $this->changeable($booking),
            ],
        ])->toResponse($request);
    }

    public function manageSlots(Request $request, string $token): JsonResponse
    {
        $booking = $this->booking($token);
        abort_unless($this->changeable($booking), 410);

        return response()->json($this->freeSlots($request, $booking->type->page, $booking->type));
    }

    public function manageCancel(Request $request, string $token): JsonResponse
    {
        $booking = $this->booking($token);
        abort_unless($this->changeable($booking), 410);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        BookingService::cancel($booking, 'guest', $validated['reason'] ?? null);

        return response()->json(['ok' => true, 'booking' => $this->bookingProps($booking->fresh(['type.page']))]);
    }

    public function manageReschedule(Request $request, string $token): JsonResponse
    {
        $booking = $this->booking($token);
        abort_unless($this->changeable($booking), 410);
        $validated = $request->validate(['start' => ['required', 'date']]);
        try {
            [$new, $newToken] = BookingService::reschedule($booking, CarbonImmutable::parse($validated['start'])->utc());
        } catch (SlotTaken $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'booking' => $this->bookingProps($new), 'manage_url' => route('booking.manage', $newToken)]);
    }

    /** @return array{slots: list<string>} */
    private function freeSlots(Request $request, BookingPage $page, BookingType $type): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))],
        ]);
        $zone = $validated['timezone'] ?? $page->timezone;
        $from = CarbonImmutable::parse($validated['from'], $zone)->startOfDay();
        $to = CarbonImmutable::parse($validated['to'], $zone)->addDay()->startOfDay();
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Ask for at most '.self::MAX_RANGE_DAYS.' days at once.']);
        }
        $now = CarbonImmutable::now();
        if ($to <= $now) {
            return ['slots' => []];
        }

        $busy = BusyTimes::for($page, $from->utc(), $to->utc());
        BookingWarnings::calendarsUnreadable($page, $busy->failed);
        $slots = Availability::slots($type->bookingRules(), $page->timezone, $from->utc(), $to->utc(), $busy->intervals,
            BookingService::bookedPerDay($type, $from->utc()->subDay(), $to->utc()->addDay()), $now);

        return ['slots' => array_map(fn (CarbonImmutable $s) => $s->toIso8601ZuluString(), $slots)];
    }

    private function page(string $publicSlug): BookingPage
    {
        $page = BookingPage::where('public_slug', $publicSlug)->where('enabled', true)
            ->whereHas('workspace')->whereHas('user')
            ->with(['types' => fn ($q) => $q->where('enabled', true), 'user', 'workspace'])
            ->first();
        abort_unless($page, 404);
        // a host who left the workspace doesn't take bookings in its name
        abort_unless($page->user->hasWorkspaceAccess($page->workspace), 404);

        return $page;
    }

    private function type(BookingPage $page, string $typeSlug): BookingType
    {
        $type = $page->types->firstWhere('slug', $typeSlug);
        abort_unless($type, 404);
        $type->setRelation('page', $page);

        return $type;
    }

    private function booking(string $token): Booking
    {
        $booking = Booking::findByManageToken($token);
        abort_unless($booking, 404);
        $booking->load('type.page.user', 'type.page.workspace');
        abort_unless($booking->type?->page, 404);

        return $booking;
    }

    /** Cancel / reschedule while the booking is confirmed and hasn't started. */
    private function changeable(Booking $booking): bool
    {
        return $booking->status === Booking::CONFIRMED && $booking->starts_at->isFuture();
    }

    private function consentText(BookingPage $page): ?string
    {
        if (($page->settings['consent'] ?? 'off') === 'off') {
            return null;
        }
        $organization = PublicOperator::for($page->workspace, nameFallback: true)['name'];
        $text = trim((string) ($page->settings['consent_text'] ?? ''));

        return str_replace('{organization}', $organization, $text !== '' ? $text
            : 'I agree that {organization} stores my name, email and answers to arrange and hold this meeting.');
    }

    /** @return array<string, mixed> */
    private function pageProps(BookingPage $page, $types): array
    {
        $host = $page->user;

        return [
            'slug' => $page->public_slug,
            'title' => $page->title,
            'intro' => $page->intro,
            'timezone' => $page->timezone,
            'host' => ['name' => $host->name, 'avatar' => $host->avatar_display ?? null],
            'consent' => $page->settings['consent'] ?? 'off',
            'consent_text' => $this->consentText($page),
            'types' => $types->map(fn (BookingType $t) => [
                'slug' => $t->slug,
                'name' => $t->name,
                'description' => $t->description,
                'duration_minutes' => $t->duration_minutes,
                'location_kind' => $t->location['kind'] ?? 'none',
                'location' => BookingText::location($t),
                'questions' => array_map(fn ($q) => array_intersect_key($q, array_flip(['id', 'label', 'type', 'required', 'options'])), (array) $t->questions),
                'horizon_days' => $t->bookingRules()->horizonDays,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> what the guest sees about their booking */
    private function bookingProps(Booking $booking): array
    {
        $booking->loadMissing('type.page');

        return [
            'type' => $booking->type->name,
            'starts_at' => $booking->starts_at->toIso8601ZuluString(),
            'ends_at' => $booking->ends_at->toIso8601ZuluString(),
            'guest_name' => $booking->guest_name,
            'guest_email' => $booking->guest_email,
            'status' => $booking->status,
            'location' => $booking->conference_url ? null : BookingText::location($booking->type),
            'conference_url' => $booking->conference_url,
        ];
    }
}
