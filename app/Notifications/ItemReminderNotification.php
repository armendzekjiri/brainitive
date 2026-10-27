<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly string $itemKey,
        public readonly string $itemTitle,
        public readonly string $whenLabel,
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'item_reminder');
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

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail($notifiable, 'item_reminder')
            ->subject("{$this->itemKey} {$this->whenLabel}")
            ->headline("{$this->itemKey} {$this->whenLabel}")
            ->line(NewLeadNotification::plain($this->itemTitle))
            ->action('Open', url($this->url));
    }
}
