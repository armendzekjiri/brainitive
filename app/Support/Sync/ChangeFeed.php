<?php

namespace App\Support\Sync;

use App\Http\Resources\AppApi\TaskListDetailResource;
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
use App\Support\Sidebar\SidebarPreferences;
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
 *   lists the client keeps — ids and / or `mine`, see mineLists() — with `can`, and their tasks),
 *   `my_day` (today's plan, `MyDayPlan`), `captures` (the private inbox; all states or only the inbox).
 *   Every query is the user's own / limited to the projects they can open now
 *   (`User::accessibleProjectIds`, the web's rule), so nobody else's data can show up.
 * - **Runs:** a run walks fixed phases (upserts first, then deletions) with keyset pagination inside each.
 *   The first run has no `since`: a full copy (`reset`), the client drops what it didn't get. Every later
 *   run sends what changed since the previous run started (10 s overlap: a change can come twice, never
 *   not at all). My Day is one record, sent when its hash changed.
 * - **Task lists (v2, BRAI-441):** resolved at the start of every run; the diff against the last run's
 *   lists gives `fresh` ones (a full pass inside the delta run) and `gone` ones (a `task_list` delete:
 *   deleted, no longer visible, no longer asked for / no longer "mine"). A change of the abilities of the
 *   kept lists (roles) re-sends every kept list.
 * - **Deletions:** soft deletes (`deleted_at` of issues, tasks, and of the board / list they sit on), the
 *   dropped lists, captures that left the inbox (filter `inbox`), plus `sync_tombstones` (captures
 *   deleted for good, work reassigned to someone else). A cursor older than the tombstones' 30 days starts
 *   a full run. Lost access is not a row anywhere: the answer's `project_ids` lists what the user can open now.
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

    /** Lists of "mine": changes this recent count as working in a list. */
    public const RECENT_DAYS = 30;

    /** phase => the scope it belongs to (null = work and captures). Append only: v1 cursors stored the index. */
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

    /** @var list<string> */
    public const PHASE_NAMES = ['taskLists', 'listTasks', 'workIssues', 'workTasks', 'captures', 'myDay', 'goneWorkIssues', 'goneWorkTasks', 'goneListTasks', 'goneTaskLists', 'goneTombstones'];

    private const TIMESTAMP = '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?\z/';

    /** @var list<int> */
    private array $projectIds;

    /** @var list<int>|null */
    private ?array $visibleLists = null;

    /** @var array<int, array{update: bool, delete: bool, manage_items: bool}>|null project id => abilities on its lists */
    private ?array $abilities = null;

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
     * @param  list<int>|null  $lists  task list ids to keep, null = the cursor's (a change is additive)
     * @param  bool|null  $mine  also keep the lists the user works in, null = the cursor's
     * @param  string|null  $captures  all | inbox, null = the cursor's (a change starts a full run)
     * @return array{changes: list<SyncChange>, next_cursor: string, has_more: bool, reset: bool, project_ids: list<int>, task_lists: list<int>}
     */
    public static function page(User $user, ?string $cursor, ?array $scopes, ?array $lists, int $limit, ?bool $mine = null, ?string $captures = null): array
    {
        return (new self($user, self::start($user, $cursor, $scopes, $lists, $mine, $captures)))->run(max(1, $limit));
    }

    /**
     * @param  list<string>|null  $scopes
     * @param  list<int>|null  $lists
     */
    private static function start(User $user, ?string $cursor, ?array $scopes, ?array $lists, ?bool $mine, ?string $captures): SyncCursor
    {
        $current = $cursor !== null ? SyncCursor::decode($cursor, $user->id) : null;
        if ($current !== null) {
            $rescoped = ($scopes !== null && SyncCursor::sortedScopes($scopes) !== $current->scopes)
                || ($captures !== null && SyncCursor::captureFilter($captures) !== $current->captures);
            // tombstones of older changes are gone: only a full run is complete
            $expired = $current->since !== null && $current->since->lt(CarbonImmutable::now()->subDays(SyncTombstone::KEEP_DAYS));
            if (! $rescoped && ! $expired) {
                $relisted = ($lists !== null && SyncCursor::sortedLists($lists) !== $current->lists)
                    || ($mine !== null && $mine !== $current->mine);

                // other lists: only they get a full pass (or a delete), the other scopes go on
                return $relisted ? $current->withLists($lists ?? $current->lists, $mine ?? $current->mine) : $current;
            }
            $scopes ??= $current->scopes;
            $lists ??= $current->lists;
            $mine ??= $current->mine;
            $captures ??= $current->captures;
        }

        return SyncCursor::fresh($user->id, $scopes ?? self::SCOPES, $lists ?? [], $mine ?? false, $captures ?? SyncCursor::CAPTURES_ALL);
    }

    /** @return array{changes: list<SyncChange>, next_cursor: string, has_more: bool, reset: bool, project_ids: list<int>, task_lists: list<int>} */
    private function run(int $limit): array
    {
        if ($this->cursor->startedAt === null) {
            $this->begin();
        }
        $phases = self::PHASE_NAMES;
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
            'task_lists' => $this->in(self::TASK_LISTS) ? $this->visibleLists() : [],
        ];
    }

    /**
     * The start of a run: resolve the kept task lists, diff them against the last run's (new → a full
     * pass, dropped → a delete) and note whether their abilities changed (→ every kept list again).
     */
    private function begin(): void
    {
        $cursor = $this->cursor;
        $cursor->startedAt = CarbonImmutable::now('UTC');
        $lists = $this->in(self::TASK_LISTS) ? $this->resolveLists() : [];

        if ($cursor->since === null) {
            [$cursor->fresh, $cursor->gone] = [[], []];
        } else {
            $old = $cursor->kept;
            $cursor->fresh = SyncCursor::sortedLists(array_intersect([...$cursor->fresh, ...array_diff($lists, $old)], $lists));
            $cursor->gone = SyncCursor::sortedLists(array_diff([...$cursor->gone, ...array_diff($old, $lists)], $lists), 200);
        }
        $cursor->kept = $lists;
        $this->visibleLists = null;

        $hash = hash('sha256', (string) json_encode($this->abilities()));
        $cursor->allLists = $cursor->since !== null && $cursor->canHash !== $hash;
        $cursor->canHash = $hash;
    }

    /**
     * The lists this run keeps: the ids asked for, then (with `mine`) the ones the user works in — only
     * lists the user can open (not deleted), at most MAX_LISTS.
     *
     * @return list<int>
     */
    private function resolveLists(): array
    {
        $wanted = [...$this->cursor->lists, ...($this->cursor->mine ? $this->mineLists() : [])];
        if ($wanted === [] || $this->projectIds === []) {
            return [];
        }
        $visible = array_flip(TaskList::query()->whereIn('id', array_unique($wanted))->whereIn('project_id', $this->projectIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all());

        return SyncCursor::sortedLists(array_slice(array_values(array_unique(array_filter($wanted, fn (int $id) => isset($visible[$id])))), 0, self::MAX_LISTS));
    }

    /**
     * "Lists I work in" (`task_lists=mine`), most relevant first: lists pinned in the sidebar (every
     * project), lists with open tasks assigned to the user, lists where the user created or was assigned
     * a task that changed in the last RECENT_DAYS days, lists the user created in that time. Visibility is
     * checked by resolveLists().
     *
     * @return list<int>
     */
    private function mineLists(): array
    {
        $ids = [];
        $accessible = array_flip($this->projectIds);
        foreach (SidebarPreferences::normalize($this->user->sidebar_preferences)['projects'] as $project => $prefs) {
            if (! isset($accessible[(int) $project])) {
                continue;
            }
            foreach ($prefs['pins'] as $pin) {
                if ($pin['type'] === 'list') {
                    $ids[] = $pin['id'];
                }
            }
        }

        $since = CarbonImmutable::now()->subDays(self::RECENT_DAYS);
        $byLastChange = fn (Builder $tasks) => $tasks->reorder()
            ->select('task_list_items.task_list_id')
            ->groupBy('task_list_items.task_list_id')
            ->orderByRaw('max(task_list_items.updated_at) desc')
            ->orderBy('task_list_items.task_list_id')
            ->limit(self::MAX_LISTS)
            ->pluck('task_list_id')->map(fn ($id) => (int) $id)->all();

        array_push($ids, ...$byLastChange(AssignedWork::tasks($this->user, $this->projectIds, AssignedWork::OPEN)));
        array_push($ids, ...$byLastChange(TaskListItem::query()
            ->where(fn (Builder $q) => $q->where('task_list_items.created_by', $this->user->id)->orWhere('task_list_items.assignee_id', $this->user->id))
            ->where('task_list_items.updated_at', '>=', $since)
            ->whereIn('task_list_items.task_list_id', TaskList::query()->whereIn('project_id', $this->projectIds)->select('id'))));
        array_push($ids, ...TaskList::query()
            ->whereIn('project_id', $this->projectIds)
            ->where('created_by', $this->user->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->orderBy('id')
            ->limit(self::MAX_LISTS)
            ->pluck('id')->map(fn ($id) => (int) $id)->all());

        return array_values(array_unique($ids));
    }

    /**
     * What the user may do in the kept lists, per project (TaskListPolicy only looks at the project):
     * the task-list API's `can`.
     *
     * @return array<int, array{update: bool, delete: bool, manage_items: bool}>
     */
    private function abilities(): array
    {
        if ($this->abilities !== null) {
            return $this->abilities;
        }
        $abilities = [];
        TaskList::query()->with('project.workspace')->whereIn('id', $this->visibleLists())->orderBy('id')->get()
            ->unique('project_id')
            ->each(function (TaskList $list) use (&$abilities) {
                $abilities[(int) $list->project_id] = TaskListDetailResource::abilities($this->user, $list);
            });
        ksort($abilities);

        return $this->abilities = $abilities;
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
        // a delta run: what changed, the fresh lists — or every kept list when the abilities changed
        $since = $this->cursor->allLists ? null : $this->cursor->since;
        $abilities = $this->abilities();
        $query = TaskLists::withCounts(TaskList::query()->with('project.workspace')->whereIn('task_lists.id', $this->visibleLists()))
            // a list's counts follow its tasks: send it again when one of them changed
            ->when($since, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('task_lists.updated_at', '>=', $since)
                ->orWhereIn('task_lists.id', $this->cursor->fresh)
                ->orWhereExists(fn (QueryBuilder $tasks) => $tasks->selectRaw('1')->from('task_list_items')
                    ->whereColumn('task_list_items.task_list_id', 'task_lists.id')
                    ->where(fn (QueryBuilder $c) => $c->where('task_list_items.updated_at', '>=', $since)->orWhere('task_list_items.deleted_at', '>=', $since)))));

        return $this->upserts($query, null, 'task_lists.id', $need, fn (TaskList $list) => new SyncChange(
            'task_list', (string) $list->id, SyncChange::UPSERT, (int) $list->project_id, $list->updated_at, $list,
            $abilities[(int) $list->project_id] ?? TaskListDetailResource::abilities($this->user, $list),
        ));
    }

    /** @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null} */
    private function listTasks(int $need): array
    {
        $query = TaskListItem::query()
            ->with(['assignee', 'taskList.project.workspace'])
            ->whereIn('task_list_items.task_list_id', $this->visibleLists())
            // a fresh list (new in this delta run) sends all its tasks
            ->when($this->cursor->since, fn (Builder $q, $since) => $q->where(fn (Builder $w) => $w
                ->where('task_list_items.updated_at', '>=', $since)
                ->orWhereIn('task_list_items.task_list_id', $this->cursor->fresh)));

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

    /**
     * The user's captures — with the filter `inbox` only the inbox: a full run sends the inbox, a delta run
     * a delete for a capture that left it (processed, archived).
     *
     * @return array{0: list<SyncChange>, 1: bool, 2: list<int|string>|null}
     */
    private function captures(int $need): array
    {
        $inbox = $this->cursor->captures === SyncCursor::CAPTURES_INBOX;
        $query = Capture::query()
            ->where('captures.user_id', $this->user->id)
            ->when($this->cursor->since, fn (Builder $q, $since) => $q->where('captures.updated_at', '>=', $since))
            ->when($inbox && $this->cursor->since === null, fn (Builder $q) => $q->inState('inbox'));

        [$rows, $more, $key] = $this->fetch($query, 'captures.updated_at', 'captures.id', $need);
        $locations = CaptureConversion::locations($rows, $this->user);
        $changes = $rows->map(fn (Capture $capture) => $inbox && $capture->state() !== 'inbox'
            ? new SyncChange('capture', (string) $capture->uuid, SyncChange::DELETE)
            : new SyncChange('capture', (string) $capture->uuid, SyncChange::UPSERT, null, $capture->updated_at, $capture, $locations[$capture->id] ?? null),
        )->all();

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

    /**
     * Lists the client must drop: deleted, no longer visible, no longer asked for or no longer "mine"
     * (begin() diffs them). Their tasks go with them.
     *
     * @return array{0: list<SyncChange>, 1: bool, 2: list<int>|null}
     */
    private function goneTaskLists(int $need): array
    {
        $after = is_array($this->cursor->key) && count($this->cursor->key) === 1 ? (int) $this->cursor->key[0] : 0;
        $rest = array_values(array_filter($this->cursor->gone, fn (int $id) => $id > $after));
        $batch = array_slice($rest, 0, $need);
        $more = count($rest) > $need;

        return [
            array_map(fn (int $id) => new SyncChange('task_list', (string) $id, SyncChange::DELETE), $batch),
            $more,
            $more ? [(int) end($batch)] : null,
        ];
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
        return $this->visibleLists ??= $this->cursor->kept === [] ? [] : TaskList::query()
            ->whereIn('id', $this->cursor->kept)
            ->whereIn('project_id', $this->projectIds)
            ->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
