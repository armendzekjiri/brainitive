<?php

namespace App\Support\Billing;

use App\Models\BillingFixedCharge;
use App\Models\BillingReport;
use App\Models\BillingSetting;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing report status changes that touch the ledger (v1.38.0, BRAI-101), each in one transaction
 * on the locked report row:
 * - finalize: freezes the snapshot and locks the entries (time_entries.billing_report_id), records
 *   the fixed charges and, in invoice mode, takes the next number. Refuses (422, nothing changes)
 *   when an entry the admin reviewed was billed by another report meanwhile, or the entries changed.
 *   A report that is already final comes back as it is (double click → one number).
 * - void: unlocks the entries and frees the fixed charges; the snapshot and the number stay.
 * - markPaid.
 */
class ReportFinalizer
{
    public function __construct(private readonly ReportBuilder $builder) {}

    /**
     * @param  list<int>  $reviewedEntryIds  the entries of the preview the admin confirmed
     */
    public function finalize(BillingReport $report, array $reviewedEntryIds, User $user): BillingReport
    {
        try {
            return DB::transaction(function () use ($report, $reviewedEntryIds, $user) {
                $locked = BillingReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
                if ($locked->isFinal()) {
                    return $locked; // a second click: same report, same number
                }
                if (! $locked->isDraft()) {
                    throw ValidationException::withMessages(['report' => 'A void report can’t be finalized. Create a new report instead.']);
                }
                $locked->setRelation('workspace', $report->workspace);

                $setting = BillingSetting::where('workspace_id', $locked->workspace_id)->lockForUpdate()->first()
                    ?? BillingSetting::forWorkspace($report->workspace);
                $options = ReportBuilder::options($locked->options);
                $reviewed = array_values(array_unique(array_map('intval', $reviewedEntryIds)));
                $projects = $this->builder->projects($locked, $options);

                // the unbilled entries in scope plus the reviewed ones whatever their state, locked in
                // id order: a parallel finalize waits here, then sees the other report's id
                $entries = $this->builder->draftScope($locked, $projects->keys()->all())
                    ->where(fn ($q) => $q->whereNull('billing_report_id')->orWhereIn('id', $reviewed))
                    ->whereNotIn('id', $options['excluded_entry_ids'])
                    ->orderBy('id')->lockForUpdate()->get();

                $billedElsewhere = $entries->filter(fn (TimeEntry $e) => $e->billing_report_id !== null)->count();
                if ($billedElsewhere > 0) {
                    throw ValidationException::withMessages(['report' => $billedElsewhere === 1
                        ? '1 time entry of this report was billed by another report in the meantime. Nothing was finalized; check the report again.'
                        : "{$billedElsewhere} time entries of this report were billed by another report in the meantime. Nothing was finalized; check the report again."]);
                }
                $ids = $entries->pluck('id')->sort()->values()->all();
                $expected = $reviewed;
                sort($expected);
                if ($ids !== $expected) {
                    throw ValidationException::withMessages(['report' => 'Time was logged, changed or removed since you opened this report. Nothing was finalized; check the report again.']);
                }
                if ($ids === []) {
                    throw ValidationException::withMessages(['report' => 'There is nothing to bill in this report.']);
                }

                $kind = $setting->invoice_mode ? 'invoice' : 'report';
                $number = null;
                if ($kind === 'invoice') {
                    $next = max(1, (int) $setting->next_number);
                    $number = InvoiceNumber::format($setting->number_prefix, $next);
                    if (BillingReport::where('workspace_id', $locked->workspace_id)->where('number', $number)->exists()) {
                        throw ValidationException::withMessages(['report' => "Invoice number {$number} is already used. Set the next number in the billing settings."]);
                    }
                    $setting->next_number = $next + 1;
                    $setting->save();
                }

                $locked->forceFill(['kind' => $kind, 'number' => $number, 'status' => 'finalized', 'finalized_at' => now()]);
                $result = $this->builder->compute($locked, $entries, $setting, now($user->localTimezone())->format('Y-m-d'));
                $snapshot = $result->snapshot;

                $locked->forceFill([
                    'title' => $snapshot['title'],
                    'currency' => $snapshot['currency'],
                    'tax_rate_bp' => $snapshot['tax_rate_bp'],
                    'prices_include_tax' => $snapshot['prices_include_tax'],
                    'total_minutes' => $snapshot['totals']['billed_minutes'],
                    'subtotal_cents' => $snapshot['totals']['net_cents'],
                    'tax_cents' => $snapshot['totals']['tax_cents'],
                    'total_cents' => $snapshot['totals']['gross_cents'],
                    'snapshot' => $snapshot,
                ])->save();

                // claim only entries nobody billed: the rows are locked, so this is a belt-and-braces check
                $claimed = TimeEntry::whereIn('id', $ids)->whereNull('billing_report_id')->update(['billing_report_id' => $locked->id]);
                if ($claimed !== count($ids)) {
                    throw ValidationException::withMessages(['report' => 'Some time entries were billed by another report in the meantime. Nothing was finalized; check the report again.']);
                }

                $now = now();
                if ($result->fixedCharges !== []) {
                    BillingFixedCharge::insert(array_map(fn (array $charge) => $charge + [
                        'workspace_id' => $locked->workspace_id,
                        'billing_report_id' => $locked->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $result->fixedCharges));
                }

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            // a parallel finalize charged the same ticket's fixed price (or took the number) first
            throw ValidationException::withMessages(['report' => 'Another report was finalized at the same time with some of these charges. Nothing was finalized; check the report again.']);
        }
    }

    /** Void a final report: its entries become billable again and its fixed prices are freed. */
    public function void(BillingReport $report): BillingReport
    {
        return DB::transaction(function () use ($report) {
            $locked = BillingReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'void') {
                return $locked;
            }
            if (! $locked->isFinal()) {
                throw ValidationException::withMessages(['report' => 'Only finalized reports can be voided. Delete the draft instead.']);
            }

            TimeEntry::where('billing_report_id', $locked->id)->update(['billing_report_id' => null]);
            BillingFixedCharge::where('billing_report_id', $locked->id)->delete();
            $locked->forceFill(['status' => 'void', 'voided_at' => now()])->save();

            return $locked;
        });
    }

    public function markPaid(BillingReport $report): BillingReport
    {
        return DB::transaction(function () use ($report) {
            $locked = BillingReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return $locked;
            }
            if (! in_array($locked->status, ['finalized', 'sent'], true)) {
                throw ValidationException::withMessages(['report' => 'Only finalized or sent reports can be marked as paid.']);
            }
            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return $locked;
        });
    }
}
