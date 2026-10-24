<?php

namespace Tests\Feature\Webhooks;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;

/**
 * Webhooks on top of InteractsWithBoards: the workspace owner is the admin.
 * Public IP literals (93.184.216.34) pass OutboundUrl without DNS; Http::fake() answers them.
 */
trait InteractsWithWebhooks
{
    protected const HOOK_URL = 'https://93.184.216.34/hook';

    protected function admin(): User
    {
        return User::findOrFail($this->workspace->owner_id);
    }

    /**
     * @param  list<string>  $events
     */
    protected function hook(array $events = ['*'], array $attributes = [], ?Workspace $workspace = null): Webhook
    {
        return Webhook::create($attributes + [
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'name' => 'Receiver',
            'url' => self::HOOK_URL,
            'secret' => 'whsec_test',
            'events' => $events,
            'active' => true,
        ]);
    }

    /** @return list<array> envelopes delivered for $event, oldest first */
    protected function envelopes(string $event, ?Webhook $webhook = null): array
    {
        return WebhookDelivery::where('event', $event)
            ->when($webhook, fn ($q) => $q->where('webhook_id', $webhook->id))
            ->orderBy('id')->pluck('payload')->all();
    }

    protected function webhookRoute(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, ...$extra]);
    }
}
