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

    /**
     * A digest of every value that shapes the document (Codex web review V): each contained entry (minutes,
     * billable, activity, rate, amount, ticket, person, note), the options, title, period, tax, rounding, currency,
     * issuer and client details, the totals and the fixed charges. The preview sends it; finalize recomputes it
     * under the locks and refuses a mismatch. What finalize itself assigns (kind, number, issue date) and the
     * derived grouping are left out; lists are sorted, so the order entries were read in doesn't matter.
     */
    public function revision(): string
    {
        $byId = function (array $list): array {
            usort($list, fn ($a, $b) => [(int) ($a['id'] ?? 0), json_encode($a)] <=> [(int) ($b['id'] ?? 0), json_encode($b)]);

            return $list;
        };
        $snapshot = $this->snapshot;
        unset($snapshot['version'], $snapshot['kind'], $snapshot['number'], $snapshot['issued_on'], $snapshot['groups']);
        $snapshot['entries'] = $byId((array) ($snapshot['entries'] ?? []));
        $charges = $this->fixedCharges;
        usort($charges, fn ($a, $b) => json_encode($a) <=> json_encode($b));

        return hash('sha256', (string) json_encode([$snapshot, $byId($this->rows), $this->entryIds, $charges], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
