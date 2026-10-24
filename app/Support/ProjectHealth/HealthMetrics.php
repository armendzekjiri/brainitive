<?php

namespace App\Support\ProjectHealth;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic project health metrics (BRAI-64), computed live for one viewer.
 *
 * Definitions (also shown in the UI):
 * - issue = a non-deleted issue or subtask on a non-deleted board of the project;
 * - completed = `completed_at` set or in a done column; open = not completed;
 * - today = the date in the chosen time zone; overdue = open with due date before today
 *   (due today is not overdue); due soon = open, due today … today + 6;
 * - blocked = open issues with a directional blocking link (`blocks` / `is_blocked_by`) whose
 *   blocker is open, not deleted and in a project the viewer can open — each pair once;
 * - plans: milestones are listed apart; rows linked to a live issue of the project take the
 *   issue's state and never count again.
 *
 * Missing data is `null` with a reason in `unknown`, never a healthy-looking 0. A fixed
 * number of queries, whatever the project size (no caching: every number is for this viewer).
 */
class HealthMetrics
{
    public const LIST_LIMIT = 20;

    public const DUE_SOON_DAYS = 7;

    public const MILESTONE_DAYS = 30;

    /** Metric keys a report may cite as `metric:<key>`, with their labels. */
    public const LABELS = [
        'open' => 'Open issues',
        'completed' => 'Completed issues',
        'completed_in_period' => 'Completed in period',
        'created_in_period' => 'Created in period',
        'overdue' => 'Overdue',
        'due_soon' => 'Due in the next 7 days',
        'no_due_date' => 'Open without due date',
        'unassigned' => 'Unassigned open issues',
        'blocked' => 'Blocked issues',
        'milestones_upcoming' => 'Upcoming milestones (30 days)',
        'milestones_missed' => 'Missed milestones',
        'plan_rows_open' => 'Open plan rows not on a board',
        'plan_rows_late' => 'Late plan rows not on a board',
    ];

    private const ISSUE_KEYS = ['open', 'completed', 'completed_in_period', 'created_in_period', 'overdue', 'due_soon', 'no_due_date', 'unassigned', 'blocked'];

    private const PLAN_KEYS = ['milestones_upcoming', 'milestones_missed', 'plan_rows_open', 'plan_rows_late'];

    /** @var array<int, array{slug: string}> */
    private array $boards = [];

    /** @var list<int> */
    private array $doneColumns = [];

    private string $today;

    public function __construct(
        private readonly Project $project,
        private readonly User $viewer,
        private readonly string $timezone,
        private readonly string $from,
        private readonly string $to,
        private readonly CarbonImmutable $now,
    ) {
        $this->today = $now->setTimezone($timezone)->toDateString();
    }

    public static function for(Project $project, User $viewer, string $timezone, string $from, string $to, ?CarbonImmutable $now = null): self
    {
        return new self($project, $viewer, $timezone, $from, $to, $now ?? CarbonImmutable::now('UTC'));
    }

    /**
     * @return array{as_of: string, timezone: string, today: string, period: array{from: string, to: string}, values: array<string, int|null>, unknown: array<string, string>, lists: array<string, list<array<string, mixed>>>}
     */
    public function compute(): array
    {
        $this->project->loadMissing('workspace:id,slug');
        $this->boards = Board::where('project_id', $this->project->id)->pluck('slug', 'id')
            ->map(fn ($slug) => ['slug' => (string) $slug])->all();

        $values = array_fill_keys(array_keys(self::LABELS), null);
        $unknown = [];
        $lists = ['overdue' => [], 'due_soon' => [], 'unassigned' => [], 'completed' => [], 'blocked' => [], 'milestones' => []];

        if ($this->boards === []) {
            foreach (self::ISSUE_KEYS as $key) {
                $unknown[$key] = 'This project has no boards.';
            }
        } else {
            $this->doneColumns = BoardColumn::whereIn('board_id', array_keys($this->boards))->where('is_done_column', true)->pluck('id')->all();
            $values = array_replace($values, $this->counts());

            $openWithDue = $values['open'] - $values['no_due_date'];
            if ($values['open'] > 0 && $openWithDue === 0) {
                $values['overdue'] = $values['due_soon'] = null;
                $unknown['overdue'] = $unknown['due_soon'] = 'No open issue has a due date.';
            }

            $lists['overdue'] = $values['overdue'] ? $this->list(fn ($q) => $q->where('items.due_date', '<', $this->today)->orderBy('items.due_date')) : [];
            $lists['due_soon'] = $values['due_soon'] ? $this->list(fn ($q) => $q->whereBetween('items.due_date', [$this->today, $this->dueSoonEnd()])->orderBy('items.due_date')) : [];
            $lists['unassigned'] = $values['unassigned'] ? $this->list(fn ($q) => $q->whereNull('items.assigned_to')->orderByRaw('items.due_date is null')->orderBy('items.due_date')) : [];
            $lists['completed'] = $values['completed_in_period'] ? $this->list(fn ($q) => $q->whereNotNull('items.completed_at')
                ->where('items.completed_at', '>=', $this->periodStartUtc())->where('items.completed_at', '<', $this->periodEndUtc())
                ->orderByDesc('items.completed_at'), open: false) : [];

            [$values['blocked'], $lists['blocked']] = $this->blocked();
        }

        [$planValues, $lists['milestones'], $planUnknown] = $this->plans();
        $values = array_replace($values, $planValues);
        $unknown += $planUnknown;

        return [
            'as_of' => $this->now->utc()->toIso8601String(),
            'timezone' => $this->timezone,
            'today' => $this->today,
            'period' => ['from' => $this->from, 'to' => $this->to],
            'values' => $values,
            'unknown' => $unknown,
            'lists' => $lists,
        ];
    }

    /**
     * One aggregate query for every issue count.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        [$done, $doneBindings] = $this->doneSql();
        [$open, $openBindings] = $this->openSql();

        $row = $this->issues()->selectRaw(implode(', ', [
            'count(*) as total',
            "sum(case when {$done} then 1 else 0 end) as completed",
            'sum(case when items.completed_at >= ? and items.completed_at < ? then 1 else 0 end) as completed_in_period',
            'sum(case when items.created_at >= ? and items.created_at < ? then 1 else 0 end) as created_in_period',
            "sum(case when {$open} and items.due_date < ? then 1 else 0 end) as overdue",
            "sum(case when {$open} and items.due_date >= ? and items.due_date <= ? then 1 else 0 end) as due_soon",
            "sum(case when {$open} and items.due_date is null then 1 else 0 end) as no_due_date",
            "sum(case when {$open} and items.assigned_to is null then 1 else 0 end) as unassigned",
        ]), [
            ...$doneBindings,
            $this->periodStartUtc(), $this->periodEndUtc(),
            $this->periodStartUtc(), $this->periodEndUtc(),
            ...$openBindings, $this->today,
            ...$openBindings, $this->today, $this->dueSoonEnd(),
            ...$openBindings,
            ...$openBindings,
        ])->toBase()->first();

        $total = (int) ($row->total ?? 0);
        $completed = (int) ($row->completed ?? 0);

        return [
            'open' => $total - $completed,
            'completed' => $completed,
            'completed_in_period' => (int) ($row->completed_in_period ?? 0),
            'created_in_period' => (int) ($row->created_in_period ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'due_soon' => (int) ($row->due_soon ?? 0),
            'no_due_date' => (int) ($row->no_due_date ?? 0),
            'unassigned' => (int) ($row->unassigned ?? 0),
        ];
    }

    /**
     * Up to LIST_LIMIT issues of this project, with what a row needs (one query).
     *
     * @return list<array<string, mixed>>
     */
    private function list(callable $scope, bool $open = true): array
    {
        $query = $this->issues()
            ->leftJoin('users', 'users.id', '=', 'items.assigned_to')
            ->select(['items.id', 'items.key', 'items.title', 'items.board_id', 'items.due_date', 'items.completed_at', 'users.name as assignee_name']);
        if ($open) {
            [$sql, $bindings] = $this->openSql();
            $query->whereRaw($sql, $bindings);
        }
        $scope($query);

        return $query->orderBy('items.id')->limit(self::LIST_LIMIT)->toBase()->get()
            ->map(fn (object $row) => $this->itemRef($row, $this->project->workspace->slug, $this->project->slug, $this->boards[$row->board_id]['slug'] ?? ''))
            ->all();
    }

    /**
     * Open issues of this project blocked by an open issue the viewer can open.
     *
     * @return array{0: int, 1: list<array{item: array<string, mixed>, blockers: list<array<string, mixed>>}>}
     */
    private function blocked(): array
    {
        $projectIssues = $this->issues()->select('items.id');
        $links = ItemLink::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('link_type', 'blocks')->whereIn('target_item_id', $projectIssues))
                ->orWhere(fn ($q) => $q->where('link_type', 'is_blocked_by')->whereIn('source_item_id', (clone $projectIssues))))
            ->get(['source_item_id', 'target_item_id', 'link_type']);
        if ($links->isEmpty()) {
            return [0, []];
        }

        // blocked id => blocker ids (a pair stored both ways counts once)
        $pairs = [];
        foreach ($links as $link) {
            [$blocked, $blocker] = $link->link_type === 'blocks'
                ? [$link->target_item_id, $link->source_item_id]
                : [$link->source_item_id, $link->target_item_id];
            $pairs[$blocked][$blocker] = true;
        }

        $ids = array_unique(array_merge(array_keys($pairs), ...array_map('array_keys', array_values($pairs))));
        $rows = DB::table('items')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->join('projects', 'projects.id', '=', 'boards.project_id')
            ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
            ->leftJoin('board_columns', 'board_columns.id', '=', 'items.column_id')
            ->leftJoin('users', 'users.id', '=', 'items.assigned_to')
            ->whereIn('items.id', $ids)
            ->whereNull('items.deleted_at')->whereNull('boards.deleted_at')->whereNull('projects.deleted_at')->whereNull('workspaces.deleted_at')
            ->get([
                'items.id', 'items.key', 'items.title', 'items.board_id', 'items.due_date', 'items.completed_at', 'users.name as assignee_name',
                'board_columns.is_done_column', 'boards.slug as board_slug', 'boards.project_id', 'projects.slug as project_slug', 'workspaces.slug as workspace_slug',
            ])
            ->keyBy('id');

        $isOpen = fn (object $row) => $row->completed_at === null && ! $row->is_done_column;
        $accessible = null;
        $canOpen = function (object $row) use (&$accessible) {
            if ((int) $row->project_id === $this->project->id) {
                return true;
            }
            $accessible ??= array_flip($this->viewer->accessibleProjectIds());

            return isset($accessible[(int) $row->project_id]);
        };

        $blocked = [];
        foreach ($pairs as $blockedId => $blockers) {
            $row = $rows->get($blockedId);
            if (! $row || (int) $row->project_id !== $this->project->id || ! $isOpen($row)) {
                continue;
            }
            $open = [];
            foreach (array_keys($blockers) as $blockerId) {
                $blocker = $rows->get($blockerId);
                if ($blocker && $isOpen($blocker) && $canOpen($blocker)) {
                    $open[] = $this->itemRef($blocker, $blocker->workspace_slug, $blocker->project_slug, $blocker->board_slug)
                        + ['external' => (int) $blocker->project_id !== $this->project->id];
                }
            }
            if ($open !== []) {
                $blocked[] = ['item' => $this->itemRef($row, $row->workspace_slug, $row->project_slug, $row->board_slug), 'blockers' => $open];
            }
        }

        usort($blocked, fn ($a, $b) => [$a['item']['due_date'] === null, $a['item']['due_date'], $a['item']['id']] <=> [$b['item']['due_date'] === null, $b['item']['due_date'], $b['item']['id']]);

        return [count($blocked), array_slice($blocked, 0, self::LIST_LIMIT)];
    }

    /**
     * Milestones (listed apart) and plan rows that aren't board issues.
     *
     * @return array{0: array<string, int|null>, 1: list<array<string, mixed>>, 2: array<string, string>}
     */
    private function plans(): array
    {
        $plans = Plan::where('project_id', $this->project->id)->orderBy('position')->orderBy('id')->get(['id', 'slug', 'name', 'data']);
        if ($plans->isEmpty()) {
            $reason = 'This project has no plans.';

            return [array_fill_keys(self::PLAN_KEYS, null), [], array_fill_keys(self::PLAN_KEYS, $reason)];
        }

        // linked rows take the issue's state — only live issues of this project count as linked
        $linkedIds = $plans->flatMap(fn (Plan $plan) => array_filter(array_map(fn ($row) => $row['itemId'] ?? null, $plan->data['rows'] ?? [])))->unique()->values()->all();
        $linked = [];
        if ($linkedIds !== [] && $this->boards !== []) {
            [$done, $bindings] = $this->doneSql();
            $linked = $this->issues()->whereIn('items.id', $linkedIds)
                ->selectRaw("items.id, items.key, items.title, case when {$done} then 1 else 0 end as is_done", $bindings)
                ->toBase()->get()->keyBy('id')->all();
        }

        $soonEnd = CarbonImmutable::parse($this->today)->addDays(self::MILESTONE_DAYS - 1)->toDateString();
        $values = ['milestones_upcoming' => 0, 'milestones_missed' => 0, 'plan_rows_open' => 0, 'plan_rows_late' => 0];
        $milestones = [];

        foreach ($plans as $plan) {
            $url = route('plans.show', [$this->project->workspace->slug, $this->project->slug, $plan->slug]);
            foreach ($plan->data['rows'] ?? [] as $row) {
                $item = isset($row['itemId']) ? ($linked[$row['itemId']] ?? null) : null;
                $rowDone = $item ? (bool) $item->is_done : ($row['status'] ?? null) === 'done';
                $bars = $row['bars'] ?? [];

                $taskBars = array_filter($bars, fn ($bar) => ($bar['type'] ?? 'bar') === 'bar');
                if (! $item && $taskBars !== [] && ! $rowDone) {
                    $values['plan_rows_open']++;
                    if (max(array_map(fn ($bar) => $bar['end'], $taskBars)) < $this->today) {
                        $values['plan_rows_late']++;
                    }
                }

                foreach ($bars as $bar) {
                    if (($bar['type'] ?? null) !== 'milestone' || $rowDone) {
                        continue;
                    }
                    $date = $bar['start'];
                    $state = $date < $this->today ? 'missed' : ($date <= $soonEnd ? 'upcoming' : null);
                    if ($state === null) {
                        continue;
                    }
                    $values[$state === 'missed' ? 'milestones_missed' : 'milestones_upcoming']++;
                    $milestones[] = [
                        'ref' => 'plan:'.$plan->id,
                        'plan_id' => $plan->id,
                        'plan' => $plan->name,
                        'bar' => (string) $bar['id'],
                        'name' => $item ? $item->key.' '.$item->title : self::milestoneName($bar, $row),
                        'date' => $date,
                        'state' => $state,
                        'item_ref' => $item ? 'item:'.$item->id : null,
                        'url' => $url,
                    ];
                }
            }
        }

        usort($milestones, fn ($a, $b) => [$a['date'], $a['plan_id']] <=> [$b['date'], $b['plan_id']]);

        return [$values, array_slice($milestones, 0, self::LIST_LIMIT), []];
    }

    /**
     * @param  array<string, mixed>  $bar
     * @param  array<string, mixed>  $row
     */
    public static function milestoneName(array $bar, array $row): string
    {
        foreach ([$bar['label'] ?? null, $row['name'] ?? null] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return 'Milestone';
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRef(object $row, string $workspaceSlug, string $projectSlug, string $boardSlug): array
    {
        return [
            'ref' => 'item:'.$row->id,
            'id' => (int) $row->id,
            'key' => (string) $row->key,
            'title' => (string) $row->title,
            'due_date' => $row->due_date ? substr((string) $row->due_date, 0, 10) : null,
            'assignee' => $row->assignee_name ?? null,
            'url' => route('boards.items.show', [$workspaceSlug, $projectSlug, $boardSlug, $row->id]),
        ];
    }

    /** Issues of the project: not deleted, on a live board. */
    private function issues()
    {
        return Item::query()->whereIn('items.board_id', array_keys($this->boards));
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function doneSql(): array
    {
        if ($this->doneColumns === []) {
            return ['items.completed_at is not null', []];
        }

        return ['(items.completed_at is not null or items.column_id in ('.implode(',', array_fill(0, count($this->doneColumns), '?')).'))', $this->doneColumns];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function openSql(): array
    {
        if ($this->doneColumns === []) {
            return ['items.completed_at is null', []];
        }

        return ['(items.completed_at is null and (items.column_id is null or items.column_id not in ('.implode(',', array_fill(0, count($this->doneColumns), '?')).')))', $this->doneColumns];
    }

    private function dueSoonEnd(): string
    {
        return CarbonImmutable::parse($this->today)->addDays(self::DUE_SOON_DAYS - 1)->toDateString();
    }

    private function periodStartUtc(): string
    {
        return CarbonImmutable::parse($this->from, $this->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    private function periodEndUtc(): string
    {
        return CarbonImmutable::parse($this->to, $this->timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
    }
}
