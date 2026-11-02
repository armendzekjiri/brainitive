<?php

namespace App\Support\Notifications;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use App\Support\Live\Live;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The notification inbox (BRAI-102): the bell, the /notifications page and the App API
 * (`/api/v1/notifications`, BRAI-393) share these queries and actions.
 *
 * Rows are the user's `notifications`; `archived_at` hides a row from the inbox (archiving also
 * marks it read, so "unread" never counts archived rows). Each row carries its catalog `category`
 * (null for a type the catalog doesn't know) so a client can pick an icon without knowing the type.
 */
final class NotificationInbox
{
    public const FILTERS = ['all', 'unread', 'mentions', 'assigned'];

    public const ACTIONS = ['read', 'unread', 'archive', 'unarchive'];

    /** Tab => catalog category. */
    private const FILTER_CATEGORIES = ['mentions' => 'mentions', 'assigned' => 'assignments'];

    /** One page of the feed, newest first (cursor pagination on created_at, id). */
    public static function page(User $user, string $filter, bool $archived, ?string $cursor, int $perPage): CursorPaginator
    {
        $query = self::own($user)
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

        return $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    /**
     * One row for the web UI. `category` comes from the catalog and is null for unknown types (never throws).
     *
     * @return array{id: string, type: string|null, category: string|null, data: array<string, mixed>, read_at: string|null, archived_at: string|null, created_at: string|null}
     */
    public static function present(InboxNotification $notification): array
    {
        $data = self::data($notification);
        $type = self::type($notification);

        return [
            'id' => $notification->id,
            'type' => $type,
            'category' => self::category($notification),
            'data' => $data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'archived_at' => $notification->archived_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function data(InboxNotification $notification): array
    {
        return is_array($notification->data) ? $notification->data : [];
    }

    public static function type(InboxNotification $notification): ?string
    {
        $type = self::data($notification)['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public static function category(InboxNotification $notification): ?string
    {
        $type = self::type($notification);

        return $type !== null ? (NotificationCatalog::TYPES[$type] ?? null) : null;
    }

    public static function own(User $user): Builder
    {
        return InboxNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }

    public static function unread(User $user): int
    {
        return self::own($user)->whereNull('archived_at')->whereNull('read_at')->count();
    }

    /**
     * read / unread / archive / unarchive on the user's own rows. Unknown ids (or another person's)
     * are a 404 when `$findOrFail` (a single row), else skipped (bulk). Returns the new unread count.
     *
     * @param  list<string>  $ids
     */
    public static function apply(User $user, array $ids, string $action, bool $findOrFail = true): int
    {
        $rows = fn () => self::own($user)->whereKey($ids);
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

        $unread = self::unread($user);
        self::announce($user, $unread);

        return $unread;
    }

    /**
     * Mark every unread inbox notification as read — with `$before`, only those created until then, so what
     * arrived later stays unread (an app's offline "read all"). Returns the new unread count.
     */
    public static function markAllRead(User $user, ?CarbonInterface $before = null): int
    {
        self::own($user)->whereNull('archived_at')->whereNull('read_at')
            ->when($before !== null, fn (Builder $q) => $q->where('created_at', '<=', $before))
            ->update(['read_at' => now()]);
        $unread = $before === null ? 0 : self::unread($user);
        self::announce($user, $unread);

        return $unread;
    }

    /** The user's other tabs and apps (bell badge, tab title) follow a read / archive at once (BRAI-151). */
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
