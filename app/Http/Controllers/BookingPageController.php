<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\BookingType;
use App\Models\CalendarConnection;
use App\Models\Workspace;
use App\Support\Booking\Availability;
use App\Support\Booking\BookingRules;
use App\Support\Booking\BookingService;
use App\Support\Booking\BookingTemplates;
use App\Support\Booking\BookingText;
use App\Support\Booking\BusyTimes;
use App\Support\Calendar\CalendarProviders;
use App\Support\Meetings\MeetingCalendars;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The host's side of booking pages (BRAI-158): the list, creating one (blank or from a template),
 * the page's settings + busy sources + "also create a card", a slot preview, and cancelling bookings.
 * Meeting types have their own controller (BookingTypeController).
 */
class BookingPageController extends Controller
{
    /** Addresses that would collide with /book/manage/… or read as something else. */
    public const RESERVED_SLUGS = ['manage', 'embed', 'admin', 'api', 'new'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pages = BookingPage::where('user_id', $user->id)
            ->whereHas('workspace')
            ->with('workspace:id,name,slug')
            ->withCount(['types'])
            ->orderBy('title')
            ->get()
            ->filter(fn (BookingPage $p) => Gate::allows('view', $p))
            ->map(fn (BookingPage $p) => [
                'uuid' => $p->uuid,
                'title' => $p->title,
                'enabled' => $p->enabled,
                'public_url' => route('booking.public', $p->public_slug),
                'workspace' => $p->workspace->name,
                'types_count' => $p->types_count,
                'upcoming_count' => Booking::confirmed()->where('user_id', $user->id)->where('starts_at', '>=', now())
                    ->whereHas('type', fn ($q) => $q->where('booking_page_id', $p->id))->count(),
            ])->values();

        return Inertia::render('Booking/Index', [
            'pages' => $pages,
            'templates' => BookingTemplates::catalog(),
            'workspaces' => $user->workspaces()->orderBy('name')->get(['workspaces.id', 'workspaces.name'])->map(fn ($w) => ['id' => $w->id, 'name' => $w->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'template' => ['required', Rule::in(array_keys(BookingTemplates::all()))],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        $workspace = Workspace::find($validated['workspace_id']);
        abort_unless($workspace && $user->hasWorkspaceAccess($workspace), 403);

        $template = BookingTemplates::all()[$validated['template']];
        $page = BookingPage::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'public_slug' => BookingPage::freeSlug($user->name.' '.($validated['template'] === 'blank' ? '' : $template['name'])),
            'title' => ($validated['title'] ?? null) ?: $template['title'],
            'intro' => $template['intro'] ?: null,
            'timezone' => $user->localTimezone(),
            'busy_sources' => ($template['busy_sources'] ?? []) + BookingPage::DEFAULT_BUSY_SOURCES,
            'settings' => ['consent' => 'off', 'consent_text' => null],
        ]);
        $target = self::defaultTarget($user->id);
        foreach ($template['types'] as $i => $type) {
            $page->types()->create($type + ['slug' => self::typeSlug($page, $type['name']), 'position' => $i, 'target_connection_id' => $target]);
        }

        return redirect()->route('booking-pages.show', $page->uuid)->with('success', 'Booking page created.');
    }

    public function show(Request $request, BookingPage $bookingPage): Response
    {
        $this->authorize('view', $bookingPage);
        $user = $request->user();
        $bookingPage->load('types', 'workspace:id,name');

        $connections = CalendarConnection::where('user_id', $user->id)->where('kind', 'calendar')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('sync_error'))
            ->orderBy('provider')->orderBy('external_calendar_name')->get();
        $meetingKinds = collect(MeetingCalendars::for($user))->keyBy('id');
        $sources = $bookingPage->busySources();

        return Inertia::render('Booking/Show', [
            'page' => [
                'uuid' => $bookingPage->uuid,
                'title' => $bookingPage->title,
                'intro' => $bookingPage->intro,
                'timezone' => $bookingPage->timezone,
                'enabled' => $bookingPage->enabled,
                'public_slug' => $bookingPage->public_slug,
                'public_url' => route('booking.public', $bookingPage->public_slug),
                'embed_url' => route('booking.public', ['publicSlug' => $bookingPage->public_slug, 'embed' => 1]),
                'workspace' => $bookingPage->workspace->name,
                'busy_sources' => $sources,
                'card_board_id' => $bookingPage->card_board_id,
                'card_column_id' => $bookingPage->card_column_id,
                'consent' => $bookingPage->settings['consent'] ?? 'off',
                'consent_text' => $bookingPage->settings['consent_text'] ?? null,
            ],
            'types' => $bookingPage->types->map(fn (BookingType $t) => self::typeRowFor($t))->values(),
            'connections' => $connections->map(fn (CalendarConnection $c) => [
                'id' => $c->id,
                'name' => (string) ($c->external_calendar_name ?: $c->account_email),
                'account' => $c->account_email,
                'provider' => CalendarProviders::has($c->provider) ? CalendarProviders::for($c)->label() : $c->provider,
                'writable' => $c->is_active && CalendarProviders::has($c->provider) && CalendarProviders::for($c)->isWritable($c),
                'conference_providers' => $meetingKinds[$c->id]['conference_providers'] ?? [],
                'sync_error' => $c->sync_error,
                'blocks' => $sources['connections'] === 'all' || in_array($c->id, $sources['connections'], true),
            ])->values(),
            'boards' => $this->cardBoards($request, $bookingPage),
            'bookings' => $this->bookings($bookingPage),
            'templates' => BookingTemplates::catalog(),
            'tab' => in_array($request->query('tab'), ['types', 'settings', 'bookings'], true) ? $request->query('tab') : 'types',
        ]);
    }

    public function update(Request $request, BookingPage $bookingPage): RedirectResponse
    {
        $this->authorize('update', $bookingPage);
        $user = $request->user();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))],
            'enabled' => ['required', 'boolean'],
            'public_slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED_SLUGS),
                Rule::unique('booking_pages', 'public_slug')->ignore($bookingPage->id)],
            'busy_sources' => ['required', 'array'],
            'busy_sources.connections' => ['present'],
            'busy_sources.issues' => ['required', 'boolean'],
            'busy_sources.tasks' => ['required', 'boolean'],
            'busy_sources.all_day_blocks' => ['required', 'boolean'],
            'card_board_id' => ['nullable', 'integer'],
            'card_column_id' => ['nullable', 'integer', 'required_with:card_board_id'],
            'consent' => ['required', Rule::in(['off', 'optional', 'required'])],
            'consent_text' => ['nullable', 'string', 'max:1000'],
        ], ['public_slug.regex' => 'Use lower-case letters, numbers and dashes.', 'public_slug.not_in' => 'This address is reserved.']);

        // only the host's own calendars, or "all"
        $connections = $validated['busy_sources']['connections'];
        if ($connections !== 'all') {
            $ids = array_map('intval', array_filter((array) $connections, 'is_numeric'));
            $connections = CalendarConnection::where('user_id', $user->id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        // "also create a card": a board of this workspace the host may add cards to, and its column
        $boardId = $validated['card_board_id'] ?? null;
        $columnId = null;
        if ($boardId) {
            $board = Board::with('project')->find($boardId);
            abort_unless($board && $board->project?->workspace_id === $bookingPage->workspace_id && Gate::allows('createItems', $board), 422, 'Pick a board you can add cards to.');
            $columnId = $board->columns()->whereKey($validated['card_column_id'])->value('id');
            abort_unless($columnId, 422, 'Pick a column of that board.');
        }

        $bookingPage->update([
            'title' => $validated['title'],
            'intro' => $validated['intro'] ?? null,
            'timezone' => $validated['timezone'],
            'enabled' => $validated['enabled'],
            'public_slug' => $validated['public_slug'],
            'busy_sources' => [
                'connections' => $connections,
                'issues' => $validated['busy_sources']['issues'],
                'tasks' => $validated['busy_sources']['tasks'],
                'all_day_blocks' => $validated['busy_sources']['all_day_blocks'],
            ],
            'card_board_id' => $boardId,
            'card_column_id' => $columnId,
            'settings' => ['consent' => $validated['consent'], 'consent_text' => $validated['consent_text'] ?? null],
        ]);

        return back()->with('success', 'Saved.');
    }

    public function destroy(BookingPage $bookingPage): RedirectResponse
    {
        $this->authorize('delete', $bookingPage);
        // upcoming bookings stay in the guests' calendars: cancel them properly first
        $upcoming = Booking::confirmed()->where('starts_at', '>', now())->whereHas('type', fn ($q) => $q->where('booking_page_id', $bookingPage->id))->get();
        foreach ($upcoming as $booking) {
            BookingService::cancel($booking, 'host', 'The booking page was removed.');
        }
        $bookingPage->delete();

        return redirect()->route('booking-pages.index')->with('success', 'Booking page deleted.');
    }

    /** The next free times of one type, as the host's preview while editing. */
    public function preview(Request $request, BookingPage $bookingPage, BookingType $bookingType): JsonResponse
    {
        $this->authorize('view', $bookingPage);
        abort_unless($bookingType->booking_page_id === $bookingPage->id, 404);
        $bookingType->setRelation('page', $bookingPage);

        $from = CarbonImmutable::now();
        $to = $from->addDays(min(14, $bookingType->bookingRules()->horizonDays + 1));
        $busy = BusyTimes::for($bookingPage, $from, $to);
        $slots = Availability::slots($bookingType->bookingRules(), $bookingPage->timezone, $from, $to, $busy->intervals,
            BookingService::bookedPerDay($bookingType, $from, $to), $from);

        return response()->json([
            'slots' => array_map(fn ($s) => $s->toIso8601ZuluString(), array_slice($slots, 0, 30)),
            'unchecked' => array_values(array_map(fn ($c) => (string) ($c->external_calendar_name ?: $c->account_email), $busy->failed)),
        ]);
    }

    public function cancelBooking(Request $request, Booking $booking): RedirectResponse
    {
        $booking->load('type.page');
        $this->authorize('update', $booking->type->page);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        BookingService::cancel($booking, 'host', $validated['reason'] ?? null);

        return back()->with('success', 'Booking cancelled. '.$booking->guest_name.' was told by email.');
    }

    /** @return array<string, mixed> */
    public static function typeRowFor(BookingType $t): array
    {
        return [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->name,
            'description' => $t->description,
            'duration_minutes' => $t->duration_minutes,
            'location' => $t->location ?? ['kind' => 'none', 'value' => null],
            'availability' => $t->availability ?? ['weekly' => [], 'overrides' => []],
            'rules' => array_merge(BookingRules::DEFAULTS, (array) $t->rules),
            'target_connection_id' => $t->target_connection_id,
            'questions' => $t->questions ?? [],
            'enabled' => $t->enabled,
            'position' => $t->position,
        ];
    }

    public static function typeSlug(BookingPage $page, string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'meeting', 56, '');
        $slug = $base;
        for ($n = 2; $page->types()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** New types write to the host's first writable calendar (they can change it). */
    public static function defaultTarget(int $userId): ?int
    {
        foreach (CalendarConnection::where('user_id', $userId)->where('kind', 'calendar')->where('is_active', true)->orderBy('id')->get() as $c) {
            if (CalendarProviders::has($c->provider) && CalendarProviders::for($c)->isWritable($c)) {
                return $c->id;
            }
        }

        return null;
    }

    /** @return list<array{id: int, name: string, project: string, columns: list<array{id: int, name: string}>}> */
    private function cardBoards(Request $request, BookingPage $page): array
    {
        return Board::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $page->workspace_id))
            ->with(['project:id,name,workspace_id', 'project.workspace', 'columns' => fn ($q) => $q->orderBy('position')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Board $b) => $request->user()->can('createItems', $b))
            ->map(fn (Board $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'project' => $b->project?->name,
                'columns' => $b->columns->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function bookings(BookingPage $page): array
    {
        $bookings = Booking::whereHas('type', fn ($q) => $q->where('booking_page_id', $page->id))
            ->with('type:id,name,booking_page_id', 'item:id,key,board_id', 'item.board:id,slug,project_id', 'item.board.project:id,slug,workspace_id', 'item.board.project.workspace:id,slug')
            ->where('starts_at', '>=', now()->subDays(60))
            ->orderBy('starts_at')
            ->limit(300)
            ->get();
        $connectionNames = CalendarConnection::whereIn('id', $bookings->pluck('unchecked_connection_ids')->flatten()->filter()->unique())
            ->get()->mapWithKeys(fn ($c) => [$c->id => (string) ($c->external_calendar_name ?: $c->account_email)]);

        return $bookings->map(function (Booking $b) use ($connectionNames) {
            $board = $b->item?->board;

            return [
                'uuid' => $b->uuid,
                'type' => $b->type?->name,
                'starts_at' => $b->starts_at->toIso8601ZuluString(),
                'ends_at' => $b->ends_at->toIso8601ZuluString(),
                'guest_name' => $b->guest_name,
                'guest_email' => $b->guest_email,
                'guest_timezone' => $b->guest_timezone,
                'answers' => BookingText::answers($b),
                'status' => $b->status,
                'cancelled_by' => $b->cancelled_by,
                'cancel_reason' => $b->cancel_reason,
                'conference_url' => $b->conference_url,
                'in_calendar' => $b->external_event_id !== null,
                'unchecked' => array_values(array_filter(array_map(fn ($id) => $connectionNames[$id] ?? null, (array) $b->unchecked_connection_ids))),
                'card' => $b->item && $board?->project?->workspace ? [
                    'key' => $b->item->key,
                    'url' => route('boards.show', [$board->project->workspace->slug, $board->project->slug, $board->slug]).'#item-'.$b->item->id,
                ] : null,
            ];
        })->all();
    }
}
