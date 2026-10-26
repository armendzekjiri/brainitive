<?php

namespace App\Notifications;

use App\Models\Page;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageUpdatedNotification extends Notification
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly Page $page,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'page_updated');
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $workspace = $this->page->project?->workspace;
        $project = $this->page->project;
        $url = $workspace && $project
            ? route('pages.show', [$workspace->slug, $project->slug, $this->page->uuid])
            : '/';

        return $this->webPush(
            "Page updated: {$this->page->name}",
            "{$this->actor->name} edited this page",
            $url,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $workspace = $this->page->project?->workspace;
        $project = $this->page->project;

        $url = $workspace && $project
            ? route('pages.show', [$workspace->slug, $project->slug, $this->page->uuid])
            : '#';

        return [
            'type' => 'page_updated',
            'page_name' => $this->page->name,
            'page_url' => $url,
            'actor_name' => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = NewLeadNotification::plain((string) $this->page->name);

        return $this->brandedMail($notifiable, 'page_updated')
            ->subject("Page updated: {$name}")
            ->eyebrow($this->page->project?->name)
            ->headline("Page updated: {$name}")
            ->line("{$this->actor->name} updated the page {$name}.")
            ->action('Open page', $this->toDatabase($notifiable)['page_url']);
    }
}
