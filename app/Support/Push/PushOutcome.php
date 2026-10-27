<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Psr\Http\Message\ResponseInterface;

/**
 * What a push service answered for one subscription (BRAI-154), in the terms Profile shows:
 *
 * - delivered    2xx: the push service accepted it (the device may still hide it — OS settings)
 * - expired      404 / 410: the browser dropped the subscription; it never comes back
 * - rejected     other 4xx / 5xx: VAPID, payload or service problem (`reason` says which)
 * - network      no answer at all
 * - unconfigured the server has no VAPID keys
 * - invalid      the stored subscription keys can't encrypt a message
 *
 * `reason` is short and never contains the endpoint, keys or a URL.
 */
final class PushOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $httpStatus = null,
        public readonly ?string $reason = null,
    ) {}

    public static function fromReport(MessageSentReport $report): self
    {
        $response = $report->getResponse();

        if ($report->isSuccess()) {
            return new self('delivered', $response?->getStatusCode());
        }
        if (! $response) {
            return new self('network', null, 'The push service could not be reached.');
        }
        if ($report->isSubscriptionExpired()) {
            return new self('expired', $response->getStatusCode(), 'The push service no longer knows this subscription.');
        }

        return new self('rejected', $response->getStatusCode(), self::describe($response));
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    /** One line for `push_subscriptions.last_error`. */
    public function summary(): string
    {
        $prefix = $this->httpStatus ? "HTTP {$this->httpStatus}" : ucfirst($this->status);

        return mb_substr($this->reason ? "{$prefix}: {$this->reason}" : $prefix, 0, 255);
    }

    /** Store the answer on the subscription: success / error time, and flag it when it expired. */
    public function record(PushSubscription $subscription): void
    {
        if (! $subscription->exists || $this->status === 'unconfigured') {
            return;
        }

        $now = now();
        $subscription->last_used_at = $now;
        if ($this->isDelivered()) {
            $subscription->last_success_at = $now;
        } else {
            $subscription->last_error = $this->summary();
            $subscription->last_error_at = $now;
        }
        if ($this->status === 'expired') {
            $subscription->expired_at ??= $now;
        }
        $subscription->saveQuietly();
    }

    /** @return array{status: string, http_status: int|null, reason: string|null} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'http_status' => $this->httpStatus, 'reason' => $this->reason];
    }

    /** The push service's own words (FCM text, Apple `reason`, Mozilla `message`), trimmed and URL-free. */
    private static function describe(ResponseInterface $response): string
    {
        $body = trim(mb_substr((string) $response->getBody(), 0, 2000));
        $json = json_decode($body, true);
        if (is_array($json)) {
            $error = $json['error'] ?? null;
            $body = (string) ($json['reason'] ?? $json['message'] ?? (is_array($error) ? ($error['message'] ?? $error['status'] ?? '') : ($error ?? '')));
        } elseif (str_contains($body, '<')) {
            $body = strip_tags($body); // HTML error pages
        }

        $body = preg_replace('~\b[a-z][a-z0-9+.-]*://\S+~i', '[url]', $body) ?? '';
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        $text = $body !== '' ? $body : $response->getReasonPhrase();

        return mb_substr($text !== '' ? $text : 'Refused by the push service.', 0, 160);
    }
}
