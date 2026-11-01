<?php

namespace App\Notifications;

use App\Models\Item;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Notifications\Contracts\DigestibleMail;
use App\Support\Notifications\DigestEntry;
use App\Support\Notifications\MailDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ItemAssignedNotification extends Notification implements DigestibleMail
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
        $board = $this->item->board;

        return $this->brandedMail($notifiable, 'item_assigned')
            ->subject("{$this->by()} assigned you to {$this->item->key}: {$title}")
            ->eyebrow($board ? trim(($board->project?->name ? $board->project->name.' · ' : '').$board->name) : null)
            ->headline("{$this->by()} assigned you to {$this->item->key}")
            ->line($title)
            ->action('Open issue', $this->toDatabase($notifiable)['item_url']);
    }

    /** The line in the notification email digest (BRAI-429). */
    public function digestEntry(object $notifiable): ?DigestEntry
    {
        $workspace = MailDigest::workspaceOf($this->item);
        if (! $workspace) {
            return null;
        }
        $title = NewLeadNotification::plain((string) $this->item->title);
        $board = $this->item->board;

        return new DigestEntry(
            type: 'item_assigned',
            workspace: $workspace,
            subject: $this->item,
            title: "{$this->by()} assigned you to {$this->item->key}",
            url: $this->toDatabase($notifiable)['item_url'],
            mailSubject: "{$this->by()} assigned you to {$this->item->key}: {$title}",
            subjectKey: $this->item->key,
            subjectTitle: $title,
            context: $board ? trim(($board->project?->name ? $board->project->name.' · ' : '').$board->name) : null,
            action: 'Open issue',
        );
    }
}
