<?php

namespace App\Notifications;

use App\Models\Page;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PageUpdatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Page $page,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $workspace = $this->page->project?->workspace;
        $project   = $this->page->project;

        $url = $workspace && $project
            ? route('pages.show', [$workspace->slug, $project->slug, $this->page->uuid])
            : '#';

        return [
            'type'         => 'page_updated',
            'page_name'    => $this->page->name,
            'page_url'     => $url,
            'actor_name'   => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }
}
