<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification inbox (BRAI-102): the bell and the /notifications page share one JSON feed.
 * Queries and actions live in NotificationInbox (shared with the App API, BRAI-393).
 */
class NotificationController extends Controller
{
    public const FILTERS = NotificationInbox::FILTERS;

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
        return response()->json(['count' => NotificationInbox::unread($request->user())]);
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
            'action' => ['required', Rule::in(NotificationInbox::ACTIONS)],
        ]);

        return $this->apply($request, $validated['ids'], $validated['action'], findOrFail: false);
    }

    /**
     * Mark every unread inbox notification as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        NotificationInbox::markAllRead($request->user());

        return response()->json(['success' => true, 'unread_count' => 0]);
    }

    /**
     * Profile → Notifications: per category, which channels deliver.
     * Body: {preferences: {"<category>": {"in_app": bool, "push": bool, "email": bool}}} (partial is fine).
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate(NotificationCatalog::preferenceRules());

        $user = $request->user();
        NotificationCatalog::updatePreferences($user, $validated['preferences']);

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
        $page = NotificationInbox::page($user, $filter, $archived, $cursor, self::PER_PAGE);

        return [
            'data' => collect($page->items())->map(fn (InboxNotification $n) => NotificationInbox::present($n))->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => NotificationInbox::unread($user),
        ];
    }

    /** @return array{0: string, 1: bool} */
    private function feedOptions(Request $request): array
    {
        $filter = $request->query('filter');

        return [in_array($filter, self::FILTERS, true) ? $filter : 'all', $request->boolean('archived')];
    }

    /** read / unread / archive / unarchive on the user's own rows; unknown ids 404 (single) or are skipped (bulk). */
    private function apply(Request $request, array $ids, string $action, bool $findOrFail = true): JsonResponse
    {
        $unread = NotificationInbox::apply($request->user(), $ids, $action, $findOrFail);

        return response()->json(['success' => true, 'unread_count' => $unread]);
    }
}
