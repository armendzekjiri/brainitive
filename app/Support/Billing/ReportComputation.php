<?php

namespace App\Support\Billing;

/**
 * The result of ReportBuilder::compute() (v1.38.0, BRAI-101): the snapshot v1 array plus what the
 * report pages and the finalizer need around it. Only `snapshot` leaves the app (documents).
 */
final class ReportComputation
{
    /**
     * @param  array<string, mixed>  $snapshot  snapshot v1 (docs/ai/tasks/release-v1.38.0-billing.md)
     * @param  list<int>  $entryIds  the entries the report contains (locked on finalize), ascending
     * @param  list<array<string, mixed>>  $rows  every contained entry for the admin, always with person + note
     * @param  list<array<string, mixed>>  $excludedRows  entries in scope the admin left out
     * @param  list<array{project_id: int, item_id: ?int, billing_activity_id: int, amount_cents: int}>  $fixedCharges  fixed prices this report charges
     * @param  array{without_activity: int, non_billable: int, nothing_to_bill: bool, settings_incomplete: bool}  $warnings
     */
    public function __construct(
        public readonly array $snapshot,
        public readonly array $entryIds,
        public readonly array $rows,
        public readonly array $excludedRows,
        public readonly array $fixedCharges,
        public readonly array $warnings,
    ) {}
}
