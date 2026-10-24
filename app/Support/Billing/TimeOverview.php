<?php

namespace App\Support\Billing;

use App\Models\BillingActivity;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The team time overview (BRAI-100, workspace admins): the filtered time entries of a workspace,
 * grouped for the month-end review and exported to Excel (TimeExport).
 *
 * One joined query (entries + project + client + live item + person + activity + report), streamed
 * with cursor() and reduced in PHP — a fixed number of queries whatever the number of entries.
 * The amounts are an indicative estimate (before rounding and VAT); billing reports compute the real
 * numbers (docs/ai/tasks/release-v1.38.0-billing.md).
 */
final class TimeOverview
{
    public const PERIODS = ['last_month', 'this_month', 'custom'];

    public const GROUPS = ['ticket', 'person', 'activity', 'day'];

    public const BILLABLE = ['all', 'billable', 'non_billable'];

    /** Entry rows shown in the page's detail view; the Excel export has no limit. */
    public const DETAIL_LIMIT = 2000;

    /** The longest custom period, in days. */
    public const MAX_DAYS = 366;

    /**
     * @param  list<int>  $projectIds
     * @param  int|'none'|null  $activity  an activity id, 'none' = entries without an activity
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $projectIds = [],
        public readonly ?int $clientId = null,
        public readonly ?int $userId = null,
        public readonly int|string|null $activity = null,
        public readonly string $billable = 'all',
        public readonly string $group = 'ticket',
        public readonly bool $detail = false,
    ) {}

    /**
     * Filters from the query string. Invalid values fall back to the defaults instead of failing:
     * this is a GET page with shareable URLs. Periods are dates in the viewer's own time zone.
     */
    public static function fromRequest(Request $request, Workspace $workspace): self
    {
        $user = $request->user();
        $today = CarbonImmutable::now($user instanceof User ? $user->localTimezone() : config('app.timezone'))->startOfDay();

        $period = in_array($request->query('period'), self::PERIODS, true) ? $request->query('period') : 'last_month';
        [$from, $to] = self::presetRange($period === 'custom' ? 'last_month' : $period, $today);
        if ($period === 'custom') {
            $customFrom = self::date($request->query('from'));
            $customTo = self::date($request->query('to'));
            if ($customFrom && $customTo) {
                [$from, $to] = $customFrom->lte($customTo) ? [$customFrom, $customTo] : [$customTo, $customFrom];
                if ($from->diffInDays($to) >= self::MAX_DAYS) {
                    $to = $from->addDays(self::MAX_DAYS - 1);
                }
            } else {
                $period = 'last_month';
            }
        }

        $projectIds = collect((array) $request->query('projects', []))
            ->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)->unique()->values()->take(200)->all();

        $activity = $request->query('activity');

        return new self(
            workspace: $workspace,
            period: $period,
            from: $from,
            to: $to,
            projectIds: $projectIds,
            clientId: self::id($request->query('client')),
            userId: self::id($request->query('person')),
            activity: $activity === 'none' ? 'none' : self::id($activity),
            billable: in_array($request->query('billable'), self::BILLABLE, true) ? $request->query('billable') : 'all',
            group: in_array($request->query('group'), self::GROUPS, true) ? $request->query('group') : 'ticket',
            detail: $request->boolean('detail'),
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function presetRange(string $preset, CarbonImmutable $today): array
    {
        $month = $preset === 'this_month' ? $today->startOfMonth() : $today->startOfMonth()->subMonthNoOverflow();

        return [$month, $month->endOfMonth()->startOfDay()];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function id(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    /** The filters as the page uses them. */
    public function filters(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'projects' => $this->projectIds,
            'client' => $this->clientId,
            'person' => $this->userId,
            'activity' => $this->activity,
            'billable' => $this->billable,
            'group' => $this->group,
            'detail' => $this->detail,
        ];
    }

    /** "time-2026-10.xlsx" for a whole calendar month, else "time-2026-10-01_2026-10-15.xlsx". */
    public function filename(): string
    {
        $wholeMonth = $this->from->day === 1 && $this->to->isSameDay($this->from->endOfMonth());

        return $wholeMonth
            ? 'time-'.$this->from->format('Y-m').'.xlsx'
            : 'time-'.$this->from->toDateString().'_'.$this->to->toDateString().'.xlsx';
    }

    /**
     * Every filtered entry with what the overview and the export show, oldest first. Items in the
     * trash count as gone: their entries fall back to the key / title snapshot taken at logging.
     * Project and client only join inside the entry's workspace and while not trashed: entries of a
     * project that was moved to another workspace or deleted stay (it is logged work), but show
     * nothing live from it (PROJECT_GONE, snapshot titles, no client).
     */
    public function query(): Builder
    {
        return DB::table('time_entries as te')
            ->leftJoin('projects as p', fn ($join) => $join->on('p.id', '=', 'te.project_id')->on('p.workspace_id', '=', 'te.workspace_id')->whereNull('p.deleted_at'))
            ->leftJoin('clients as c', fn ($join) => $join->on('c.id', '=', 'p.client_id')->on('c.workspace_id', '=', 'te.workspace_id'))
            ->leftJoin('items as i', fn ($join) => $join->on('i.id', '=', 'te.item_id')->whereNull('i.deleted_at'))
            ->leftJoin('users as u', 'u.id', '=', 'te.user_id')
            ->leftJoin('billing_activities as a', 'a.id', '=', 'te.billing_activity_id')
            ->leftJoin('billing_reports as r', 'r.id', '=', 'te.billing_report_id')
            ->where('te.workspace_id', $this->workspace->id)
            ->whereBetween('te.spent_on', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($this->projectIds, fn ($q) => $q->whereIn('te.project_id', $this->projectIds))
            ->when($this->clientId, fn ($q) => $q->where('p.client_id', $this->clientId))
            ->when($this->userId, fn ($q) => $q->where('te.user_id', $this->userId))
            ->when($this->activity === 'none', fn ($q) => $q->whereNull('te.billing_activity_id'))
            ->when(is_int($this->activity), fn ($q) => $q->where('te.billing_activity_id', $this->activity))
            ->when($this->billable !== 'all', fn ($q) => $q->where('te.billable', $this->billable === 'billable'))
            ->select([
                'te.id', 'te.spent_on', 'te.minutes', 'te.note', 'te.billable', 'te.project_id', 'te.item_id',
                'te.user_id', 'te.billing_activity_id', 'te.billing_report_id', 'te.item_key', 'te.item_title',
                'p.id as live_project_id', 'p.name as project_name', 'p.key as project_key', 'c.name as client_name',
                'i.key as live_key', 'i.title as live_title', 'u.name as user_name',
                'a.name as activity_name', 'a.pricing_type', 'a.rate_cents',
                'r.number as report_number',
            ])
            ->orderBy('te.spent_on')
            ->orderBy('te.id');
    }

    /** Label of entries whose project was deleted or moved to another workspace. */
    public const PROJECT_GONE = 'Deleted or moved project';

    /** "WEB-12" / "Checkout bug": the live item while it (and its project here) exists, else the snapshot. */
    public static function ticket(object $row): array
    {
        $live = $row->live_project_id !== null;

        return [
            'key' => ($live ? $row->live_key : null) ?? $row->item_key,
            'title' => ($live ? $row->live_title : null) ?? $row->item_title,
        ];
    }

    /** What the Excel "Billed" column shows: the report's number, or its id while it has none. */
    public static function billedLabel(object $row): ?string
    {
        if ($row->billing_report_id === null) {
            return null;
        }

        return $row->report_number ?: '#'.$row->billing_report_id;
    }

    /**
     * Groups + totals for the page.
     *
     * Group by `ticket`: project groups, each with one row per ticket ("No ticket" for entries
     * without one). Other modes: one flat group per person / activity / day. Every leaf (ticket row
     * or flat group) carries the entry detail when requested.
     *
     * Estimate: billable entries only; hourly = minutes / 60 × rate; a fixed price counts once per
     * project + ticket + activity, on the leaf of its earliest entry, so groups add up to the total.
     */
    public function summary(): array
    {
        $groups = [];
        $fixedSeen = [];
        $totals = ['minutes' => 0, 'billable_minutes' => 0, 'entries' => 0];
        $detailCount = 0;
        $truncated = false;

        foreach ($this->query()->cursor() as $row) {
            $minutes = (int) $row->minutes;
            $billable = (bool) $row->billable;
            $ticket = self::ticket($row);
            $ticketKey = $row->item_id ? 'i'.$row->item_id : ($ticket['key'] ? 'k'.$ticket['key'] : 'none');

            [$groupKey, $attributes] = $this->groupOf($row);
            $groups[$groupKey] ??= $attributes + self::emptyLeaf();
            self::count($totals, $minutes, $billable);
            self::count($groups[$groupKey], $minutes, $billable);

            if ($this->group === 'ticket') {
                $groups[$groupKey]['rows'][$ticketKey] ??= [
                    'key' => $ticketKey,
                    'item_id' => $row->item_id,
                    'ticket_key' => $ticket['key'],
                    'label' => $ticket['title'] ?? ($ticket['key'] ? null : 'No ticket'),
                ] + self::emptyLeaf();
                $leaf = &$groups[$groupKey]['rows'][$ticketKey];
                self::count($leaf, $minutes, $billable);
            } else {
                $leaf = &$groups[$groupKey];
            }

            if ($billable && $row->billing_activity_id) {
                $leaf['priced'] = true;
                if ($row->pricing_type === BillingActivity::FIXED) {
                    $fixedKey = $row->project_id.'|'.$ticketKey.'|'.$row->billing_activity_id;
                    if (! isset($fixedSeen[$fixedKey])) {
                        $fixedSeen[$fixedKey] = true;
                        $leaf['fixed_cents'] += (int) $row->rate_cents;
                    }
                } else {
                    $leaf['minute_cents'] += $minutes * (int) $row->rate_cents;
                }
            }

            if ($this->detail) {
                if ($detailCount < self::DETAIL_LIMIT) {
                    $leaf['detail'][] = $this->detailRow($row, $ticket);
                    $detailCount++;
                } else {
                    $truncated = true;
                }
            }
            unset($leaf);
        }

        $amount = 0;
        $priced = false;
        foreach ($groups as &$group) {
            if ($this->group === 'ticket') {
                $group['amount_cents'] = 0;
                foreach ($group['rows'] as &$ticketRow) {
                    self::finish($ticketRow);
                    $group['amount_cents'] += $ticketRow['amount_cents'];
                    $group['priced'] = $group['priced'] || $ticketRow['priced'];
                }
                unset($ticketRow);
                $rows = array_values($group['rows']);
                usort($rows, fn ($a, $b) => [$b['minutes'], (string) $a['ticket_key']] <=> [$a['minutes'], (string) $b['ticket_key']]);
                $group['rows'] = $rows;
                unset($group['detail'], $group['minute_cents'], $group['fixed_cents']);
            } else {
                self::finish($group);
            }
            $amount += $group['amount_cents'];
            $priced = $priced || $group['priced'];
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return [
            'groups' => array_map(fn (array $group) => array_diff_key($group, ['sort' => true]), $groups),
            'totals' => $totals + [
                'non_billable_minutes' => $totals['minutes'] - $totals['billable_minutes'],
                'amount_cents' => $amount,
                'priced' => $priced,
            ],
            'detail_truncated' => $truncated,
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function groupOf(object $row): array
    {
        return match ($this->group) {
            'person' => ['u'.($row->user_id ?? 0), [
                'key' => 'u'.($row->user_id ?? 0),
                'label' => $row->user_name ?? 'Deleted user',
                'meta' => null,
                'sort' => [$row->user_name === null ? 1 : 0, mb_strtolower((string) $row->user_name)],
            ]],
            'activity' => ['a'.($row->billing_activity_id ?? 0), [
                'key' => 'a'.($row->billing_activity_id ?? 0),
                'label' => $row->activity_name ?? 'No activity',
                'meta' => $row->pricing_type,
                'sort' => [$row->activity_name === null ? 1 : 0, mb_strtolower((string) $row->activity_name)],
            ]],
            'day' => ['d'.$row->spent_on, [
                'key' => 'd'.$row->spent_on,
                'label' => (string) $row->spent_on,
                'meta' => null,
                'sort' => [0, (string) $row->spent_on],
            ]],
            default => ['p'.$row->project_id, [
                'key' => 'p'.$row->project_id,
                'label' => $row->project_name ?? self::PROJECT_GONE,
                'meta' => $row->project_key,
                'client' => $row->client_name,
                'sort' => [$row->project_name === null ? 1 : 0, mb_strtolower((string) $row->project_name)],
                'rows' => [],
            ]],
        };
    }

    private static function emptyLeaf(): array
    {
        return [
            'minutes' => 0, 'billable_minutes' => 0, 'entries' => 0, 'priced' => false,
            'minute_cents' => 0, 'fixed_cents' => 0, 'amount_cents' => 0, 'detail' => [],
        ];
    }

    private static function count(array &$bucket, int $minutes, bool $billable): void
    {
        $bucket['minutes'] += $minutes;
        $bucket['billable_minutes'] += $billable ? $minutes : 0;
        $bucket['entries']++;
    }

    private static function finish(array &$leaf): void
    {
        // Σ minutes × rate cents / 60, rounded half up once per leaf, plus the fixed prices
        $leaf['amount_cents'] = (int) round($leaf['minute_cents'] / 60) + $leaf['fixed_cents'];
        unset($leaf['minute_cents'], $leaf['fixed_cents']);
    }

    private function detailRow(object $row, array $ticket): array
    {
        return [
            'id' => (int) $row->id,
            'date' => (string) $row->spent_on,
            'person' => $row->user_name,
            'project_key' => $row->project_key,
            'ticket_key' => $ticket['key'],
            'ticket_title' => $ticket['title'],
            'activity' => $row->activity_name,
            'note' => $row->note,
            'minutes' => (int) $row->minutes,
            'billable' => (bool) $row->billable,
            'locked' => $row->billing_report_id !== null,
            'report' => self::billedLabel($row),
        ];
    }

    /** What the filter controls offer: a handful of small queries, independent of the entries. */
    public function options(): array
    {
        return [
            'projects' => $this->workspace->projects()->orderBy('name')->get(['id', 'name', 'key', 'client_id'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'key' => $p->key, 'client_id' => $p->client_id])->values(),
            'clients' => Client::where('workspace_id', $this->workspace->id)->orderBy('name')->get(['id', 'name', 'archived_at'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name, 'archived' => $c->archived_at !== null])->values(),
            'people' => $this->workspace->users()->orderBy('name')->get(['users.id', 'users.name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'activities' => BillingActivity::where('workspace_id', $this->workspace->id)->orderBy('position')->orderBy('name')
                ->get(['id', 'name', 'pricing_type', 'archived_at'])
                ->map(fn (BillingActivity $a) => ['id' => $a->id, 'name' => $a->name, 'pricing_type' => $a->pricing_type, 'archived' => $a->archived_at !== null])->values(),
        ];
    }
}
