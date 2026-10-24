<?php

namespace Tests\Feature\Webhooks;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Billing\ReportBuilder;
use App\Support\Billing\ReportFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Billing\InteractsWithBilling;
use Tests\TestCase;

/**
 * billing_report.finalized / billing_report.paid (BRAI-104): the only events that carry amounts.
 */
class WebhookBillingEventsTest extends TestCase
{
    use InteractsWithBilling;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
        Http::fake(['*' => Http::response('ok')]);
        Webhook::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Accounting', 'url' => 'https://93.184.216.34/hook',
            'secret' => 'whsec_test', 'events' => ['billing_report.finalized', 'billing_report.paid'], 'active' => true,
        ]);
    }

    public function test_finalize_and_mark_paid_send_the_report_totals(): void
    {
        $report = $this->draft();
        $finalizer = app(ReportFinalizer::class);

        $finalizer->finalize($report, app(ReportBuilder::class)->compute($report->fresh())->entryIds, $this->admin);
        $finalizer->finalize($report->fresh(), [], $this->admin); // a second click changes nothing → no second event

        $finalized = WebhookDelivery::where('event', 'billing_report.finalized')->pluck('payload');
        $this->assertCount(1, $finalized);
        $data = $finalized[0]['data']['report'];
        $report->refresh();
        $this->assertSame(['finalized', $report->total_cents, $report->currency, $this->client->name], [$data['status'], $data['total_cents'], $data['currency'], $data['client']['name']]);
        $this->assertSame(route('billing.reports.show', [$this->workspace->slug, $report->id]), $data['url']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->name], $finalized[0]['actor']);

        $this->actingAs($this->admin);
        $finalizer->markPaid($report);
        $paid = WebhookDelivery::where('event', 'billing_report.paid')->value('payload');
        $this->assertSame('paid', $paid['data']['report']['status']);
        $this->assertNotNull($paid['data']['report']['paid_at']);
    }
}
