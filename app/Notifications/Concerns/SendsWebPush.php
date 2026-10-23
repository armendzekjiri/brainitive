<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Shared helpers for notifications that should also be delivered as a browser
 * push. Only adds the WebPush channel for real User notifiables (push
 * subscriptions live on the User model), never for TicketContacts.
 */
trait SendsWebPush
{
    /** Append the WebPush channel when $notifiable can receive pushes. */
    protected function withWebPush(object $notifiable, array $channels): array
    {
        if ($notifiable instanceof User) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /** Build a WebPushMessage with our app's icon/badge and a click-through URL. */
    protected function webPush(string $title, string $body, string $url): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/apple-touch-icon.png')
            ->badge('/favicon-32x32.png')
            ->data(['url' => $url])
            ->options(['TTL' => 1800]);
    }
}
