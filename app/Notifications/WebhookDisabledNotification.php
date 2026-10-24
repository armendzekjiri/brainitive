<?php

namespace App\Notifications;

use App\Models\Webhook;
use Illuminate\Support\Str;

/**
 * To the workspace admins when a webhook was disabled after Webhook::MAX_CONSECUTIVE_FAILURES
 * failed deliveries in a row (v1.39.0, BRAI-104; category: workspace). Names only the host of the
 * URL, never its path or query (they may carry a token).
 */
class WebhookDisabledNotification extends CatalogNotification
{
    public const TYPE = 'webhook_disabled';

    public function __construct(public readonly Webhook $webhook, public readonly ?string $lastError = null) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        $workspace = $this->webhook->workspace;
        $host = parse_url($this->webhook->url, PHP_URL_HOST) ?: 'its URL';

        return [
            'title' => 'Webhook “'.Str::limit($this->webhook->name, 60).'” was disabled after '.Webhook::MAX_CONSECUTIVE_FAILURES.' failed deliveries',
            'body' => $host.($this->lastError ? ' · Last error: '.$this->lastError : ''),
            'url' => $workspace ? route('webhooks.index', $workspace->slug) : url('/'),
            'actor' => null,
            'project_name' => $workspace?->name,
            'subject_key' => null,
        ];
    }
}
