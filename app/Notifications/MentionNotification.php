<?php

namespace App\Notifications;

use App\Models\Item;
use App\Models\Page;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Notifications\Contracts\DigestibleMail;
use App\Support\Notifications\DigestEntry;
use App\Support\Notifications\MailDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionNotification extends Notification implements DigestibleMail
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly string $contextType, // 'item' or 'page'
        public readonly string $contextTitle,
        public readonly string $contextUrl,
        public readonly User $actor,
        /** the issue or page the mention is in (BRAI-429: access is checked again for the email digest) */
        public readonly ?Model $subject = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'mentioned');
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
            'type' => 'mentioned',
            'context_type' => $this->contextType,
            'context_title' => $this->contextTitle,
            'context_url' => $this->contextUrl,
            'actor_name' => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $where = $this->contextType === 'page' ? 'on a page' : 'in an issue';

        return $this->brandedMail($notifiable, 'mentioned')
            ->subject("{$this->actor->name} mentioned you: ".NewLeadNotification::plain($this->contextTitle))
            ->headline("{$this->actor->name} mentioned you {$where}")
            ->line(NewLeadNotification::plain($this->contextTitle))
            ->action('Open', url($this->contextUrl));
    }

    /** The line in the notification email digest (BRAI-429); only with a subject (issue / page). */
    public function digestEntry(object $notifiable): ?DigestEntry
    {
        $workspace = $this->subject ? MailDigest::workspaceOf($this->subject) : null;
        if (! $this->subject || ! $workspace) {
            return null;
        }
        $where = $this->contextType === 'page' ? 'on a page' : 'in an issue';
        $title = NewLeadNotification::plain($this->contextTitle);
        $context = match (true) {
            $this->subject instanceof Item => $this->subject->board ? trim(($this->subject->board->project?->name ? $this->subject->board->project->name.' · ' : '').$this->subject->board->name) : null,
            $this->subject instanceof Page => $this->subject->project?->name,
            default => null,
        };

        return new DigestEntry(
            type: 'mentioned',
            workspace: $workspace,
            subject: $this->subject,
            title: "{$this->actor->name} mentioned you {$where}",
            url: url($this->contextUrl),
            mailSubject: "{$this->actor->name} mentioned you: {$title}",
            subjectTitle: $title,
            context: $context,
            action: 'Open',
        );
    }
}
