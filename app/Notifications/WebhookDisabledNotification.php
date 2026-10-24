<?php

namespace App\Notifications;

use App\Models\Webhook;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * To the workspace admins when a webhook was disabled after Webhook::MAX_CONSECUTIVE_FAILURES
 * failed deliveries in a row (v1.39.0, BRAI-104). Catalog type `webhook_disabled` (workspace).
 * Payload follows the v1.39.0 notification contract (type, title, body, url, …).
 */
class WebhookDisabledNotification extends Notification
{
    use Queueable, RespectsPreferences;

    public const TYPE = 'webhook_disabled';

    public function __construct(public readonly Webhook $webhook, public readonly ?string $lastError = null) {}

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, self::TYPE);
    }

    public function toDatabase(object $notifiable): array
    {
        $workspace = $this->webhook->workspace;
        $host = parse_url($this->webhook->url, PHP_URL_HOST) ?: $this->webhook->url;

        return [
            'type' => self::TYPE,
            'title' => 'Webhook “'.Str::limit($this->webhook->name, 60).'” was disabled after '.Webhook::MAX_CONSECUTIVE_FAILURES.' failed deliveries',
            'body' => Str::limit($host.($this->lastError ? ' · Last error: '.$this->lastError : ''), 200),
            'url' => $workspace ? route('webhooks.index', $workspace->slug) : url('/'),
            'actor_name' => null,
            'actor_avatar' => null,
            'project_name' => $workspace?->name,
            'subject_key' => null,
        ];
    }
}
