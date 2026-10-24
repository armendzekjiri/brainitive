<?php

namespace App\Support\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;

/**
 * Records a delivery and hands it to DeliverWebhook (BRAI-104).
 *
 * Sync queue (dev.brainitive.app, the e2e server, small self-hosted installs): a delivery caused
 * by a user's action runs AFTER the response was sent (dispatch()->afterResponse()), so a slow or
 * dead endpoint never slows down or breaks that request. It gets one attempt there (a sync job can't
 * be released for a retry); "Redeliver" in the UI sends it again. Manual sends (Send test,
 * Redeliver) run inline on sync so the admin sees the result at once. At most MAX_AFTER_RESPONSE
 * deliveries and AFTER_RESPONSE_BUDGET seconds per request are spent that way, with a shorter
 * timeout per attempt (a bulk action or slow receivers would otherwise hold the PHP worker); the
 * rest are logged as failed "Not sent", ready to redeliver. With a real queue every delivery is queued and
 * retried with backoff.
 */
final class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $envelope  DomainEvents::envelope()
     */
    public static function queue(Webhook $webhook, array $envelope, bool $manual = false): WebhookDelivery
    {
        $delivery = $webhook->deliveries()->create([
            'event' => $envelope['event'],
            'event_id' => $envelope['id'],
            'payload' => $envelope,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $job = new DeliverWebhook($delivery->id, $manual);

        if (! self::syncQueue()) {
            dispatch($job);
        } elseif ($manual || app()->runningInConsole()) {
            dispatch_sync($job);
        } elseif (self::takeAfterResponseSlot()) {
            $job->afterResponse = true;
            dispatch($job)->afterResponse();
        } else {
            // a bulk action on a sync queue: don't keep the PHP worker busy with hundreds of POSTs
            self::notSent($delivery, 'more than '.self::MAX_AFTER_RESPONSE.' deliveries in one request without a queue worker');
        }

        return $delivery;
    }

    /** Deliveries one web request may send after its response on a sync queue. */
    public const MAX_AFTER_RESPONSE = 25;

    /** Seconds one web request may spend on them in total (slow receivers can't hold the worker). */
    public const AFTER_RESPONSE_BUDGET = 15;

    /** Per-attempt timeout after the response (a queue worker uses config('webhooks.timeout')). */
    public const AFTER_RESPONSE_TIMEOUT = 5;

    /**
     * Seconds left of this request's after-response budget; the clock starts with the first
     * delivery sent after the response.
     */
    public static function afterResponseSecondsLeft(): float
    {
        $key = 'webhooks.after-response-deadline';
        if (! app()->bound($key)) {
            app()->instance($key, hrtime(true) + self::AFTER_RESPONSE_BUDGET * 1_000_000_000);
        }

        return max(0, (app($key) - hrtime(true)) / 1_000_000_000);
    }

    /** Logs a delivery as failed without sending it; it doesn't count towards auto-disable. */
    public static function notSent(WebhookDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => "Not sent: {$reason}. Use Redeliver.",
        ]);
    }

    private static function takeAfterResponseSlot(): bool
    {
        $key = 'webhooks.after-response-deliveries'; // per request: the container is rebuilt for each one
        $used = app()->bound($key) ? (int) app($key) : 0;
        app()->instance($key, $used + 1);

        return $used < self::MAX_AFTER_RESPONSE;
    }

    public static function syncQueue(): bool
    {
        $connection = (string) config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }
}
