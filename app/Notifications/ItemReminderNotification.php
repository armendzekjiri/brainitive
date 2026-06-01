<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Reminds the assignee that a board item / task-list item shown on their
 * calendar is about to start. Delivered to BOTH the in-app bell (database) and
 * the browser (web push) so it surfaces even before a push subscription exists.
 *
 * The command computes the human-facing strings and deep link, so this class
 * stays agnostic of whether the source is a board item or a task-list item.
 */
class ItemReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly string $itemKey,
        public readonly string $itemTitle,
        public readonly string $whenLabel,
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withWebPush($notifiable, ['database']);
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush(
            "{$this->itemKey} · {$this->itemTitle}",
            $this->whenLabel,
            $this->url,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'item_reminder',
            'item_key' => $this->itemKey,
            'item_title' => $this->itemTitle,
            'item_url' => $this->url,
            'when_label' => $this->whenLabel,
        ];
    }
}
