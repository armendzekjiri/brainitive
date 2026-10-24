<?php

namespace App\Support\Billing;

use App\Contracts\Billing\BuildsReportData;
use App\Models\BillingActivity;
use App\Models\BillingFixedCharge;
use App\Models\BillingReport;
use App\Models\BillingSetting;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Billing report data, snapshot v1 (v1.38.0, BRAI-101). Contract and money rules:
 * docs/ai/tasks/release-v1.38.0-billing.md. In short:
 * - Draft: the **unbilled** entries of the client's projects (options.project_ids, null = all of
 *   them) in the period, minus options.excluded_entry_ids. Finalized: the entries locked to it.
 * - Per entry, billed minutes = rounded **up** to rounding_minutes (0 = exact).
 * - Lines per project: ticket + activity (group_by ticket), activity, or person + activity.
 *   Fixed-price entries always make one line per ticket + activity, because the price is per ticket.
 * - Hourly amount = round_half_up(billed_minutes × rate / 60), per line. Fixed = the flat price once
 *   per ticket + activity (billing_fixed_charges); charged by another report → fixed_already_billed,
 *   0. Without a ticket: once per report and project. No activity: "No activity", rate 0.
 * - Non-billable entries only appear in `entries` (billed 0), never in lines or totals.
 * - Totals in net or gross mode; tax rate = client override ?? workspace default.
 * A fixed number of queries, whatever the number of entries.
 */
class ReportBuilder implements BuildsReportData
{
    public const NO_ACTIVITY = 'No activity';

    public const NO_TICKET = 'No ticket';

    public const FORMER_MEMBER = 'Former member';

    public function build(BillingReport $report): array
    {
        return $this->compute($report)->snapshot;
    }

    /**
     * @param  Collection<int, TimeEntry>|null  $entries  the report's entries, already selected (the finalizer's locked rows)
     * @param  string|null  $issuedOn  Y-m-d; default: the finalize date, or today for a draft
     */
    public function compute(BillingReport $report, ?Collection $entries = null, ?BillingSetting $setting = null, ?string $issuedOn = null): ReportComputation
    {
        $setting ??= BillingSetting::forWorkspace($report->workspace);
        $options = self::options($report->options);
        $excluded = collect();
        $projects = null;

        if ($entries === null && $report->isDraft()) {
            $projects = $this->projects($report, $options);
            $entries = $this->draftScope($report, $projects->keys()->all())->whereNull('billing_report_id')
                ->orderBy('spent_on')->orderBy('id')->get();
            [$excluded, $entries] = $entries->partition(fn (TimeEntry $e) => in_array($e->id, $options['excluded_entry_ids'], true))->all();
            $excluded = $excluded->values();
            $entries = $entries->values();
        } elseif ($entries === null) {
            $entries = TimeEntry::where('billing_report_id', $report->id)->orderBy('spent_on')->orderBy('id')->get();
        }

        $entries->concat($excluded)->load(['user:id,name', 'activity:id,name,pricing_type,rate_cents', 'item:id,key,title']);
        $projects ??= Project::withTrashed()->whereIn('id', $entries->pluck('project_id')->unique()->values())->get(['id', 'name', 'key'])->keyBy('id');

        $taxRate = (int) ($report->client?->tax_rate_bp ?? $setting->default_tax_rate_bp ?? 0);
        $rounding = (int) ($setting->rounding_minutes ?? 0);
        $showPeople = $options['show_people'] || $options['group_by'] === 'person';
        $charged = $this->chargedElsewhere($report, $entries);

        $groups = [];
        $rows = [];
        $snapshotEntries = [];
        $withoutActivity = 0;
        $nonBillable = 0;

        foreach ($entries as $entry) {
            $billable = (bool) $entry->billable;
            $billed = $billable ? self::roundUp($entry->minutes, $rounding) : 0;
            $person = $entry->user?->name ?? self::FORMER_MEMBER;
            $activity = $entry->activity;
            [$itemKey, $itemTitle] = $this->ticket($entry);
            $project = $projects->get($entry->project_id);

            $row = [
                'id' => $entry->id,
                'date' => $entry->spent_on->format('Y-m-d'),
                'person' => $person,
                'project_key' => $project?->key,
                'item_key' => $itemKey,
                'item_title' => $itemTitle,
                'activity' => $activity?->name ?? self::NO_ACTIVITY,
                'minutes' => $entry->minutes,
                'billed_minutes' => $billed,
                'billable' => $billable,
                'note' => $entry->note,
            ];
            $rows[] = $row;
            $snapshotEntries[] = array_merge($row, [
                'person' => $showPeople ? $person : null,
                'note' => $options['show_notes'] ? $entry->note : null,
            ]);

            if (! $billable) {
                $nonBillable++;

                continue;
            }
            if (! $activity) {
                $withoutActivity++;
            }

            $fixed = $activity?->isFixed() ?? false;
            $key = match (true) {
                $fixed => "f|{$activity->id}|".($entry->item_id ?? '-'),
                $options['group_by'] === 'activity' => 'h|'.($activity?->id ?? '-'),
                $options['group_by'] === 'person' => 'h|'.($entry->user_id ?? '-').'|'.($activity?->id ?? '-'),
                default => 'h|'.($entry->item_id ?? '-').'|'.($activity?->id ?? '-'),
            };
            $perTicket = $fixed || $options['group_by'] === 'ticket';

            $groups[$entry->project_id] ??= ['project' => $project, 'lines' => []];
            $groups[$entry->project_id]['lines'][$key] ??= [
                'fixed' => $fixed,
                'item_id' => $entry->item_id,
                'item_key' => $perTicket ? $itemKey : null,
                'item_title' => $perTicket ? ($itemTitle ?? self::NO_TICKET) : null,
                'activity' => $activity,
                'person_sort' => $options['group_by'] === 'person' && ! $fixed ? $person : null,
                'people' => [],
                'minutes' => 0,
                'billed_minutes' => 0,
                'notes' => [],
                'entry_ids' => [],
            ];
            $line = &$groups[$entry->project_id]['lines'][$key];
            $line['people'][$person] = true;
            $line['minutes'] += $entry->minutes;
            $line['billed_minutes'] += $billed;
            $line['entry_ids'][] = $entry->id;
            $note = trim((string) $entry->note);
            if ($options['show_notes'] && $note !== '') {
                $line['notes'][$note] = true;
            }
            unset($line);
        }

        $charges = [];
        $snapshotGroups = [];
        $totalMinutes = 0;
        $totalBilled = 0;
        $sum = 0;
        foreach ($groups as $projectId => $group) {
            $lines = [];
            foreach ($group['lines'] as $line) {
                $activity = $line['activity'];
                $rate = (int) ($activity?->rate_cents ?? 0);
                $alreadyBilled = false;
                if ($line['fixed']) {
                    $alreadyBilled = $line['item_id'] !== null && isset($charged[$line['item_id'].'|'.$activity->id]);
                    $amount = $alreadyBilled ? 0 : $rate;
                    if (! $alreadyBilled) {
                        $charges[] = ['project_id' => $projectId, 'item_id' => $line['item_id'], 'billing_activity_id' => $activity->id, 'amount_cents' => $rate];
                    }
                } else {
                    $amount = self::divHalfUp($line['billed_minutes'] * $rate, 60);
                }
                $people = array_keys($line['people']);
                sort($people, SORT_NATURAL | SORT_FLAG_CASE);
                sort($line['entry_ids']);

                $lines[] = [
                    'sort' => $this->lineSort($line, $options['group_by']),
                    'line' => [
                        'item_key' => $line['item_key'],
                        'item_title' => $line['item_title'],
                        'activity' => ['name' => $activity?->name ?? self::NO_ACTIVITY, 'pricing_type' => $activity?->pricing_type ?? BillingActivity::HOURLY],
                        'person' => $showPeople ? ($line['person_sort'] ?? implode(', ', $people)) : null,
                        'minutes' => $line['minutes'],
                        'billed_minutes' => $line['billed_minutes'],
                        'rate_cents' => $rate,
                        'amount_cents' => $amount,
                        'fixed_already_billed' => $alreadyBilled,
                        'notes' => array_map('strval', array_keys($line['notes'])),
                        'entry_ids' => $line['entry_ids'],
                    ],
                ];
            }
            usort($lines, fn ($a, $b) => self::compareKeys($a['sort'], $b['sort']));
            $lines = array_column($lines, 'line');

            $minutes = array_sum(array_column($lines, 'minutes'));
            $billed = array_sum(array_column($lines, 'billed_minutes'));
            $net = array_sum(array_column($lines, 'amount_cents'));
            $totalMinutes += $minutes;
            $totalBilled += $billed;
            $sum += $net;
            $project = $group['project'];
            $snapshotGroups[] = [
                'project' => ['id' => (int) $projectId, 'name' => (string) $project?->name, 'key' => $project?->key],
                'lines' => $lines,
                'minutes' => $minutes,
                'billed_minutes' => $billed,
                'net_cents' => $net,
            ];
        }
        usort($snapshotGroups, fn ($a, $b) => self::compareKeys([$a['project']['name'], $a['project']['id']], [$b['project']['name'], $b['project']['id']]));
        usort($snapshotEntries, fn ($a, $b) => self::compareKeys([$a['date'], (string) $a['person'], $a['id']], [$b['date'], (string) $b['person'], $b['id']]));
        usort($rows, fn ($a, $b) => self::compareKeys([$a['date'], $a['person'], $a['id']], [$b['date'], $b['person'], $b['id']]));

        $includeTax = (bool) $setting->prices_include_tax;
        [$net, $tax, $gross] = self::totals($sum, $taxRate, $includeTax);
        $client = $report->client;
        $kind = $report->isDraft() ? ($setting->invoice_mode ? 'invoice' : 'report') : $report->kind;

        $snapshot = [
            'version' => 1,
            'kind' => $kind,
            'number' => $report->number,
            'title' => $report->title ?: self::defaultTitle($kind, $report->period_from, $report->period_to),
            'issued_on' => $issuedOn ?? ($report->finalized_at ?? now())->format('Y-m-d'),
            'period' => ['from' => $report->period_from->format('Y-m-d'), 'to' => $report->period_to->format('Y-m-d')],
            'currency' => $setting->currency ?: 'EUR',
            'tax_rate_bp' => $taxRate,
            'prices_include_tax' => $includeTax,
            'rounding_minutes' => $rounding,
            'issuer' => [
                'company_name' => (string) $setting->company_name,
                'address' => (string) $setting->company_address,
                'vat_id' => (string) $setting->company_vat_id,
                'email' => (string) $setting->company_email,
                'phone' => (string) $setting->company_phone,
                'bank_name' => (string) $setting->bank_name,
                'iban' => (string) $setting->iban,
                'bic' => (string) $setting->bic,
                'footer' => (string) $setting->footer,
                'logo_path' => $setting->logo_path ?: null,
                'payment_terms_days' => (int) ($setting->payment_terms_days ?? 14),
            ],
            'client' => [
                'name' => (string) $client?->name,
                'email' => (string) $client?->email,
                'address' => (string) $client?->address,
                'vat_id' => (string) $client?->vat_id,
            ],
            'options' => ['group_by' => $options['group_by'], 'show_people' => $options['show_people'], 'show_notes' => $options['show_notes']],
            'groups' => $snapshotGroups,
            'totals' => ['minutes' => $totalMinutes, 'billed_minutes' => $totalBilled, 'net_cents' => $net, 'tax_cents' => $tax, 'gross_cents' => $gross],
            'entries' => $snapshotEntries,
        ];

        $excludedRows = $excluded->map(function (TimeEntry $e) use ($projects) {
            [$itemKey, $itemTitle] = $this->ticket($e);

            return [
                'id' => $e->id,
                'date' => $e->spent_on->format('Y-m-d'),
                'person' => $e->user?->name ?? self::FORMER_MEMBER,
                'project_key' => $projects->get($e->project_id)?->key,
                'item_key' => $itemKey,
                'item_title' => $itemTitle,
                'activity' => $e->activity?->name ?? self::NO_ACTIVITY,
                'minutes' => $e->minutes,
                'billable' => (bool) $e->billable,
                'note' => $e->note,
            ];
        })->values()->all();

        return new ReportComputation(
            snapshot: $snapshot,
            entryIds: $entries->pluck('id')->sort()->values()->all(),
            rows: $rows,
            excludedRows: $excludedRows,
            fixedCharges: $charges,
            warnings: [
                'without_activity' => $withoutActivity,
                'non_billable' => $nonBillable,
                'nothing_to_bill' => $entries->isEmpty(),
                'settings_incomplete' => trim((string) $setting->company_name) === '',
            ],
        );
    }

    /**
     * The client's projects this report covers, keyed by id (options.project_ids null = all of them).
     *
     * @param  array{project_ids: ?list<int>}  $options
     * @return Collection<int, Project>
     */
    public function projects(BillingReport $report, array $options): Collection
    {
        if (! $report->client_id) {
            return collect();
        }

        return Project::where('workspace_id', $report->workspace_id)
            ->where('client_id', $report->client_id)
            ->when($options['project_ids'] !== null, fn (Builder $q) => $q->whereIn('id', $options['project_ids']))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'key'])
            ->keyBy('id');
    }

    /**
     * The entries a draft looks at, before the unbilled filter: the workspace's entries of these
     * projects in the report's period.
     *
     * @param  list<int>  $projectIds
     */
    public function draftScope(BillingReport $report, array $projectIds): Builder
    {
        return TimeEntry::query()
            ->where('workspace_id', $report->workspace_id)
            ->whereIn('project_id', $projectIds)
            ->whereBetween('spent_on', [$report->period_from->format('Y-m-d'), $report->period_to->format('Y-m-d')]);
    }

    /**
     * Report options with defaults (stored in billing_reports.options).
     *
     * @return array{project_ids: ?list<int>, group_by: string, show_people: bool, show_notes: bool, excluded_entry_ids: list<int>}
     */
    public static function options(?array $options): array
    {
        $options ??= [];
        $groupBy = $options['group_by'] ?? 'ticket';

        return [
            'project_ids' => isset($options['project_ids']) && is_array($options['project_ids'])
                ? array_values(array_map('intval', $options['project_ids'])) : null,
            'group_by' => in_array($groupBy, BillingReport::GROUP_BY, true) ? $groupBy : 'ticket',
            'show_people' => (bool) ($options['show_people'] ?? true),
            'show_notes' => (bool) ($options['show_notes'] ?? false),
            'excluded_entry_ids' => array_values(array_map('intval', (array) ($options['excluded_entry_ids'] ?? []))),
        ];
    }

    /** "Time report October 2026" for a calendar month, otherwise with both dates. */
    public static function defaultTitle(string $kind, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);
        $label = $kind === 'invoice' ? 'Invoice' : 'Time report';

        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth())) {
            return $label.' '.$from->format('F Y');
        }

        return $label.' '.$from->format('j M Y').' – '.$to->format('j M Y');
    }

    /** Per entry: rounded up to the next multiple of $step minutes (0 = exact). */
    public static function roundUp(int $minutes, int $step): int
    {
        return $step > 0 ? intdiv($minutes + $step - 1, $step) * $step : $minutes;
    }

    /** round($a / $b) half up, in integers (no float drift), for $a, $b ≥ 0. */
    public static function divHalfUp(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /**
     * The money rules: net prices → tax on top; gross prices → tax taken out of the sum.
     *
     * @return array{0: int, 1: int, 2: int} [net, tax, gross]
     */
    public static function totals(int $sum, int $taxRateBp, bool $pricesIncludeTax): array
    {
        if ($pricesIncludeTax) {
            $net = self::divHalfUp($sum * 10000, 10000 + $taxRateBp);

            return [$net, $sum - $net, $sum];
        }
        $tax = self::divHalfUp($sum * $taxRateBp, 10000);

        return [$sum, $tax, $sum + $tax];
    }

    /** [key, title] of the entry's ticket: the live issue while it exists, else the snapshot at logging. */
    private function ticket(TimeEntry $entry): array
    {
        if ($entry->item) {
            return [$entry->item->key, $entry->item->title];
        }

        return [$entry->item_key ?: null, $entry->item_title ?: null];
    }

    /**
     * Fixed activities + tickets of these entries that another (not voided) report already charged,
     * as "item|activity" => true.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return array<string, true>
     */
    private function chargedElsewhere(BillingReport $report, Collection $entries): array
    {
        $fixed = $entries->filter(fn (TimeEntry $e) => $e->item_id && $e->activity?->isFixed());
        if ($fixed->isEmpty()) {
            return [];
        }

        return BillingFixedCharge::whereIn('item_id', $fixed->pluck('item_id')->unique()->values())
            ->whereIn('billing_activity_id', $fixed->pluck('billing_activity_id')->unique()->values())
            ->where('billing_report_id', '!=', $report->id)
            ->get(['item_id', 'billing_activity_id'])
            ->mapWithKeys(fn (BillingFixedCharge $c) => [$c->item_id.'|'.$c->billing_activity_id => true])
            ->all();
    }

    /** Sort key of a line: tickets in key order (no ticket last), activities by name (none last), people by name. */
    private function lineSort(array $line, string $groupBy): array
    {
        $activity = $line['activity'] ? [0, $line['activity']->name] : [1, ''];
        $ticket = $line['item_key'] !== null ? [0, $line['item_key']] : [1, (string) $line['item_title']];

        return match (true) {
            $line['fixed'] && $groupBy !== 'ticket' => [1, ...$activity, ...$ticket],
            $groupBy === 'activity' => [0, ...$activity],
            $groupBy === 'person' => [0, $line['person_sort'] === self::FORMER_MEMBER ? 1 : 0, (string) $line['person_sort'], ...$activity],
            default => [0, ...$ticket, ...$activity],
        };
    }

    /** Element-wise compare: ints numerically, strings naturally and case-insensitively. */
    private static function compareKeys(array $a, array $b): int
    {
        foreach ($a as $i => $value) {
            $other = $b[$i] ?? null;
            $result = is_int($value) && is_int($other) ? $value <=> $other : strnatcasecmp((string) $value, (string) $other);
            if ($result !== 0) {
                return $result;
            }
        }

        return count($a) <=> count($b);
    }
}
