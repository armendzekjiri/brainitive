<?php

namespace Tests\Feature\Billing;

use App\Models\BillingFixedCharge;
use App\Models\BillingReport;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Billing report pages and status changes (BRAI-101): draft → finalize (locks the entries, refuses
 * double billing) → paid / void (unlocks), invoice numbering, admins only.
 */
class BillingReportFlowTest extends TestCase
{
    use InteractsWithBilling;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    private function url(string $name, ?BillingReport $report = null, array $query = []): string
    {
        return route($name, array_merge(array_values(array_filter([$this->workspace->slug, $report?->id])), $query));
    }

    private function reviewedIds(BillingReport $report): array
    {
        return app(ReportBuilder::class)->compute($report->fresh())->entryIds;
    }

    private function finalize(BillingReport $report, ?array $ids = null)
    {
        return $this->actingAs($this->admin)->post($this->url('billing.reports.finalize', $report), ['entry_ids' => $ids ?? $this->reviewedIds($report)]);
    }

    /** @return list<int> ids of October entries in the client's projects */
    private function octoberIds(): array
    {
        return collect($this->entries)->except(['september', 'other_client'])->pluck('id')->sort()->values()->all();
    }

    public function test_pages_render_for_admins(): void
    {
        $this->assertFileExists(resource_path('js/Pages/Billing/Reports/Index.tsx'));
        $this->assertFileExists(resource_path('js/Pages/Billing/Reports/Show.tsx'));
        $report = $this->draft();

        $this->actingAs($this->admin)->get($this->url('billing.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Billing/Reports/Index')
                ->has('reports.data', 1)
                ->where('reports.data.0.id', $report->id)
                ->where('clients.0.name', 'Muster GmbH')
                ->where('clients.0.projects_count', 2));

        $this->actingAs($this->admin)->get($this->url('billing.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Billing/Reports/Show')
                ->where('report.status', 'draft')
                ->where('preview.totals.net_cents', 86250)
                ->where('entryIds', $this->octoberIds())
                ->where('warnings.settings_incomplete', true)
                ->has('projects', 2));

        // the draft's totals columns cache the preview for the list
        $this->assertSame(86250, $report->fresh()->total_cents);
        $this->actingAs($this->admin)->get($this->url('billing.index'))->assertRedirect($this->url('billing.reports.index'));
    }

    public function test_an_admin_creates_a_draft_for_a_client_and_period(): void
    {
        $response = $this->actingAs($this->admin)->post($this->url('billing.reports.store'), [
            'client_id' => $this->client->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        ]);

        $report = BillingReport::latest('id')->first();
        $response->assertRedirect($this->url('billing.reports.show', $report));
        $this->assertSame(['draft', 'report', 'Time report October 2026', $this->admin->id], [$report->status, $report->kind, $report->title, $report->created_by]);
        $this->assertSame(['project_ids' => null, 'group_by' => 'ticket', 'show_people' => true, 'show_notes' => false, 'excluded_entry_ids' => []], $report->options);
        $this->assertSame(86250, $report->total_cents);
    }

    public function test_a_draft_needs_a_client_of_this_workspace_with_projects_and_a_valid_period(): void
    {
        $empty = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'No projects']);
        $foreign = Client::create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Foreign']);
        $store = fn (array $data) => $this->actingAs($this->admin)->post($this->url('billing.reports.store'), $data + ['period_from' => '2026-10-01', 'period_to' => '2026-10-31']);

        $store(['client_id' => $empty->id])->assertSessionHasErrors('client_id');
        $store(['client_id' => $foreign->id])->assertSessionHasErrors('client_id');
        $store(['client_id' => $this->client->id, 'period_to' => '2026-09-01'])->assertSessionHasErrors('period_to');
        $store(['client_id' => $this->client->id, 'period_to' => '2028-01-01'])->assertSessionHasErrors('period_to');
        $this->assertSame(0, BillingReport::count());
    }

    public function test_a_draft_can_be_changed_and_entries_left_out(): void
    {
        $report = $this->draft();
        $this->actingAs($this->admin)->patch($this->url('billing.reports.update', $report), [
            'title' => 'October work',
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'project_ids' => [$this->website->id],
            'group_by' => 'activity',
            'show_people' => false,
            'show_notes' => true,
            'excluded_entry_ids' => [$this->entries['web2_ada']->id, 999999],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame('October work', $report->title);
        $this->assertSame(['project_ids' => [$this->website->id], 'group_by' => 'activity', 'show_people' => false, 'show_notes' => true, 'excluded_entry_ids' => [$this->entries['web2_ada']->id]], $report->options);
        $this->assertSame(30000, $report->total_cents);

        // projects of another client are refused
        $this->actingAs($this->admin)->patch($this->url('billing.reports.update', $report), [
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'project_ids' => [$this->otherProject->id],
            'group_by' => 'ticket', 'show_people' => true, 'show_notes' => false, 'excluded_entry_ids' => [],
        ])->assertSessionHasErrors('project_ids');
    }

    public function test_finalizing_freezes_the_snapshot_and_locks_the_entries(): void
    {
        $this->settings(['default_tax_rate_bp' => 1900]);
        $report = $this->draft(['options' => ['excluded_entry_ids' => [$this->entries['web2_ada']->id]]]);

        $this->finalize($report)->assertRedirect($this->url('billing.reports.show', $report))->assertSessionHas('success');

        $report->refresh();
        $this->assertSame(['finalized', 'report', null], [$report->status, $report->kind, $report->number]);
        $this->assertNotNull($report->finalized_at);
        // 810.00 net (862.50 − 52.50 left out), 19 % = 153.90
        $this->assertSame([81000, 15390, 96390, 305], [$report->subtotal_cents, $report->tax_cents, $report->total_cents, $report->total_minutes]);
        $this->assertSame(96390, $report->snapshot['totals']['gross_cents']);
        $this->assertSame(now()->format('Y-m-d'), $report->snapshot['issued_on']);

        $locked = TimeEntry::where('billing_report_id', $report->id)->pluck('id')->sort()->values()->all();
        $this->assertSame(array_values(array_diff($this->octoberIds(), [$this->entries['web2_ada']->id])), $locked);
        $this->assertTrue($this->entries['web1_ada']->fresh()->isLocked());
        $this->assertFalse($this->entries['web2_ada']->fresh()->isLocked()); // left out: still billable later
        $this->assertSame(1, BillingFixedCharge::where('billing_report_id', $report->id)->count());

        // the stored snapshot is shown, not a recomputation: later rate changes don't touch it
        $this->programming->update(['rate_cents' => 99900]);
        $this->actingAs($this->admin)->get($this->url('billing.reports.show', $report))
            ->assertInertia(fn (Assert $page) => $page->where('preview.totals.gross_cents', 96390)->where('report.status', 'finalized'));

        // a finalized report can't be edited or deleted
        $this->actingAs($this->admin)->delete($this->url('billing.reports.destroy', $report))->assertSessionHasErrors('report');
        $this->assertNotNull($report->fresh());
    }

    public function test_a_second_report_does_not_see_billed_entries(): void
    {
        $this->finalize($this->draft());

        $second = $this->draft();
        $computation = app(ReportBuilder::class)->compute($second);
        $this->assertSame([], $computation->entryIds);
        $this->assertTrue($computation->warnings['nothing_to_bill']);
        $this->finalize($second, [])->assertSessionHasErrors('report');
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_finalize_is_refused_when_an_entry_was_billed_by_another_report_meanwhile(): void
    {
        $report = $this->draft();
        $reviewed = $this->reviewedIds($report);

        // another admin finalized a report with one of these entries first
        $other = BillingReport::create(['workspace_id' => $this->workspace->id, 'client_id' => $this->client->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized']);
        $this->entries['web1_bob']->forceFill(['billing_report_id' => $other->id])->save();

        $this->finalize($report, $reviewed)->assertSessionHasErrors(['report' => '1 time entry of this report was billed by another report in the meantime. Nothing was finalized; check the report again.']);

        // nothing changed
        $this->assertSame('draft', $report->fresh()->status);
        $this->assertNull($report->fresh()->snapshot);
        $this->assertSame(0, TimeEntry::where('billing_report_id', $report->id)->count());
        $this->assertSame(0, BillingFixedCharge::count());
        $this->assertSame($other->id, $this->entries['web1_bob']->fresh()->billing_report_id);
    }

    public function test_finalize_is_refused_when_time_changed_since_the_preview(): void
    {
        $report = $this->draft();
        $reviewed = $this->reviewedIds($report);
        $this->log($this->ada, 'WEB-1', $this->programming, 15, '2026-10-29'); // logged meanwhile

        $this->finalize($report, $reviewed)->assertSessionHasErrors('report');
        $this->assertSame('draft', $report->fresh()->status);
        $this->assertSame(0, TimeEntry::whereNotNull('billing_report_id')->count());

        $this->finalize($report)->assertSessionHasNoErrors(); // after reviewing again
        $this->assertSame('finalized', $report->fresh()->status);
    }

    public function test_a_double_finalize_returns_the_same_result_without_a_second_number(): void
    {
        $setting = $this->settings(['invoice_mode' => true, 'number_prefix' => 'RE-', 'next_number' => 7]);
        $report = $this->draft();
        $ids = $this->reviewedIds($report);

        $this->finalize($report, $ids)->assertSessionHasNoErrors();
        $this->finalize($report, $ids)->assertSessionHasNoErrors()->assertSessionHas('success', 'This report was already finalized.');

        $this->assertSame(['invoice', 'RE-007'], [$report->fresh()->kind, $report->fresh()->number]);
        $this->assertSame(8, $setting->fresh()->next_number);
        $this->assertSame('RE-007', $report->fresh()->snapshot['number']);
    }

    public function test_invoice_numbers_are_consecutive_and_unique(): void
    {
        $setting = $this->settings(['invoice_mode' => true, 'number_prefix' => '2026-', 'next_number' => 14]);

        $first = $this->draft(['options' => ['project_ids' => [$this->website->id]]]);
        $this->finalize($first)->assertSessionHasNoErrors();
        $second = $this->draft(['options' => ['project_ids' => [$this->shop->id]]]);
        $this->finalize($second)->assertSessionHasNoErrors();

        $this->assertSame(['2026-014', '2026-015'], [$first->fresh()->number, $second->fresh()->number]);
        $this->assertSame(16, $setting->fresh()->next_number);

        // voiding never frees a number
        $this->actingAs($this->admin)->post($this->url('billing.reports.void', $first))->assertSessionHasNoErrors();
        $this->assertSame('2026-014', $first->fresh()->number);

        // a next number that was already used is refused instead of reused
        $setting->update(['next_number' => 15]);
        $third = $this->draft();
        $this->finalize($third)->assertSessionHasErrors(['report' => 'Invoice number 2026-015 is already used. Set the next number in the billing settings.']);
        $this->assertSame('draft', $third->fresh()->status);
        $this->assertSame(15, $setting->fresh()->next_number);
    }

    public function test_void_unlocks_the_entries_and_frees_the_fixed_charges(): void
    {
        $report = $this->draft();
        $this->finalize($report);
        $this->assertSame(6, TimeEntry::where('billing_report_id', $report->id)->count());

        $this->actingAs($this->admin)->post($this->url('billing.reports.void', $report))->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame('void', $report->status);
        $this->assertNotNull($report->voided_at);
        $this->assertNotNull($report->snapshot); // the document stays readable
        $this->assertSame(0, TimeEntry::where('billing_report_id', $report->id)->count());
        $this->assertSame(0, BillingFixedCharge::count());

        // billable again
        $this->assertSame($this->octoberIds(), app(ReportBuilder::class)->compute($this->draft())->entryIds);
        // a void report can't come back
        $this->finalize($report, $this->octoberIds())->assertSessionHasErrors('report');
    }

    public function test_status_goes_finalized_paid_and_paid_needs_a_confirm_to_void(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->admin)->post($this->url('billing.reports.paid', $draft))->assertSessionHasErrors('report');
        $this->actingAs($this->admin)->post($this->url('billing.reports.void', $draft))->assertSessionHasErrors('report');

        $this->finalize($draft);
        $draft->forceFill(['status' => 'sent', 'sent_at' => now()])->save(); // the documents part sends it
        $this->actingAs($this->admin)->post($this->url('billing.reports.paid', $draft))->assertSessionHasNoErrors();
        $this->assertSame('paid', $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->paid_at);

        $this->actingAs($this->admin)->post($this->url('billing.reports.void', $draft))->assertSessionHasErrors('report');
        $this->assertSame('paid', $draft->fresh()->status);
        $this->actingAs($this->admin)->post($this->url('billing.reports.void', $draft), ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertSame('void', $draft->fresh()->status);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $report = $this->draft();
        $this->actingAs($this->admin)->delete($this->url('billing.reports.destroy', $report))->assertRedirect($this->url('billing.reports.index'));
        $this->assertNull($report->fresh());
    }

    public function test_the_list_filters_by_status_and_client(): void
    {
        $draft = $this->draft();
        $final = $this->draft(['options' => ['project_ids' => [$this->website->id]]]);
        $this->finalize($final);
        $other = $this->draft(['client_id' => Client::where('name', 'Other AG')->value('id')]);

        $ids = fn (array $query) => collect($this->actingAs($this->admin)->get($this->url('billing.reports.index', null, $query))->viewData('page')['props']['reports']['data'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$draft->id, $final->id, $other->id], $ids([]));
        $this->assertSame([$final->id], $ids(['status' => 'finalized']));
        $this->assertEqualsCanonicalizing([$draft->id, $final->id], $ids(['client' => $this->client->id]));
    }

    public function test_members_are_forbidden(): void
    {
        $report = $this->draft();
        $this->actingAs($this->ada);

        $this->get($this->url('billing.reports.index'))->assertForbidden();
        $this->get($this->url('billing.reports.show', $report))->assertForbidden();
        $this->post($this->url('billing.reports.store'), ['client_id' => $this->client->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31'])->assertForbidden();
        $this->patch($this->url('billing.reports.update', $report), [])->assertForbidden();
        $this->post($this->url('billing.reports.finalize', $report), ['entry_ids' => $this->octoberIds()])->assertForbidden();
        $this->post($this->url('billing.reports.void', $report))->assertForbidden();
        $this->post($this->url('billing.reports.paid', $report))->assertForbidden();
        $this->delete($this->url('billing.reports.destroy', $report))->assertForbidden();
        $this->assertSame('draft', $report->fresh()->status);
    }

    public function test_another_workspaces_report_is_not_found(): void
    {
        $stranger = User::factory()->create();
        $theirs = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $theirs->addUser($stranger, 'admin');
        $report = $this->draft();

        $this->actingAs($stranger)->get(route('billing.reports.show', [$theirs->slug, $report->id]))->assertNotFound();
        $this->actingAs($stranger)->post(route('billing.reports.finalize', [$theirs->slug, $report->id]), ['entry_ids' => $this->octoberIds()])->assertNotFound();
        $this->actingAs($stranger)->post(route('billing.reports.void', [$theirs->slug, $report->id]))->assertNotFound();
        $this->actingAs($stranger)->get($this->url('billing.reports.show', $report))->assertForbidden(); // not a member here
        $this->assertSame('draft', $report->fresh()->status);

        // a project of another workspace can't be put into this one's draft
        $foreignProject = Project::factory()->create(['workspace_id' => $theirs->id, 'created_by' => $stranger->id]);
        $this->actingAs($this->admin)->patch($this->url('billing.reports.update', $report), [
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'project_ids' => [$foreignProject->id],
            'group_by' => 'ticket', 'show_people' => true, 'show_notes' => false, 'excluded_entry_ids' => [],
        ])->assertSessionHasErrors('project_ids');
    }
}
