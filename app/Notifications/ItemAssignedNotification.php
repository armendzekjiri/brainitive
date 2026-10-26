<?php

namespace App\Notifications;

use App\Models\Item;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ItemAssignedNotification extends Notification
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly Item $item,
        public readonly User $actor,
        /** the automation rule that assigned (v1.41.2), shown instead of the person it acts as */
        public readonly ?string $automation = null,
    ) {}

    /** Who did it, as the text says it. */
    private function by(): string
    {
        return $this->automation !== null ? "Automation “{$this->automation}”" : $this->actor->name;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'item_assigned');
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $board = $this->item->board;
        $project = $board?->project;
        $workspace = $project?->workspace;
        $url = $workspace && $project && $board
            ? route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $this->item->id])
            : '/';

        return $this->webPush(
            "{$this->by()} assigned you an item",
            "{$this->item->key} · {$this->item->title}",
            $url,
        );
    }

    /**
     * Get the array representation of the notification (stored in DB).
     */
    public function toDatabase(object $notifiable): array
    {
        $board = $this->item->board;
        $project = $board?->project;
        $workspace = $project?->workspace;

        $url = $workspace && $project && $board
            ? route('boards.items.show', [
                $workspace->slug,
                $project->slug,
                $board->slug,
                $this->item->id,
            ])
            : '#';

        return [
            'type' => 'item_assigned',
            'item_key' => $this->item->key,
            'item_title' => $this->item->title,
            'item_url' => $url,
            'actor_name' => $this->by(),
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = NewLeadNotification::plain((string) $this->item->title);

        return (new MailMessage)
            ->subject("{$this->by()} assigned you to {$this->item->key}: {$title}")
            ->line("{$this->by()} assigned you to {$this->item->key}.")
            ->line($title)
            ->action('Open issue', $this->toDatabase($notifiable)['item_url']);
    }
}
