<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly Ticket $ticket,
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
        return $this->channelsFor($notifiable, 'ticket_assigned');
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;
        $url = route('tickets.show', [$workspace->slug, $project->slug, $helpdesk->slug, $this->ticket->id]);

        return $this->webPush(
            "Ticket {$this->ticket->key} assigned to you",
            "{$this->by()}: {$this->ticket->subject}",
            $url,
        );
    }

    /**
     * Get the array representation of the notification (stored in DB).
     */
    public function toDatabase(object $notifiable): array
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;

        $url = route('tickets.show', [
            $workspace->slug,
            $project->slug,
            $helpdesk->slug,
            $this->ticket->id,
        ]);

        return [
            'type' => 'ticket_assigned',
            'ticket_key' => $this->ticket->key,
            'ticket_subject' => $this->ticket->subject,
            'ticket_url' => $url,
            'actor_name' => $this->by(),
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;

        $url = route('tickets.show', [
            $workspace->slug,
            $project->slug,
            $helpdesk->slug,
            $this->ticket->id,
        ]);

        return (new MailMessage)
            ->subject("Ticket {$this->ticket->key} assigned to you: ".NewLeadNotification::plain((string) $this->ticket->subject))
            ->line("{$this->by()} assigned you to ticket {$this->ticket->key}.")
            ->line(NewLeadNotification::plain((string) $this->ticket->subject))
            ->action('View Ticket', $url);
    }
}
