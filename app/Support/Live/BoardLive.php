<?php

namespace App\Support\Live;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\ContentReference;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Plan;
use App\Models\Sprint;
use App\Models\TimeEntry;
use App\Support\Imports\ImportScope;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Live boards + plans (BRAI-152): every change to a card, column, sprint or plan publishes to
 * `board.{id}` / `plan.{id}` (and the card's `item.{id}`), so open boards and Gantt charts update
 * without a reload.
 *
 * - **Where:** model events (register(), every write path: UI, MCP, automations, offline sync, API,
 *   GitHub, recurring issues …) plus explicit calls where a write bypasses them (query-builder
 *   updates of positions / subtrees, label pivots, custom field values).
 * - **Coalesced:** changes are collected per request / queued job / command and sent once at its
 *   end (deferred, like Live itself): one card → a precise event (`item.moved` …), several cards
 *   of one board → one `items.changed` with their ids (bulk edit, sprint moves, subtrees).
 * - **Imports:** inside ImportScope nothing is sent per record; the boards and plans an import
 *   touched get one `board.refreshed` / `plan.updated` when it ends (importFinished()).
 * - **Payloads:** ids, column ids, positions, field *names*, revisions and the sender tab's
 *   `client_id` + `actor_id` (X-Live-Client + the user: that tab ignores its own echo) — never content
 *   (Live::KEY).
 *   Clients refetch through permission-checked endpoints (`boards.cards`, `plans.document`).
 * - A no-op (no work at all) while publishing is off.
 */
final class BoardLive
{
    /** Header the browser tab sends with its own changes (echo suppression). */
    public const CLIENT_HEADER = 'X-Live-Client';

    /** Most ids per batched message (Live's list limit; keeps the payload under 2 KB). */
    private const CHUNK = 100;

    /** Cards of one board that still get their own `item.{id}` message in one flush. */
    private const ITEM_CHANNEL_LIMIT = 10;

    /** Card attributes a linked plan row shows (PlanIssueSync / ProjectIssues::summaries). */
    private const PLAN_FIELDS = ['title' => true, 'start_date' => true, 'due_date' => true, 'column_id' => true, 'assigned_to' => true, 'completed_at' => true, 'item_type_id' => true, 'priority_id' => true, 'parent_id' => true, 'board_id' => true];

    /** Item attributes never worth an event of their own. */
    private const IGNORED_FIELDS = ['updated_at', 'created_at', 'metadata'];

    /** @var array<int, array{items: array<int, array{kind: string, fields: array<string, true>, column_id?: int|null, from_column_id?: int|null, position?: int|null, board_id?: int}>, columns: array<int, array{kind: string, fields: array<string, true>}>, reordered: bool, sprints: array<int, array{action: string, status: string|null}>, board: array<string, true>|null, deleted: bool, refreshed: bool}> */
    private static array $boards = [];

    /** @var array<int, array{revision: int|null, fields: array<string, true>, deleted: bool}> */
    private static array $plans = [];

    /** Boards / plans an import touched (one refresh each when it ends). */
    private static array $importBoards = [];

    private static array $importPlans = [];

    /** @var array<int, array<string, true>> card id => fields, board not known yet (resolved in flush) */
    private static array $unresolved = [];

    /** Model hooks: called once from LiveServiceProvider::boot(). */
    public static function register(): void
    {
        Item::created(fn (Item $item) => self::item($item, 'created'));
        Item::updated(function (Item $item) {
            // a restore saves the model too: `restored` reports it
            if ($item->wasChanged($item->getDeletedAtColumn())) {
                return;
            }
            if ($item->wasChanged('board_id')) {
                self::itemsLeft((int) $item->getOriginal('board_id'), (int) $item->board_id, [$item->id], [$item->id => $item->column_id]);

                return;
            }
            $fields = array_diff(array_keys($item->getChanges()), self::IGNORED_FIELDS);
            if ($fields !== []) {
                self::item($item, $item->wasChanged('column_id') ? 'moved' : 'updated', $fields);
            }
        });
        Item::deleted(fn (Item $item) => self::item($item, 'deleted'));
        Item::restored(fn (Item $item) => self::item($item, 'restored'));

        // what a card shows besides its own columns (counts, links, time)
        Comment::created(fn (Comment $comment) => self::itemIds([(int) $comment->item_id], ['comments']));
        Comment::deleted(fn (Comment $comment) => self::itemIds([(int) $comment->item_id], ['comments']));
        Attachment::created(fn (Attachment $attachment) => self::itemIds([(int) $attachment->item_id], ['attachments']));
        Attachment::deleted(fn (Attachment $attachment) => self::itemIds([(int) $attachment->item_id], ['attachments']));
        ItemLink::created(fn (ItemLink $link) => self::itemIds([(int) $link->source_item_id, (int) $link->target_item_id], ['links']));
        ItemLink::deleted(fn (ItemLink $link) => self::itemIds([(int) $link->source_item_id, (int) $link->target_item_id], ['links']));
        $timeLogged = fn (TimeEntry $entry) => self::itemIds(array_values(array_unique([(int) $entry->item_id, (int) $entry->getOriginal('item_id')])), ['time_spent']);
        TimeEntry::created($timeLogged);
        TimeEntry::updated($timeLogged);
        TimeEntry::deleted($timeLogged);

        BoardColumn::created(fn (BoardColumn $column) => self::column($column, 'created'));
        BoardColumn::updated(fn (BoardColumn $column) => self::column($column, 'updated', array_diff(array_keys($column->getChanges()), self::IGNORED_FIELDS)));
        BoardColumn::deleted(fn (BoardColumn $column) => self::column($column, 'deleted'));

        Sprint::created(fn (Sprint $sprint) => self::sprint($sprint, 'created'));
        Sprint::updated(fn (Sprint $sprint) => self::sprint($sprint, $sprint->wasChanged('status') ? (string) $sprint->status : 'updated'));
        Sprint::deleted(fn (Sprint $sprint) => self::sprint($sprint, 'deleted'));

        Board::updated(function (Board $board) {
            $fields = array_diff(array_keys($board->getChanges()), self::IGNORED_FIELDS);
            if ($fields !== []) {
                self::board((int) $board->id, $fields);
            }
        });
        Board::deleted(fn (Board $board) => self::board((int) $board->id, [], deleted: true));

        Plan::updated(function (Plan $plan) {
            $fields = array_diff(array_keys($plan->getChanges()), self::IGNORED_FIELDS);
            if ($fields !== []) {
                self::plan($plan, $fields);
            }
        });
        Plan::deleted(fn (Plan $plan) => self::plan($plan, [], deleted: true));
    }

    /**
     * One card changed. $kind: created | updated | moved | deleted | restored.
     *
     * @param  list<string>  $fields  names of the changed attributes (never their values)
     */
    public static function item(Item $item, string $kind, array $fields = []): void
    {
        $boardId = (int) $item->board_id;
        if (! self::accepting($boardId)) {
            return;
        }
        // read now (originals are gone after the save), collect once the change is committed
        $id = (int) $item->id;
        $extra = [
            'column_id' => $item->column_id !== null ? (int) $item->column_id : null,
            'from_column_id' => $kind === 'moved' && $item->getOriginal('column_id') !== null ? (int) $item->getOriginal('column_id') : null,
            'position' => $item->position !== null ? (int) $item->position : null,
        ];
        self::committed(fn () => self::mergeItem($boardId, $id, $kind, $fields, $extra));
    }

    /**
     * Cards changed without model events (query-builder updates, pivots, related rows). Without a
     * board, the cards' boards are looked up once for the whole flush.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $fields
     */
    public static function itemIds(array $ids, array $fields, ?int $boardId = null): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === [] || ! self::enabled()) {
            return;
        }
        if ($boardId === null) {
            if (ImportScope::active()) {
                return; // the imported cards' own events already mark their boards
            }
            self::committed(function () use ($ids, $fields) {
                foreach ($ids as $id) {
                    self::$unresolved[$id] = (self::$unresolved[$id] ?? []) + array_fill_keys($fields, true);
                }
            });

            return;
        }
        if (! self::accepting($boardId)) {
            return;
        }
        self::committed(function () use ($ids, $fields, $boardId) {
            foreach ($ids as $id) {
                self::mergeItem($boardId, $id, 'updated', $fields);
            }
        });
    }

    /**
     * Cards moved to another board: they leave $from and arrive on $to.
     *
     * @param  list<int>  $ids
     * @param  array<int, int|null>  $columns  card id => its new column
     */
    public static function itemsLeft(int $from, int $to, array $ids, array $columns = []): void
    {
        $leave = self::accepting($from);
        $arrive = self::accepting($to);
        if (! $leave && ! $arrive) {
            return;
        }
        self::committed(function () use ($from, $to, $ids, $columns, $leave, $arrive) {
            foreach ($ids as $id) {
                if ($leave) {
                    self::mergeItem($from, (int) $id, 'moved_out', ['board_id']);
                }
                if ($arrive) {
                    self::mergeItem($to, (int) $id, 'moved_in', ['board_id'], ['column_id' => $columns[$id] ?? null]);
                }
            }
        });
    }

    /**
     * @param  list<string>  $fields
     */
    public static function column(BoardColumn $column, string $kind, array $fields = []): void
    {
        $boardId = (int) $column->board_id;
        $id = (int) $column->id;
        if (($kind === 'updated' && $fields === []) || ! self::accepting($boardId)) {
            return;
        }
        self::committed(function () use ($boardId, $id, $kind, $fields) {
            $entry = self::$boards[$boardId]['columns'][$id] ?? ['kind' => $kind, 'fields' => []];
            $entry['kind'] = in_array($entry['kind'], ['created', 'deleted'], true) && $kind === 'updated' ? $entry['kind'] : $kind;
            $entry['fields'] += array_fill_keys($fields, true);
            self::$boards[$boardId]['columns'][$id] = $entry;
        });
    }

    /**
     * Columns got new positions (query builder).
     *
     * @param  list<int>  $ids
     */
    public static function columnsReordered(int $boardId, array $ids): void
    {
        if (! self::accepting($boardId)) {
            return;
        }
        self::committed(function () use ($boardId, $ids) {
            foreach ($ids as $id) {
                self::$boards[$boardId]['columns'][(int) $id] ??= ['kind' => 'updated', 'fields' => []];
                self::$boards[$boardId]['columns'][(int) $id]['fields']['position'] = true;
            }
            self::$boards[$boardId]['reordered'] = true;
        });
    }

    public static function sprint(Sprint $sprint, string $action): void
    {
        $boardId = (int) $sprint->board_id;
        if (! self::accepting($boardId)) {
            return;
        }
        $id = (int) $sprint->id;
        $status = $sprint->status !== null ? (string) $sprint->status : null;
        self::committed(function () use ($boardId, $id, $action, $status) {
            self::$boards[$boardId]['sprints'][$id] = ['action' => $action, 'status' => $status];
        });
    }

    /**
     * Board settings changed (name, type, swimlanes …) or the board was deleted.
     *
     * @param  list<string>  $fields
     */
    public static function board(int $boardId, array $fields, bool $deleted = false): void
    {
        if (! self::accepting($boardId)) {
            return;
        }
        self::committed(function () use ($boardId, $fields, $deleted) {
            self::$boards[$boardId]['board'] = (self::$boards[$boardId]['board'] ?? []) + array_fill_keys($fields, true);
            self::$boards[$boardId]['deleted'] = $deleted || (self::$boards[$boardId]['deleted'] ?? false);
        });
    }

    /**
     * The plan document (revision), its name / sharing, or the plan itself changed.
     *
     * @param  list<string>  $fields
     */
    public static function plan(Plan $plan, array $fields, bool $deleted = false): void
    {
        if (! self::enabled()) {
            return;
        }
        $id = (int) $plan->id;
        if (ImportScope::active()) {
            self::$importPlans[$id] = true;

            return;
        }
        $revision = (int) $plan->edit_revision;
        self::committed(function () use ($id, $revision, $fields, $deleted) {
            $entry = self::$plans[$id] ?? ['revision' => null, 'fields' => [], 'deleted' => false];
            $entry['revision'] = max($revision, (int) $entry['revision']);
            $entry['fields'] += array_fill_keys($fields, true);
            $entry['deleted'] = $entry['deleted'] || $deleted;
            self::$plans[$id] = $entry;
        });
    }

    /** An import ended (ImportScope): one refresh per board / plan it touched. */
    public static function importFinished(): void
    {
        $boards = array_keys(self::$importBoards);
        $plans = array_keys(self::$importPlans);
        self::$importBoards = self::$importPlans = [];
        if ($boards === [] && $plans === []) {
            return;
        }
        foreach ($boards as $boardId) {
            self::$boards[$boardId]['refreshed'] = true;
        }
        foreach ($plans as $planId) {
            self::$plans[$planId] ??= ['revision' => (int) Plan::withTrashed()->whereKey($planId)->value('edit_revision'), 'fields' => [], 'deleted' => false];
        }
        self::schedule();
    }

    /** Send what was collected (deferred to the end of the request / job / command). Never throws. */
    public static function flush(): void
    {
        self::resolveBoards();
        $boards = self::$boards;
        $plans = self::$plans;
        self::$boards = self::$plans = [];
        if ($boards === [] && $plans === []) {
            return;
        }

        try {
            $client = self::clientId();
            $touched = [];
            foreach ($boards as $boardId => $board) {
                $touched = [...$touched, ...self::publishBoard((int) $boardId, $board, $client)];
            }
            foreach ($plans as $planId => $plan) {
                self::publishPlan((int) $planId, $plan, $client);
            }
            self::publishLinkedPlans($touched);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            report($e);
        }

        // this runs among the deferred callbacks: send now instead of waiting for Live's own flush
        Live::flush();
    }

    /** Forget everything collected (tests). */
    public static function reset(): void
    {
        self::$boards = self::$plans = self::$importBoards = self::$importPlans = self::$unresolved = [];
    }

    /** Cards touched by id only: their boards, one query for the whole flush. */
    private static function resolveBoards(): void
    {
        $unresolved = self::$unresolved;
        self::$unresolved = [];
        if ($unresolved === []) {
            return;
        }
        try {
            $boards = Item::withTrashed()->whereIn('id', array_keys($unresolved))->pluck('board_id', 'id');
            foreach ($boards as $id => $boardId) {
                if ($boardId !== null && self::accepting((int) $boardId)) {
                    self::mergeItem((int) $boardId, (int) $id, 'updated', array_keys($unresolved[$id] ?? []));
                }
            }
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            report($e);
        }
    }

    /**
     * Collect once the change is committed (at once outside a transaction): a rolled-back delete or
     * move never removes a card from an open board.
     */
    private static function committed(Closure $collect): void
    {
        DB::afterCommit(function () use ($collect) {
            $collect();
            self::schedule();
        });
    }

    /**
     * @param  array<string, mixed>  $board
     * @return list<int> ids of cards that changed (for linked plans)
     */
    private static function publishBoard(int $boardId, array $board, ?string $client): array
    {
        $channel = "board.{$boardId}";
        $base = self::sender($client);

        if ($board['deleted'] ?? false) {
            Live::publish($channel, 'board.deleted', $base);

            return [];
        }
        if ($board['refreshed'] ?? false) {
            Live::publish($channel, 'board.refreshed');
        }
        if (($board['board'] ?? null) !== null) {
            Live::publish($channel, 'board.updated', $base + ['fields' => array_slice(array_keys($board['board']), 0, 20)]);
        }

        $columns = $board['columns'] ?? [];
        if ($board['reordered'] ?? false) {
            foreach (array_chunk(array_keys($columns), self::CHUNK) as $ids) {
                Live::publish($channel, 'columns.reordered', $base + ['ids' => $ids]);
            }
        } elseif (count($columns) === 1) {
            $id = array_key_first($columns);
            $column = $columns[$id];
            Live::publish($channel, "column.{$column['kind']}", $base + ['id' => $id] + ($column['fields'] !== [] ? ['fields' => array_keys($column['fields'])] : []));
        } elseif ($columns !== []) {
            foreach (array_chunk(array_keys($columns), self::CHUNK) as $ids) {
                Live::publish($channel, 'columns.changed', $base + ['ids' => $ids]);
            }
        }

        foreach ($board['sprints'] ?? [] as $id => $sprint) {
            Live::publish($channel, 'sprint.changed', $base + ['id' => $id, 'action' => $sprint['action'], 'status' => $sprint['status']]);
        }

        $items = $board['items'] ?? [];
        if (count($items) === 1) {
            $id = array_key_first($items);
            self::publishItem($channel, $id, $items[$id], $base);
        } elseif ($items !== []) {
            $fields = [];
            foreach ($items as $item) {
                $fields += $item['fields'];
            }
            $ids = array_keys($items);
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $gone = array_values(array_filter($chunk, fn (int $id) => in_array($items[$id]['kind'], ['deleted', 'moved_out'], true)));
                Live::publish($channel, 'items.changed', $base + [
                    'ids' => array_values(array_diff($chunk, $gone)),
                    'deleted_ids' => $gone,
                    'count' => count($ids),
                    'fields' => array_slice(array_keys($fields), 0, 30),
                ]);
            }
        }
        // the card's own channel (an open issue page / dialog) — only for a handful of cards. The same
        // `item.updated` {id, fields} the issue view already handles; title / description are
        // published there by Item itself (live basics), so only the other fields (+ deleted) go here.
        if (count($items) <= self::ITEM_CHANNEL_LIMIT) {
            foreach ($items as $id => $item) {
                if ($item['kind'] === 'created' || $item['kind'] === 'moved_in') {
                    continue;
                }
                $fields = array_values(array_diff(array_keys($item['fields']), ['title', 'description', 'comments'])); // those: Item / LiveThreads
                if (in_array($item['kind'], ['deleted', 'moved_out', 'restored'], true)) {
                    $fields[] = $item['kind'];
                }
                if ($fields !== []) {
                    Live::publish("item.{$id}", 'item.updated', $base + ['id' => $id, 'fields' => array_slice($fields, 0, 30)]);
                }
            }
        }

        // only what a plan row shows (name, dates, status, assignee …) reaches the linked plans
        return array_keys(array_filter($items, fn (array $item) => $item['kind'] !== 'updated'
            || array_intersect_key($item['fields'], self::PLAN_FIELDS) !== []));
    }

    /**
     * @param  array{kind: string, fields: array<string, true>, column_id?: int|null, from_column_id?: int|null, position?: int|null, board_id?: int}  $item
     * @param  array<string, string>  $base
     */
    private static function publishItem(string $channel, int $id, array $item, array $base): void
    {
        $fields = array_slice(array_keys($item['fields']), 0, 30);
        match ($item['kind']) {
            'created', 'restored' => Live::publish($channel, "item.{$item['kind']}", $base + ['id' => $id, 'column_id' => $item['column_id'] ?? null]),
            'moved' => Live::publish($channel, 'item.moved', $base + [
                'id' => $id,
                'column_id' => $item['column_id'] ?? null,
                'from_column_id' => $item['from_column_id'] ?? null,
                'position' => $item['position'] ?? null,
                'fields' => $fields,
            ]),
            'deleted' => Live::publish($channel, 'item.deleted', $base + ['id' => $id]),
            'moved_out' => Live::publish($channel, 'item.moved_out', $base + ['id' => $id]),
            'moved_in' => Live::publish($channel, 'item.moved_in', $base + ['id' => $id, 'column_id' => $item['column_id'] ?? null]),
            default => Live::publish($channel, 'item.updated', $base + ['id' => $id, 'fields' => $fields]),
        };
    }

    /**
     * @param  array{revision: int|null, fields: array<string, true>, deleted: bool}  $plan
     */
    private static function publishPlan(int $planId, array $plan, ?string $client): void
    {
        $base = self::sender($client);
        if ($plan['deleted']) {
            Live::publish("plan.{$planId}", 'plan.deleted', $base);

            return;
        }
        Live::publish("plan.{$planId}", 'plan.updated', $base + [
            'revision' => $plan['revision'],
            'fields' => array_slice(array_keys($plan['fields']), 0, 20),
        ]);
    }

    /**
     * Plans whose rows are linked to changed issues (PlanIssueSync, mirrored by the derived content
     * references): `issues.changed` with the linked ids, one query for the whole flush.
     *
     * @param  list<int>  $itemIds
     */
    private static function publishLinkedPlans(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }
        $links = ContentReference::query()
            ->where('source_type', 'plan')
            ->where('target_type', 'item')
            ->whereIn('target_id', array_slice(array_values(array_unique($itemIds)), 0, 1000))
            ->get(['source_id', 'target_id'])
            ->groupBy('source_id');
        foreach ($links as $planId => $rows) {
            $ids = $rows->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                Live::publish("plan.{$planId}", 'issues.changed', ['ids' => $chunk]);
            }
        }
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, int|null>  $extra
     */
    private static function mergeItem(int $boardId, int $id, string $kind, array $fields = [], array $extra = []): void
    {
        $current = self::$boards[$boardId]['items'][$id] ?? null;
        $entry = $current ?? ['kind' => $kind, 'fields' => []];
        $entry['kind'] = self::strongerKind($current['kind'] ?? null, $kind);
        $entry['fields'] += array_fill_keys(array_values($fields), true);
        foreach ($extra as $key => $value) {
            // the first move keeps where the card came from; the rest is the latest state
            if ($key === 'from_column_id' && isset($entry[$key])) {
                continue;
            }
            $entry[$key] = $value;
        }
        self::$boards[$boardId]['items'][$id] = $entry;
    }

    /** What one request did to a card, all changes together. */
    private static function strongerKind(?string $current, string $next): string
    {
        $rank = ['updated' => 0, 'moved' => 1, 'restored' => 2, 'created' => 3, 'moved_in' => 4, 'moved_out' => 5, 'deleted' => 6];
        if ($current === null) {
            return $next;
        }
        // a card deleted and restored in one go is simply back; out and back in again just moved
        if ($current === 'deleted' && $next === 'restored') {
            return 'restored';
        }
        if (($current === 'moved_out' && $next === 'moved_in') || ($current === 'moved_in' && $next === 'moved_out')) {
            return 'moved';
        }

        return $rank[$next] > $rank[$current] ? $next : $current;
    }

    /** Collect for this board now? (publishing on; during an import only note the board). */
    private static function accepting(int $boardId): bool
    {
        if ($boardId <= 0 || ! self::enabled()) {
            return false;
        }
        if (ImportScope::active()) {
            self::$importBoards[$boardId] = true;

            return false;
        }

        return true;
    }

    private static function enabled(): bool
    {
        return LiveConfig::publishUrl() !== null;
    }

    private static function schedule(): void
    {
        // the same name every time: Laravel keeps one (the last), invoked at the end
        defer(fn () => self::flush(), 'live.board')->always();
    }

    /**
     * Who sent the change: the tab id together with the acting user, so a tab only treats an event as
     * its own echo when both match (another member reusing a seen tab id can't hide changes from it).
     *
     * @return array<string, int|string>
     */
    private static function sender(?string $client): array
    {
        $userId = $client !== null ? auth()->id() : null;

        return $userId !== null ? ['client_id' => $client, 'actor_id' => (int) $userId] : [];
    }

    /** The browser tab that made this change (its own echo is ignored there). */
    private static function clientId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }
        $value = request()->header(self::CLIENT_HEADER);

        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{8,40}\z/', $value) === 1 ? $value : null;
    }
}
