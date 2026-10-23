<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Support\LeadForms\LeadFormOptions;
use App\Support\LeadForms\LeadIntake;
use App\Support\OutboundUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * POSTs a new lead to the form's webhook URL (BRAI-93). The body is signed with the form's
 * secret: `X-Brainitive-Signature: sha256=<hex hmac of the raw body>`. Only public hosts
 * (OutboundUrl), no redirects; 5xx and timeouts are retried, 4xx are not.
 */
class DeliverLeadWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $leadId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $lead = Lead::with('form.project.workspace')->find($this->leadId);
        $form = $lead?->form;
        $url = $form ? LeadFormOptions::settings($form->settings)['webhook_url'] : null;
        if (! $url || ! $form->webhook_secret) {
            return;
        }

        $options = OutboundUrl::options($url);
        if ($options === null) {
            Log::warning('Lead webhook skipped: URL not allowed', ['lead_form' => $form->id]);

            return;
        }

        $body = json_encode(LeadIntake::payload($lead), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = Http::withOptions($options)
            ->timeout(10)
            ->withHeaders([
                'User-Agent' => 'Brainitive-Webhook/1.0',
                'X-Brainitive-Event' => 'lead.created',
                'X-Brainitive-Delivery' => (string) Str::uuid(),
                'X-Brainitive-Signature' => 'sha256='.hash_hmac('sha256', $body, $form->webhook_secret),
            ])
            ->withBody($body, 'application/json')
            ->post($url);

        if ($response->serverError()) {
            throw new RuntimeException("Lead webhook returned {$response->status()}");
        }
        if (! $response->successful()) {
            Log::warning('Lead webhook rejected', ['lead_form' => $form->id, 'status' => $response->status()]);
        }
    }
}
