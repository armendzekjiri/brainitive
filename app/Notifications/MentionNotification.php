<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly string $contextType, // 'item' or 'page'
        public readonly string $contextTitle,
        public readonly string $contextUrl,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withWebPush($notifiable, ['database']);
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush(
            "{$this->actor->name} mentioned you",
            "In {$this->contextType}: {$this->contextTitle}",
            $this->contextUrl,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'           => 'mentioned',
            'context_type'   => $this->contextType,
            'context_title'  => $this->contextTitle,
            'context_url'    => $this->contextUrl,
            'actor_name'     => $this->actor->name,
            'actor_avatar'   => $this->actor->avatar_display,
        ];
    }
}
