<?php

namespace App\Support\Push;

use App\Models\PushSubscription as AppPushSubscription;
use Illuminate\Contracts\Events\Dispatcher;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Replaces the package's ReportHandler for every real notification push (BRAI-154, bound in
 * AppServiceProvider). Same events, but instead of deleting an expired (404 / 410) subscription
 * it records the answer on the row (`PushOutcome::record()`): success time, last error, and an
 * `expired_at` flag so Profile → Notifications can say which device stopped receiving.
 */
class PushReportHandler implements ReportHandlerInterface
{
    public function __construct(protected Dispatcher $events) {}

    public function handleReport(MessageSentReport $report, PushSubscription $subscription, WebPushMessageInterface $message): void
    {
        $outcome = PushOutcome::fromReport($report);

        if ($subscription instanceof AppPushSubscription) {
            $outcome->record($subscription);
        } elseif ($outcome->status === 'expired') {
            $subscription->delete(); // a foreign model class: keep the package's behaviour
        }

        $this->events->dispatch($outcome->isDelivered()
            ? new NotificationSent($report, $subscription, $message)
            : new NotificationFailed($report, $subscription, $message));
    }
}
