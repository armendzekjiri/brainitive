<?php

namespace App\Notifications;

use App\Models\Item;
use App\Models\User;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ItemAssignedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly Item $item,
        public readonly User $actor,
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return $this->withWebPush($notifiable, ['database']);
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $board  = $this->item->board;
        $project = $board?->project;
        $workspace = $project?->workspace;
        $url = $workspace && $project && $board
            ? route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $this->item->id])
            : '/';

        return $this->webPush(
            "{$this->actor->name} assigned you an item",
            "{$this->item->key} · {$this->item->title}",
            $url,
        );
    }

    /**
     * Get the array representation of the notification (stored in DB).
     */
    public function toDatabase(object $notifiable): array
    {
        $board  = $this->item->board;
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
            'type'         => 'item_assigned',
            'item_key'     => $this->item->key,
            'item_title'   => $this->item->title,
            'item_url'     => $url,
            'actor_name'   => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }
}
