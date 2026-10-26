<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use App\Support\Live\Live;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification inbox (BRAI-102): the bell and the /notifications page share one JSON feed.
 *
 * Rows are the user's `notifications`; `archived_at` hides a row from the inbox (archiving also
 * marks it read, so "unread" never counts archived rows). Each row carries its catalog `category`
 * (null for a type the catalog doesn't know) so the UI can pick an icon without knowing the type.
 */
class NotificationController extends Controller
{
    public const FILTERS = ['all', 'unread', 'mentions', 'assigned'];

    /** Tab => catalog category. */
    private const FILTER_CATEGORIES = ['mentions' => 'mentions', 'assigned' => 'assignments'];

    private const PER_PAGE = 20;

    /**
     * The inbox page. Opening it does not mark anything read.
     */
    public function page(Request $request): Response
    {
        [$filter, $archived] = $this->feedOptions($request);

        return Inertia::render('Notifications/Index', [
            'feed' => $this->feed($request->user(), $filter, $archived, null),
            'filter' => $filter,
            'archived' => $archived,
        ]);
    }

    /**
     * One page of the feed: ?filter=all|unread|mentions|assigned&archived=0|1&cursor=…
     */
    public function index(Request $request): JsonResponse
    {
        [$filter, $archived] = $this->feedOptions($request);
        $cursor = $request->query('cursor');

        return response()->json($this->feed($request->user(), $filter, $archived, is_string($cursor) ? $cursor : null));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->unread($request->user())]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        return $this->apply($request, [$id], 'read');
    }

    public function markUnread(Request $request, string $id): JsonResponse
    {
        return $this->apply($request, [$id], 'unread');
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->apply($request, [$id], 'archive');
    }

    public function unarchive(Request $request, string $id): JsonResponse
    {
        return $this->apply($request, [$id], 'unarchive');
    }

    /**
     * Bulk actions from the inbox page's multi-select.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['read', 'unread', 'archive', 'unarchive'])],
        ]);

        return $this->apply($request, $validated['ids'], $validated['action'], findOrFail: false);
    }

    /**
     * Mark every unread inbox notification as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->own($request->user())->whereNull('archived_at')->whereNull('read_at')->update(['read_at' => now()]);
        self::announce($request->user(), 0);

        return response()->json(['success' => true, 'unread_count' => 0]);
    }

    /**
     * Profile → Notifications: per category, which channels deliver.
     * Body: {preferences: {"<category>": {"in_app": bool, "push": bool, "email": bool}}} (partial is fine).
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array:'.implode(',', array_keys(NotificationCatalog::CATEGORIES))],
            'preferences.*' => ['required', 'array:'.implode(',', NotificationCatalog::CHANNELS)],
            'preferences.*.*' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        foreach ($validated['preferences'] as $category => $channels) {
            $stored[$category] = array_replace(NotificationCatalog::preferences($user, $category), array_map('boolval', $channels));
        }
        $user->update(['notification_preferences' => $stored]);

        return $request->header('X-Inertia') || ! $request->expectsJson()
            ? back()
            : response()->json(['categories' => NotificationCatalog::forUser($user)]);
    }

    /**
     * The feed shape shared by the page props and the JSON endpoint.
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, unread_count: int}
     */
    private function feed(User $user, string $filter, bool $archived, ?string $cursor): array
    {
        $query = $this->own($user)
            ->when($archived, fn (Builder $q) => $q->whereNotNull('archived_at'), fn (Builder $q) => $q->whereNull('archived_at'))
            ->when($filter === 'unread', fn (Builder $q) => $q->whereNull('read_at'));

        if (isset(self::FILTER_CATEGORIES[$filter])) {
            $category = self::FILTER_CATEGORIES[$filter];
            $types = array_keys(array_filter(NotificationCatalog::TYPES, fn (string $c) => $c === $category));
            if ($filter === 'assigned') {
                $types[] = 'ticket_assigned'; // a helpdesk notification (email on by default), but still an assignment
            }
            self::whereDataType($query, $types);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $cursor);

        return [
            'data' => collect($page->items())->map(fn (InboxNotification $n) => self::present($n))->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $this->unread($user),
        ];
    }

    /** One row for the UI. `category` comes from the catalog and is null for unknown types (never throws). */
    private static function present(InboxNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = is_string($data['type'] ?? null) ? $data['type'] : null;

        return [
            'id' => $notification->id,
            'type' => $type,
            'category' => $type !== null ? (NotificationCatalog::TYPES[$type] ?? null) : null,
            'data' => $data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'archived_at' => $notification->archived_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /** @return array{0: string, 1: bool} */
    private function feedOptions(Request $request): array
    {
        $filter = $request->query('filter');

        return [in_array($filter, self::FILTERS, true) ? $filter : 'all', $request->boolean('archived')];
    }

    private function own(User $user): Builder
    {
        return InboxNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }

    private function unread(User $user): int
    {
        return $this->own($user)->whereNull('archived_at')->whereNull('read_at')->count();
    }

    /** read / unread / archive / unarchive on the user's own rows; unknown ids 404 (single) or are skipped (bulk). */
    private function apply(Request $request, array $ids, string $action, bool $findOrFail = true): JsonResponse
    {
        $user = $request->user();
        $rows = fn () => $this->own($user)->whereKey($ids);
        if ($findOrFail && ! $rows()->exists()) {
            abort(404);
        }

        $now = now();
        match ($action) {
            'read' => $rows()->whereNull('read_at')->update(['read_at' => $now]),
            // back to the inbox: an archived row is always read, so "unread" also un-archives
            'unread' => $rows()->update(['read_at' => null, 'archived_at' => null]),
            'archive' => [$rows()->whereNull('read_at')->update(['read_at' => $now]), $rows()->update(['archived_at' => $now])],
            'unarchive' => $rows()->update(['archived_at' => null]),
        };

        $unread = $this->unread($user);
        self::announce($user, $unread);

        return response()->json(['success' => true, 'unread_count' => $unread]);
    }

    /** The user's other tabs (bell badge, tab title) follow a read / archive at once (BRAI-151). */
    private static function announce(User $user, int $unread): void
    {
        Live::publish("user.{$user->id}", 'notifications.changed', ['unread_count' => $unread]);
    }

    /** Filter on the `type` key inside the JSON `data` text column (cross-DB). */
    private static function whereDataType(Builder $query, array $types): void
    {
        if ($types === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $expression = match (DB::connection()->getDriverName()) {
            'pgsql' => "(data::jsonb ->> 'type')",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(data, '$.type'))",
            default => "json_extract(data, '$.type')",
        };

        $query->whereIn(DB::raw($expression), $types);
    }
}
