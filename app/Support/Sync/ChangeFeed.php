<?php

namespace App\Support\Sync;

use App\Models\Board;
use App\Models\Capture;
use App\Models\Item;
use App\Models\SyncTombstone;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\Captures\CaptureConversion;
use App\Support\MyDay\MyDayPlan;
use App\Support\MyWork\AssignedWork;
use App\Support\MyWork\WorkRef;
use App\Support\TaskLists\TaskLists;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The App API's change feed for offline clients (`GET /api/v1/sync/changes`, BRAI-425): what changed in
 * the content a client keeps offline since its cursor — and what it must drop.
 *
 * - **Scopes:** `work` (issues + tasks assigned to the user: My Work, `AssignedWork`), `task_lists` (the
 *   lists the client keeps: their tasks + the lists), `my_day` (today's plan, `MyDayPlan`), `captures`
 *   (the private inbox). Every query is the user's own / limited to the projects they can open now
 *   (`User::accessibleProjectIds`, the web's rule), so nobody else's data can show up.
 * - **Runs:** a run walks fixed phases (upserts first, then deletions) with keyset pagination inside each.
 *   The first run has no `since`: a full copy (`reset`), the client drops what it didn't get. Every later
 *   run sends what changed since the previous run started (10 s overlap: a change can come twice, never
 *   not at all). My Day is one record, sent when its hash changed.
 * - **Deletions:** soft deletes (`deleted_at` of issues, tasks, lists, and of the board / list they sit
 *   on) plus `sync_tombstones` (captures deleted for good, work reassigned to someone else). A cursor older
 *   than the tombstones' 30 days starts a full run. Lost access is not a row anywhere: the answer's
 *   `project_ids` lists what the user can open now.
 */
final class ChangeFeed
{
    public const WORK = 'work';

    public const TASK_LISTS = 'task_lists';

    public const MY_DAY = 'my_day';

    public const CAPTURES = 'captures';

    public const SCOPES = [self::WORK, self::TASK_LISTS, self::MY_DAY, self::CAPTURES];

    /** Most task lists one client keeps (the web's offline mode keeps the last 20). */
    public const MAX_LISTS = 50;

    /** Seconds the next run reaches back before this run's start (late commits are never missed). */
    public const OVERLAP_SECONDS = 10;

    /** phase => the scope it belongs to (null = work and captures) */
    private const PHASES = [
        'taskLists' => self::TASK_LISTS,
        'listTasks' => self::TASK_LISTS,
        'workIssues' => self::WORK,
        'workTasks' => self::WORK,
        'captures' => self::CAPTURES,
        'myDay' => self::MY_DAY,
        'goneWorkIssues' => self::WORK,
        'goneWorkTasks' => self::WORK,
        'goneListTasks' => self::TASK_LISTS,
        'goneTaskLists' => self::TASK_LISTS,
        'goneTombstones' => null,
    ];

    private const TIMESTAMP = '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?\z/';

    /** @var list<int> */
    private array $projectIds;

    /** @var list<int>|null */
    private ?array $visibleLists = null;

    private function __construct(private readonly User $user, private readonly SyncCursor $cursor)
    {
        $ids = array_map('intval', $user->accessibleProjectIds());
        sort($ids);
        $this->projectIds = array_values(array_unique($ids));
    }

    /**
     * One page of the feed.
     *
     * @param  list<string>|null  $scopes  null = the cursor's (a different scope starts a full run)
     * @param  list<int>|null  $lists  task lists to keep, null = the cursor's
     * @return array{changes: list<SyncChange>, next_cursor: string, has_more: bool, reset: bool, project_ids: list<int>}
     */
    public static function page(User $user, ?string $cursor, ?array $scopes, ?array $lists, int $limit): array
    {
        return (new self($user, self::start($user, $cursor, $scopes, $lists)))->run(max(1, $limit));
    }

    /**
     * @param  list<string>|null  $scopes
     * @param  list<int>|null  $lists
     */
    private static function start(User $user, ?string $cursor, ?array $scopes, ?array $lists): SyncCursor
    {
        $current = $cursor !== null ? SyncCursor::decode($cursor, $user->id) : null;
        if ($current !== null) {
            $changed = ($scopes !== null && SyncCursor::sortedScopes($scopes) !== $current->scopes)
                || ($lists !== null && SyncCursor::sortedLists($lists) !== $current->lists);
            // tombstones of older changes are gone: only a full run is complete
            $expired = $current->since !== null && $current->since->lt(CarbonImmutable::now()->subDays(SyncTombstone::KEEP_DAYS));
            if (! $changed && ! $expired) {
                return $current;
            }
            $scopes ??= $current->scopes;
            $lists ??= $current->lists;
        }

        return SyncCursor::fresh($user->id, $scopes ?? self::SCOPES, $lists ?? []);
    }

    /** @return array{changes: list<SyncChange>, next_cursor: string, has_more: bool, reset: bool, project_ids: list<int>} */
    private function run(int $limit): array
    {
        $this->cursor->startedAt ??= CarbonImmutable::now('UTC');
        $phases = array_keys(self::PHASES);
        $changes = [];

        while (count($changes) < $limit && $this->cursor->phase < count($phases)) {
            $phase = $phases[$this->cursor->phase];
            if (! $this->active($phase)) {
                $this->advance();

                continue;
            }
            [$batch, $more, $key] = $this->{$phase}($limit - count($changes));
            array_push($changes, ...$batch);
            if ($more) {
                $this->cursor->key = $key;

                break;
            }
            $this->advance();
        }

        $finished = $this->cursor->phase >= count($phases);

        return [
            'changes' => $changes,
            'next_cursor' => ($finished ? $this->cursor->nextRun(self::OVERLAP_SECONDS) : $this->cursor)->encode(),
            'has_more' => ! $finished,
            'reset' => $this->cursor->reset,
            'project_ids' => $this->projectIds,
        ];
    }

    private function active(string $phase): bool
    {
        // a full run has nothing to delete: the client drops what it didn't get
        if (str_starts_with($phase, 'gone') && $this->cursor->since === null) {
            return false;
        }
        $scope = self::PHASES[$phase];

        return $scope === null
            ? $this->in(self::WORK) || $this->in(self::CAPTURES)
            : $this->in($scope);
    }

    private function in(string $scope): bool
    {
        return in_array($scope, $this->cursor->scopes, true);
    }

    private function advance(): void
    {
        $this->cursor->phase++;
        $this->cursor->key = null;
    }

    // ── Upserts ──────────────────────────────────────────────────────────────────────────────

    /** @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function taskLists(int $need): array
    {
        $since = $this->cursor->since;
        $query = TaskLists::withCounts(TaskList::query()->with('project.workspace')->whereIn('task_lists.id', $this->visibleLists()))
            // a list's counts follow its tasks: send it again when one of them changed
            ->when($since, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('task_lists.updated_at', '>=', $since)
                ->orWhereExists(fn (QueryBuilder $tasks) => $tasks->selectRaw('1')->from('task_list_items')
                    ->whereColumn('task_list_items.task_list_id', 'task_lists.id')
                    ->where(fn (QueryBuilder $c) => $c->where('task_list_items.updated_at', '>=', $since)->orWhere('task_list_items.deleted_at', '>=', $since)))));

        return $this->upserts($query, null, 'task_lists.id', $need, fn (TaskList $list) => new SyncChange(
            'task_list', (string) $list->id, SyncChange::UPSERT, (int) $list->project_id, $list->updated_at, $list,
        ));
    }

    /** @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function listTasks(int $need): array
    {
        $query = TaskListItem::query()
            ->with(['assignee', 'taskList.project.workspace'])
            ->whereIn('task_list_items.task_list_id', $this->visibleLists())
            ->when($this->cursor->since, fn (Builder $q, $since) => $q->where('task_list_items.updated_at', '>=', $since));

        return $this->upserts($query, 'task_list_items.updated_at', 'task_list_items.id', $need, fn (TaskListItem $task) => new SyncChange(
            'task', (string) $task->id, SyncChange::UPSERT, (int) $task->taskList->project_id, $task->updated_at, $task,
        ));
    }

    /** @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function workIssues(int $need): array
    {
        $since = $this->cursor->since;
        // a full run: open work (My Work's default); later runs also send work that was completed
        $query = AssignedWork::issues($this->user, $this->projectIds, $since ? AssignedWork::ALL : AssignedWork::OPEN)
            ->with(WorkRef::ISSUE_WITH)
            ->when($since, fn (Builder $q) => $q->where('items.updated_at', '>=', $since));

        return $this->upserts($query, 'items.updated_at', 'items.id', $need, fn (Item $item) => new SyncChange(
            self::WORK, WorkRef::of($item), SyncChange::UPSERT, (int) $item->board->project_id, $item->updated_at, $item,
        ));
    }

    /** @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function workTasks(int $need): array
    {
        $since = $this->cursor->since;
        $query = AssignedWork::tasks($this->user, $this->projectIds, $since ? AssignedWork::ALL : AssignedWork::OPEN)
            ->with(WorkRef::TASK_WITH)
            ->when($since, fn (Builder $q) => $q->where('task_list_items.updated_at', '>=', $since));

        return $this->upserts($query, 'task_list_items.updated_at', 'task_list_items.id', $need, fn (TaskListItem $task) => new SyncChange(
            self::WORK, WorkRef::of($task), SyncChange::UPSERT, (int) $task->taskList->project_id, $task->updated_at, $task,
        ));
    }

    /** @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function captures(int $need): array
    {
        $query = Capture::query()
            ->where('captures.user_id', $this->user->id)
            ->when($this->cursor->since, fn (Builder $q, $since) => $q->where('captures.updated_at', '>=', $since));

        [$rows, $more, $key] = $this->fetch($query, 'captures.updated_at', 'captures.id', $need);
        $locations = CaptureConversion::locations($rows, $this->user);
        $changes = $rows->map(fn (Capture $capture) => new SyncChange(
            'capture', (string) $capture->uuid, SyncChange::UPSERT, null, $capture->updated_at, $capture, $locations[$capture->id] ?? null,
        ))->all();

        return [$changes, $more, $key];
    }

    /**
     * Today's plan as one record, when it differs from the last one sent (entries, order, their work,
     * "Bring over" count) — a removal or a new day changes it too.
     *
     * @return array{0: list<SyncChange>, 1: bool, 2: null}
     */
    private function myDay(int $need): array
    {
        $date = MyDayPlan::today($this->user);
        $rows = MyDayPlan::forDate($this->user, $date, WorkRef::ISSUE_WITH, WorkRef::TASK_WITH);
        $carryOver = MyDayPlan::carryOverCandidates($this->user, $date)->count();

        $hash = hash('sha256', (string) json_encode([$date, $carryOver, $rows->map(fn (array $row) => [
            WorkRef::of($row['work']),
            (int) $row['entry']->position,
            $row['work']->updated_at?->format('Y-m-d H:i:s'),
            AssignedWork::isDone($row['work']),
        ])->all()]));
        $previous = $this->cursor->myDayHash;
        $this->cursor->myDayHash = $hash;
        if (! $this->cursor->reset && $previous === $hash) {
            return [[], false, null];
        }

        return [[new SyncChange(self::MY_DAY, $date, SyncChange::UPSERT, null, CarbonImmutable::now('UTC'), [
            'date' => $date,
            'items' => $rows->pluck('work')->values(),
            'carry_over_count' => $carryOver,
        ])], false, null];
    }

    // ── Deletions (runs with a `since` only) ─────────────────────────────────────────────────

    /** Issues assigned to the user that were deleted, or whose board was. @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function goneWorkIssues(int $need): array
    {
        $since = $this->cursor->since;
        $query = Item::withTrashed()
            ->select('items.id')
            ->where('items.assigned_to', $this->user->id)
            ->whereIn('items.board_id', Board::withTrashed()->whereIn('project_id', $this->projectIds)->select('id'))
            ->where(fn (Builder $q) => $q
                ->where('items.deleted_at', '>=', $since)
                ->orWhereIn('items.board_id', Board::onlyTrashed()->where('deleted_at', '>=', $since)->select('id')));

        return $this->deletes($query, 'items.id', $need, fn (Item $item) => WorkRef::ISSUE.':'.$item->id, self::WORK);
    }

    /** Tasks assigned to the user that were deleted, or whose list was. @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function goneWorkTasks(int $need): array
    {
        $since = $this->cursor->since;
        $query = TaskListItem::withTrashed()
            ->select('task_list_items.id')
            ->where('task_list_items.assignee_id', $this->user->id)
            ->whereIn('task_list_items.task_list_id', TaskList::withTrashed()->whereIn('project_id', $this->projectIds)->select('id'))
            ->where(fn (Builder $q) => $q
                ->where('task_list_items.deleted_at', '>=', $since)
                ->orWhereIn('task_list_items.task_list_id', TaskList::onlyTrashed()->where('deleted_at', '>=', $since)->select('id')));

        return $this->deletes($query, 'task_list_items.id', $need, fn (TaskListItem $task) => WorkRef::TASK.':'.$task->id, self::WORK);
    }

    /** Deleted tasks of the kept lists (a deleted list comes as one `task_list` delete). @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function goneListTasks(int $need): array
    {
        $query = TaskListItem::onlyTrashed()
            ->select('task_list_items.id')
            ->whereIn('task_list_items.task_list_id', $this->visibleLists())
            ->where('task_list_items.deleted_at', '>=', $this->cursor->since);

        return $this->deletes($query, 'task_list_items.id', $need, fn (TaskListItem $task) => (string) $task->id, 'task');
    }

    /** Kept lists that were deleted (in a project the user can still open). @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function goneTaskLists(int $need): array
    {
        $query = TaskList::onlyTrashed()
            ->select('task_lists.id')
            ->whereIn('task_lists.id', $this->cursor->lists)
            ->whereIn('task_lists.project_id', $this->projectIds)
            ->where('task_lists.deleted_at', '>=', $this->cursor->since);

        return $this->deletes($query, 'task_lists.id', $need, fn (TaskList $list) => (string) $list->id, 'task_list');
    }

    /**
     * Captures deleted for good, and work reassigned away from the user (unless it is theirs again).
     *
     * @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null}
     */
    private function goneTombstones(int $need): array
    {
        $types = array_values(array_filter([
            $this->in(self::WORK) ? SyncTombstone::WORK : null,
            $this->in(self::CAPTURES) ? SyncTombstone::CAPTURE : null,
        ]));
        $query = SyncTombstone::query()
            ->where('user_id', $this->user->id)
            ->whereIn('entity_type', $types)
            ->where('created_at', '>=', $this->cursor->since);

        [$rows, $more, $key] = $this->fetch($query, null, 'sync_tombstones.id', $need);
        $mine = $this->stillAssigned($rows->where('entity_type', SyncTombstone::WORK)->pluck('entity_id'));
        $changes = $rows
            ->reject(fn (SyncTombstone $row) => $row->entity_type === SyncTombstone::WORK && isset($mine[$row->entity_id]))
            ->unique(fn (SyncTombstone $row) => $row->entity_type.'|'.$row->entity_id)
            ->map(fn (SyncTombstone $row) => new SyncChange($row->entity_type, (string) $row->entity_id, SyncChange::DELETE))
            ->values()
            ->all();

        return [$changes, $more, $key];
    }

    /**
     * Of these work refs, the ones assigned to the user again (and still there): reassigned back.
     *
     * @param  Collection<int, string>  $refs
     * @return array<string, true>
     */
    private function stillAssigned(Collection $refs): array
    {
        $parsed = $refs->map(fn (string $ref) => WorkRef::parse($ref))->filter();
        $issues = $parsed->where(0, WorkRef::ISSUE)->pluck(1)->all();
        $tasks = $parsed->where(0, WorkRef::TASK)->pluck(1)->all();
        $mine = [];
        if ($issues !== []) {
            Item::whereIn('id', $issues)->where('assigned_to', $this->user->id)
                ->whereIn('board_id', Board::whereIn('project_id', $this->projectIds)->select('id'))
                ->pluck('id')->each(function (int $id) use (&$mine) {
                    $mine[WorkRef::ISSUE.':'.$id] = true;
                });
        }
        if ($tasks !== []) {
            TaskListItem::whereIn('id', $tasks)->where('assignee_id', $this->user->id)
                ->whereIn('task_list_id', TaskList::whereIn('project_id', $this->projectIds)->select('id'))
                ->pluck('id')->each(function (int $id) use (&$mine) {
                    $mine[WorkRef::TASK.':'.$id] = true;
                });
        }

        return $mine;
    }

    // ── Paging ───────────────────────────────────────────────────────────────────────────────

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): SyncChange  $map
     * @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null}
     */
    private function upserts(Builder $query, ?string $timestamp, string $id, int $need, callable $map): array
    {
        [$rows, $more, $key] = $this->fetch($query, $timestamp, $id, $need);

        return [$rows->map($map)->values()->all(), $more, $key];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): string  $id
     * @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null}
     */
    private function deletes(Builder $query, string $column, int $need, callable $id, string $type): array
    {
        [$rows, $more, $key] = $this->fetch($query, null, $column, $need);

        return [$rows->map(fn (Model $row) => new SyncChange($type, $id($row), SyncChange::DELETE))->values()->all(), $more, $key];
    }

    /**
     * Keyset pagination inside a phase: by (timestamp, id) or by id. Fetches one row more than needed
     * to know whether the phase goes on.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{0: Collection<int, TModel>, 1: bool, 2: list<int|string>|null}
     */
    private function fetch(Builder $query, ?string $timestamp, string $id, int $need): array
    {
        $key = $this->cursor->key;
        if ($timestamp !== null) {
            if (is_array($key) && count($key) === 2 && is_string($key[0]) && preg_match(self::TIMESTAMP, $key[0]) === 1) {
                [$at, $after] = [$key[0], (int) $key[1]];
                $query->where(fn (Builder $q) => $q->where($timestamp, '>', $at)
                    ->orWhere(fn (Builder $same) => $same->where($timestamp, '=', $at)->where($id, '>', $after)));
            }
            $query->orderBy($timestamp)->orderBy($id);
        } else {
            if (is_array($key) && count($key) === 1) {
                $query->where($id, '>', (int) $key[0]);
            }
            $query->orderBy($id);
        }

        $rows = $query->limit($need + 1)->get();
        $more = $rows->count() > $need;
        $rows = $rows->take($need)->values();
        $last = $rows->last();
        $attribute = substr((string) strrchr('.'.$timestamp, '.'), 1);
        $next = match (true) {
            $last === null => null,
            $timestamp !== null => [(string) $last->getRawOriginal($attribute), (int) $last->getKey()],
            default => [(int) $last->getKey()],
        };

        return [$rows, $more, $next];
    }

    /** @return list<int> the kept lists the user can open (not deleted) */
    private function visibleLists(): array
    {
        return $this->visibleLists ??= $this->cursor->lists === [] ? [] : TaskList::query()
            ->whereIn('id', $this->cursor->lists)
            ->whereIn('project_id', $this->projectIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
