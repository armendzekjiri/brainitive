<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use App\Notifications\Concerns\SendsWebPush;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Throwable;

/**
 * Profile → Notifications → "Send test notification" (BRAI-154): one push, sent now (not queued),
 * to exactly one subscription, through the same WebPush client and message shape as real
 * notifications. Returns what the push service answered and stores it on the row.
 */
class PushTester
{
    use SendsWebPush;

    public function send(PushSubscription $subscription): PushOutcome
    {
        if (! WebPushClient::isConfigured()) {
            return new PushOutcome('unconfigured', null, 'Push notifications are not set up on this server (no VAPID keys).');
        }

        $label = $subscription->device_label ?: 'this device';
        $message = $this->webPush(
            'Brainitive test notification',
            "Notifications work on {$label}.",
            '/profile',
        )->tag('brainitive-test')->renotify();

        try {
            $client = WebPushClient::make(timeout: 10); // a request waits on it
        } catch (Throwable $e) {
            report($e);

            return new PushOutcome('unconfigured', null, 'The VAPID keys on this server are invalid.');
        }

        try {
            $report = $client->sendOneNotification(
                new Subscription(
                    $subscription->endpoint,
                    $subscription->public_key,
                    $subscription->auth_token,
                    $subscription->content_encoding ?? ContentEncoding::aes128gcm,
                ),
                json_encode($message->toArray()),
                $message->getOptions(),
            );
            $outcome = PushOutcome::fromReport($report);
        } catch (Throwable $e) {
            // bad stored keys (encryption) or a VAPID setup the library refuses — never the message: it can hold the endpoint
            report($e);
            $outcome = new PushOutcome('invalid', null, 'This subscription could not be encrypted. Register the device again.');
        }

        $outcome->record($subscription);

        return $outcome;
    }
}
