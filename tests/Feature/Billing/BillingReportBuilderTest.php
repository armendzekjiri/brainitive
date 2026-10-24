<?php

namespace Tests\Feature\Billing;

use App\Contracts\Billing\BuildsReportData;
use App\Models\BillingFixedCharge;
use App\Models\BillingReport;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Billing\ReportBuilder;
use App\Support\Billing\ReportFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Snapshot v1 of a billing report (BRAI-101): exact money for net / gross / 0 % / client VAT,
 * rounding, fixed price once per ticket, left-out and non-billable entries, the exact shape, and
 * a fixed number of queries. Fixture and expected amounts: InteractsWithBilling.
 */
class BillingReportBuilderTest extends TestCase
{
    use InteractsWithBilling;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    private function snapshot(?BillingReport $report = null): array
    {
        return app(ReportBuilder::class)->build($report ?? $this->draft());
    }

    /** "KEY activity" => amount_cents for every line, in order. */
    private function lines(array $snapshot): array
    {
        $lines = [];
        foreach ($snapshot['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                $lines[trim(($line['item_key'] ?? $line['item_title'] ?? $line['person'] ?? '').' '.$line['activity']['name'])] = $line['amount_cents'];
            }
        }

        return $lines;
    }

    private function finalize(BillingReport $report): BillingReport
    {
        $ids = app(ReportBuilder::class)->compute($report)->entryIds;

        return app(ReportFinalizer::class)->finalize($report, $ids, $this->admin);
    }

    public function test_net_prices_with_19_percent_vat(): void
    {
        $this->settings(['default_tax_rate_bp' => 1900]);
        $snapshot = $this->snapshot();

        $this->assertSame([
            'SHOP-1 Programming' => 6000,
            'SHOP-1 SSM setup' => 45000,
            'WEB-1 Programming' => 30000,
            'WEB-2 Testing' => 5250,
        ], $this->lines($snapshot));
        $this->assertSame(['Shop', 'Website'], array_map(fn ($g) => $g['project']['name'], $snapshot['groups']));
        $this->assertSame(51000, $snapshot['groups'][0]['net_cents']);
        $this->assertSame(35250, $snapshot['groups'][1]['net_cents']);
        // 862.50 net, 19 % = 163.875 → 163.88 (half up), 1026.38 gross
        $this->assertSame(['minutes' => 375, 'billed_minutes' => 375, 'net_cents' => 86250, 'tax_cents' => 16388, 'gross_cents' => 102638], $snapshot['totals']);
        $this->assertSame(1900, $snapshot['tax_rate_bp']);
        $this->assertFalse($snapshot['prices_include_tax']);
    }

    public function test_gross_prices_take_the_vat_out_of_the_sum(): void
    {
        $this->settings(['default_tax_rate_bp' => 1900, 'prices_include_tax' => true]);

        // 862.50 gross → net = 862.50 / 1.19 = 724.789… → 724.79, tax = 137.71
        $this->assertSame(['minutes' => 375, 'billed_minutes' => 375, 'net_cents' => 72479, 'tax_cents' => 13771, 'gross_cents' => 86250], $this->snapshot()['totals']);
    }

    public function test_zero_vat(): void
    {
        $this->assertSame(['minutes' => 375, 'billed_minutes' => 375, 'net_cents' => 86250, 'tax_cents' => 0, 'gross_cents' => 86250], $this->snapshot()['totals']);
    }

    public function test_the_clients_vat_rate_overrides_the_workspace_default(): void
    {
        $this->settings(['default_tax_rate_bp' => 1900]);
        $this->client->update(['tax_rate_bp' => 700]);

        $snapshot = $this->snapshot();
        // 7 % of 862.50 = 60.375 → 60.38
        $this->assertSame(700, $snapshot['tax_rate_bp']);
        $this->assertSame(['minutes' => 375, 'billed_minutes' => 375, 'net_cents' => 86250, 'tax_cents' => 6038, 'gross_cents' => 92288], $snapshot['totals']);

        $this->client->update(['tax_rate_bp' => 0]); // a client without VAT (reverse charge)
        $this->assertSame(0, $this->snapshot()['totals']['tax_cents']);
    }

    public function test_each_entry_is_rounded_up_to_15_minutes(): void
    {
        $this->settings(['rounding_minutes' => 15, 'default_tax_rate_bp' => 1900]);
        $snapshot = $this->snapshot();

        // 100 → 105, 50 → 60 (per entry, not per line: 150 would stay 150) ; 70 → 75 ; 125 → 135 ; 30 → 30
        $this->assertSame([
            'SHOP-1 Programming' => 6000,
            'SHOP-1 SSM setup' => 45000, // fixed: the flat price, whatever the hours
            'WEB-1 Programming' => 33000, // 165 min × 120 €/h
            'WEB-2 Testing' => 5625,     // 75 min × 45 €/h
        ], $this->lines($snapshot));
        $web1 = $snapshot['groups'][1]['lines'][0];
        $this->assertSame([150, 165], [$web1['minutes'], $web1['billed_minutes']]);
        $this->assertSame(['minutes' => 375, 'billed_minutes' => 405, 'net_cents' => 89625, 'tax_cents' => 17029, 'gross_cents' => 106654], $snapshot['totals']);
        $this->assertSame(15, $snapshot['rounding_minutes']);
    }

    public function test_hourly_lines_round_half_up_per_line(): void
    {
        $this->testing->update(['rate_cents' => 4999]);
        $this->assertSame(5832, $this->lines($this->snapshot())['WEB-2 Testing']); // 70 / 60 × 49.99 = 58.321…

        $this->assertSame(3, ReportBuilder::divHalfUp(5, 2));   // 2.5 → 3
        $this->assertSame(2, ReportBuilder::divHalfUp(149, 60)); // 2.48 → 2
        $this->assertSame([10000, 1900, 11900], ReportBuilder::totals(10000, 1900, false));
        $this->assertSame([8403, 1597, 10000], ReportBuilder::totals(10000, 1900, true));
    }

    public function test_a_fixed_price_is_billed_once_per_ticket_across_reports(): void
    {
        $october = $this->finalize($this->draft());
        $this->assertSame(45000, $this->lines($october->snapshot)['SHOP-1 SSM setup']);
        $this->assertDatabaseHas('billing_fixed_charges', ['billing_report_id' => $october->id, 'item_id' => $this->items['SHOP-1']->id, 'billing_activity_id' => $this->ssm->id, 'amount_cents' => 45000]);

        // more SSM work on the same ticket in November: shown, but not charged again
        $this->log($this->bob, 'SHOP-1', $this->ssm, 60, '2026-11-04');
        $this->log($this->bob, 'WEB-2', $this->ssm, 30, '2026-11-05'); // another ticket: charged
        $november = $this->draft(['period_from' => '2026-11-01', 'period_to' => '2026-11-30']);
        $snapshot = $this->snapshot($november);

        $shopLine = $snapshot['groups'][0]['lines'][0];
        $this->assertSame(['SHOP-1', 'SSM setup', 60, 0, true], [$shopLine['item_key'], $shopLine['activity']['name'], $shopLine['minutes'], $shopLine['amount_cents'], $shopLine['fixed_already_billed']]);
        $this->assertSame(['SHOP-1 SSM setup' => 0, 'WEB-2 SSM setup' => 45000], $this->lines($snapshot));

        // voiding October frees the charge: November now bills it
        app(ReportFinalizer::class)->void($october);
        $this->assertSame(['SHOP-1 SSM setup' => 45000, 'WEB-2 SSM setup' => 45000], $this->lines($this->snapshot($november->fresh())));
    }

    public function test_a_fixed_price_without_a_ticket_bills_once_per_report_and_project(): void
    {
        $this->log($this->ada, null, $this->ssm, 30, '2026-10-12', project: $this->website);
        $this->log($this->bob, null, $this->ssm, 45, '2026-10-13', project: $this->website);

        $snapshot = $this->snapshot();
        $line = collect($snapshot['groups'][1]['lines'])->firstWhere('item_title', ReportBuilder::NO_TICKET);
        $this->assertSame([null, 75, 45000, false], [$line['item_key'], $line['minutes'], $line['amount_cents'], $line['fixed_already_billed']]);

        $october = $this->finalize($this->draft());
        $this->log($this->ada, null, $this->ssm, 20, '2026-11-02', project: $this->website);
        $november = $this->snapshot($this->draft(['period_from' => '2026-11-01', 'period_to' => '2026-11-30']));
        $this->assertSame(['No ticket SSM setup' => 45000], $this->lines($november)); // a new report: charged again
        $this->assertSame(2, BillingFixedCharge::where('billing_report_id', $october->id)->count()); // SHOP-1 + the ticketless one
    }

    public function test_left_out_entries_are_not_billed(): void
    {
        $report = $this->draft(['options' => ['excluded_entry_ids' => [$this->entries['web2_ada']->id, $this->entries['web1_bob']->id]]]);
        $computation = app(ReportBuilder::class)->compute($report);

        $this->assertSame(['SHOP-1 Programming' => 6000, 'SHOP-1 SSM setup' => 45000, 'WEB-1 Programming' => 20000], $this->lines($computation->snapshot));
        $this->assertSame(71000, $computation->snapshot['totals']['net_cents']);
        $this->assertEqualsCanonicalizing([$this->entries['web2_ada']->id, $this->entries['web1_bob']->id], array_column($computation->excludedRows, 'id'));
        $this->assertNotContains($this->entries['web2_ada']->id, $computation->entryIds);
        $this->assertNotContains($this->entries['web2_ada']->id, array_column($computation->snapshot['entries'], 'id'));
    }

    public function test_non_billable_entries_are_listed_but_add_nothing(): void
    {
        $snapshot = $this->snapshot();
        $entry = collect($snapshot['entries'])->firstWhere('id', $this->entries['shop1_test']->id);

        $this->assertSame([false, 20, 0], [$entry['billable'], $entry['minutes'], $entry['billed_minutes']]);
        $this->assertArrayNotHasKey('SHOP-1 Testing', $this->lines($snapshot));
        $this->assertSame(86250, $snapshot['totals']['net_cents']);
        $this->assertCount(6, $snapshot['entries']); // September and the other client's entry are out of scope
    }

    public function test_entries_without_activity_are_shown_as_no_activity_and_not_billed(): void
    {
        $this->log($this->ada, 'WEB-1', null, 45, '2026-10-20');
        // a work log from before activities existed, backfilled as a legacy entry
        $this->log($this->bob, 'WEB-1', null, 30, '2026-10-21')->forceFill(['source' => TimeEntry::SOURCE_LEGACY])->save();
        $computation = app(ReportBuilder::class)->compute($this->draft());

        $this->assertSame(0, $this->lines($computation->snapshot)['WEB-1 No activity']);
        $this->assertSame(2, $computation->warnings['without_activity']);
        $this->assertSame(86250, $computation->snapshot['totals']['net_cents']);
    }

    public function test_grouping_by_activity_and_person_people_and_notes(): void
    {
        $byActivity = $this->snapshot($this->draft(['options' => ['group_by' => 'activity', 'show_people' => false]]));
        $website = $byActivity['groups'][1]['lines'];
        $this->assertSame([['Programming', null, null, 150, 30000], ['Testing', null, null, 70, 5250]],
            array_map(fn ($l) => [$l['activity']['name'], $l['item_key'], $l['person'], $l['minutes'], $l['amount_cents']], $website));
        // fixed prices stay per ticket, after the hourly lines
        $this->assertSame(['Programming', 'SSM setup'], array_map(fn ($l) => $l['activity']['name'], $byActivity['groups'][0]['lines']));
        $this->assertSame('SHOP-1', $byActivity['groups'][0]['lines'][1]['item_key']);
        $this->assertNull($byActivity['entries'][0]['person']); // names hidden everywhere

        $byPerson = $this->snapshot($this->draft(['options' => ['group_by' => 'person', 'show_notes' => true]]));
        $this->assertSame([['Ada Developer', 'Programming', 100, 20000], ['Ada Developer', 'Testing', 70, 5250], ['Bob Developer', 'Programming', 50, 10000]],
            array_map(fn ($l) => [$l['person'], $l['activity']['name'], $l['minutes'], $l['amount_cents']], $byPerson['groups'][1]['lines']));
        $this->assertSame(['Fixed rounding in cart'], $byPerson['groups'][1]['lines'][0]['notes']);

        $byTicket = $this->snapshot($this->draft(['options' => ['show_notes' => true]]));
        $web1 = $byTicket['groups'][1]['lines'][0];
        $this->assertSame(['Ada Developer, Bob Developer', ['Fixed rounding in cart', 'Review']], [$web1['person'], $web1['notes']]);
        $this->assertSame([], $this->snapshot()['groups'][1]['lines'][0]['notes']); // notes hidden by default
    }

    public function test_only_the_chosen_projects_and_unbilled_entries_are_used(): void
    {
        $snapshot = $this->snapshot($this->draft(['options' => ['project_ids' => [$this->website->id]]]));
        $this->assertSame(['Website'], array_column(array_column($snapshot['groups'], 'project'), 'name'));

        $this->entries['web2_ada']->forceFill(['billing_report_id' => BillingReport::create(['workspace_id' => $this->workspace->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized'])->id])->save();
        $this->assertArrayNotHasKey('WEB-2 Testing', $this->lines($this->snapshot()));
    }

    public function test_the_live_ticket_title_is_used_while_the_issue_exists(): void
    {
        $this->items['WEB-1']->update(['title' => 'Checkout bug (renamed)']);
        $this->items['WEB-2']->delete(); // soft-deleted: the title at logging time

        $lines = $this->snapshot()['groups'][1]['lines'];
        $this->assertSame(['Checkout bug (renamed)', 'Pricing page'], array_column($lines, 'item_title'));
    }

    public function test_the_snapshot_has_exactly_the_v1_shape(): void
    {
        $this->settings(['company_name' => 'Brainitive GmbH', 'invoice_mode' => true, 'number_prefix' => '2026-', 'next_number' => 14]);
        $snapshot = $this->snapshot();

        $this->assertSame(['version', 'kind', 'number', 'title', 'issued_on', 'period', 'currency', 'tax_rate_bp', 'prices_include_tax', 'rounding_minutes', 'issuer', 'client', 'options', 'groups', 'totals', 'entries'], array_keys($snapshot));
        $this->assertSame(['from', 'to'], array_keys($snapshot['period']));
        $this->assertSame(['company_name', 'address', 'vat_id', 'email', 'phone', 'bank_name', 'iban', 'bic', 'footer', 'logo_path', 'payment_terms_days'], array_keys($snapshot['issuer']));
        $this->assertSame(['name', 'email', 'address', 'vat_id'], array_keys($snapshot['client']));
        $this->assertSame(['group_by', 'show_people', 'show_notes'], array_keys($snapshot['options']));
        $this->assertSame(['project', 'lines', 'minutes', 'billed_minutes', 'net_cents'], array_keys($snapshot['groups'][0]));
        $this->assertSame(['id', 'name', 'key'], array_keys($snapshot['groups'][0]['project']));
        $this->assertSame(['item_key', 'item_title', 'activity', 'person', 'minutes', 'billed_minutes', 'rate_cents', 'amount_cents', 'fixed_already_billed', 'notes', 'entry_ids'], array_keys($snapshot['groups'][0]['lines'][0]));
        $this->assertSame(['name', 'pricing_type'], array_keys($snapshot['groups'][0]['lines'][0]['activity']));
        $this->assertSame(['minutes', 'billed_minutes', 'net_cents', 'tax_cents', 'gross_cents'], array_keys($snapshot['totals']));
        $this->assertSame(['id', 'date', 'person', 'project_key', 'item_key', 'item_title', 'activity', 'minutes', 'billed_minutes', 'billable', 'note'], array_keys($snapshot['entries'][0]));

        $this->assertSame([1, 'invoice', null, 'Time report October 2026', ['from' => '2026-10-01', 'to' => '2026-10-31'], 'EUR'],
            [$snapshot['version'], $snapshot['kind'], $snapshot['number'], $snapshot['title'], $snapshot['period'], $snapshot['currency']]);
        $this->assertSame(['name' => 'Muster GmbH', 'email' => 'billing@muster.test', 'address' => "Hauptstr. 1\nBerlin", 'vat_id' => 'DE123'], $snapshot['client']);
        $this->assertSame('Brainitive GmbH', $snapshot['issuer']['company_name']);
        $this->assertSame('', $snapshot['issuer']['iban']);
        $this->assertSame([$this->entries['web1_ada']->id, $this->entries['web1_bob']->id], $snapshot['groups'][1]['lines'][0]['entry_ids']);
        $this->assertSame(['id' => $this->entries['web1_ada']->id, 'date' => '2026-10-02', 'person' => 'Ada Developer', 'project_key' => 'WEB', 'item_key' => 'WEB-1', 'item_title' => 'Checkout bug', 'activity' => 'Programming', 'minutes' => 100, 'billed_minutes' => 100, 'billable' => true, 'note' => null], $snapshot['entries'][0]);

        // the documents part resolves the same builder through the contract
        $this->assertInstanceOf(ReportBuilder::class, app(BuildsReportData::class));
    }

    public function test_ordering_is_deterministic(): void
    {
        $first = $this->snapshot();
        TimeEntry::query()->update(['updated_at' => now()->addMinute()]);
        $this->assertSame($first, $this->snapshot());
        $dates = array_column($first['entries'], 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_a_finalized_report_is_built_from_its_locked_entries(): void
    {
        $report = $this->finalize($this->draft());
        $this->log($this->ada, 'WEB-1', $this->programming, 60, '2026-10-30'); // later time is not part of it

        $rebuilt = app(ReportBuilder::class)->build($report->fresh());
        $this->assertSame($report->snapshot['totals'], $rebuilt['totals']);
        $this->assertSame($report->snapshot['groups'], $rebuilt['groups']);
    }

    public function test_building_uses_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            $report = $this->draft(['options' => ['excluded_entry_ids' => [$this->entries['web2_ada']->id]]]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ReportBuilder::class)->build($report);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $small = $count();

        foreach (range(1, 15) as $i) {
            $person = User::factory()->create();
            $this->items["WEB-x{$i}"] = $this->issue($this->website, "Issue {$i}");
            $this->log($person, "WEB-x{$i}", $i % 2 ? $this->programming : $this->ssm, 10 + $i, '2026-10-'.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT));
        }

        $this->assertSame($small, $count());
        $this->assertLessThanOrEqual(9, $small);
    }
}
