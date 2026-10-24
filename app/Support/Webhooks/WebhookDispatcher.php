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
 * deliveries per request are sent that way (a bulk action would otherwise keep the PHP worker busy);
 * the rest are logged as failed, ready to redeliver. With a real queue every delivery is queued and
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
            dispatch($job)->afterResponse();
        } else {
            // a bulk action on a sync queue: don't keep the PHP worker busy with hundreds of POSTs
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'error' => 'Not sent: more than '.self::MAX_AFTER_RESPONSE.' deliveries in one request without a queue worker. Use Redeliver.',
            ]);
        }

        return $delivery;
    }

    /** Deliveries one web request may send after its response on a sync queue. */
    public const MAX_AFTER_RESPONSE = 25;

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
