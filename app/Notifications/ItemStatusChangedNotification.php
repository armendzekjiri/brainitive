<?php

namespace App\Notifications;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ItemStatusChangedNotification extends Notification
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly Item $item,
        public readonly BoardColumn $oldColumn,
        public readonly BoardColumn $newColumn,
        public readonly User $actor,
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'item_status_changed');
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
            "{$this->item->key} moved to {$this->newColumn->name}",
            "{$this->actor->name} moved \"{$this->item->title}\" from {$this->oldColumn->name}",
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
            'type' => 'item_status_changed',
            'item_key' => $this->item->key,
            'item_title' => $this->item->title,
            'item_url' => $url,
            'old_column' => $this->oldColumn->name,
            'new_column' => $this->newColumn->name,
            'actor_name' => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $board = $this->item->board;

        return $this->brandedMail($notifiable, 'item_status_changed')
            ->subject("{$this->item->key} moved to {$this->newColumn->name}")
            ->eyebrow($board ? trim(($board->project?->name ? $board->project->name.' · ' : '').$board->name) : null)
            ->headline("{$this->item->key} moved to {$this->newColumn->name}")
            ->line("{$this->actor->name} moved {$this->item->key} from {$this->oldColumn->name} to {$this->newColumn->name}.")
            ->line(NewLeadNotification::plain((string) $this->item->title))
            ->action('Open issue', $this->toDatabase($notifiable)['item_url']);
    }
}
