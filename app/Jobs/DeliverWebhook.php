<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Notifications\WebhookDisabledNotification;
use App\Support\Webhooks\WebhookUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * POSTs one webhook delivery (v1.39.0, BRAI-104; modelled on DeliverLeadWebhook).
 *
 * - Only hosts allowed by WebhookUrl (OutboundUrl: public IPs, pinned resolve, no redirects),
 *   re-checked before every attempt.
 * - Headers: X-Brainitive-Event, X-Brainitive-Delivery (delivery uuid), X-Brainitive-Timestamp
 *   (unix seconds) and X-Brainitive-Signature: sha256=<hex hmac_sha256(secret, "{timestamp}.{body}")>.
 * - 2xx = success. 5xx, 429 and network errors are retried (30 s, 2 min, 10 min); any other status
 *   fails at once. A sync job can't be released, so it gets one attempt (see WebhookDispatcher).
 * - The webhook's failure_count counts finally failed deliveries in a row; at
 *   Webhook::MAX_CONSECUTIVE_FAILURES it is disabled and the workspace admins are notified.
 *   A success resets it.
 * - Never throws: the outcome is written to the delivery row.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public int $deliveryId, public bool $manual = false) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('webhook.workspace')->find($this->deliveryId);
        $webhook = $delivery?->webhook;
        if (! $delivery || ! $webhook || $delivery->status === WebhookDelivery::STATUS_SUCCESS) {
            return;
        }

        $delivery->attempts++;

        if (! $webhook->active && ! $this->manual) {
            $this->finish($delivery, $webhook, false, null, 'The webhook is disabled.');

            return;
        }

        $options = WebhookUrl::options($webhook->url);
        if ($options === null) {
            $this->finish($delivery, $webhook, false, null, 'URL not allowed: it must be https and resolve to a public address.');

            return;
        }

        // the receiver's answer is only logged (2 KB): refuse to download more than 1 MB of it
        $options['curl'][CURLOPT_MAXFILESIZE] = 1024 * 1024;
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->getTimestamp();
        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = Http::withOptions($options)
                ->timeout((int) config('webhooks.timeout', 10))
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'Brainitive-Webhook/1.0',
                    'X-Brainitive-Event' => $delivery->event,
                    'X-Brainitive-Delivery' => $delivery->uuid,
                    'X-Brainitive-Timestamp' => $timestamp,
                    'X-Brainitive-Signature' => self::signature((string) $webhook->secret, $timestamp, (string) $body),
                ])
                ->withBody((string) $body, 'application/json')
                ->post($webhook->url);
        } catch (ConnectionException $e) {
            $error = 'Connection failed: '.Str::limit($e->getMessage(), 300);
        } catch (Throwable $e) {
            $error = 'Request failed: '.Str::limit($e->getMessage(), 300);
        }

        $delivery->duration_ms = (int) round((hrtime(true) - $started) / 1_000_000);

        if ($response?->successful()) {
            $this->finish($delivery, $webhook, true, $response, null);

            return;
        }

        $retryable = $response === null || $response->serverError() || $response->status() === 429;
        $error ??= "HTTP {$response->status()}";

        if ($retryable && $this->canRetry()) {
            $this->record($delivery, $response, $error, WebhookDelivery::STATUS_PENDING);
            $this->release($this->backoff()[$this->attempts() - 1] ?? 600);

            return;
        }

        $this->finish($delivery, $webhook, false, $response, $error);
    }

    /** The value of X-Brainitive-Signature for a body sent at $timestamp. */
    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Called by the worker when the job itself crashed: the delivery must not stay "pending". */
    public function failed(?Throwable $e): void
    {
        WebhookDelivery::whereKey($this->deliveryId)->where('status', WebhookDelivery::STATUS_PENDING)
            ->update(['status' => WebhookDelivery::STATUS_FAILED, 'error' => Str::limit('Delivery crashed: '.$e?->getMessage(), 480)]);
    }

    private function canRetry(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries;
    }

    private function record(WebhookDelivery $delivery, ?Response $response, ?string $error, string $status): void
    {
        $delivery->fill([
            'status' => $status,
            'response_code' => $response?->status(),
            'response_body' => $response ? self::truncate($response->body()) : null,
            'error' => $error ? Str::limit($error, 480) : null,
        ])->save();
    }

    private function finish(WebhookDelivery $delivery, Webhook $webhook, bool $ok, ?Response $response, ?string $error): void
    {
        $this->record($delivery, $response, $error, $ok ? WebhookDelivery::STATUS_SUCCESS : WebhookDelivery::STATUS_FAILED);
        if ($ok) {
            $delivery->forceFill(['delivered_at' => now()])->save();
            Webhook::whereKey($webhook->id)->update(['failure_count' => 0, 'last_delivery_at' => now()]);

            return;
        }

        Webhook::whereKey($webhook->id)->increment('failure_count', 1, ['last_delivery_at' => now()]);
        $failures = (int) Webhook::whereKey($webhook->id)->value('failure_count');

        // Disable once, exactly when the threshold is crossed (the update only matches an active row)
        if ($failures >= Webhook::MAX_CONSECUTIVE_FAILURES
            && Webhook::whereKey($webhook->id)->where('active', true)->update(['active' => false, 'disabled_at' => now()]) === 1) {
            $webhook->refresh();
            $admins = $webhook->workspace?->admins()->get() ?? collect();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new WebhookDisabledNotification($webhook, $error));
            }
        }
    }

    private static function truncate(string $body): string
    {
        $body = mb_scrub($body, 'UTF-8');

        return strlen($body) > WebhookDelivery::RESPONSE_LIMIT ? mb_strcut($body, 0, WebhookDelivery::RESPONSE_LIMIT, 'UTF-8') : $body;
    }
}
