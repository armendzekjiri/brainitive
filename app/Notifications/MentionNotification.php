<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $contextType, // 'item' or 'page'
        public readonly string $contextTitle,
        public readonly string $contextUrl,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
