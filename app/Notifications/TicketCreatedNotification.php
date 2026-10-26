<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Support\Legal\PublicOperator;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketCreatedNotification extends Notification
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketContact|User|null $source = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        // contacts: mail only (RespectsPreferences); users: their helpdesk preferences
        return $this->channelsFor($notifiable, 'ticket_created');
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;
        $url = route('tickets.show', [$workspace->slug, $project->slug, $helpdesk->slug, $this->ticket->id]);

        $sourceName = $this->source instanceof User
            ? $this->source->name
            : ($this->source instanceof TicketContact ? ($this->source->name ?: $this->source->email) : 'Someone');

        return $this->webPush(
            "New ticket {$this->ticket->key}",
            "{$sourceName}: {$this->plainSubject()}",
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

        $data = [
            'type' => 'ticket_created',
            'ticket_key' => $this->ticket->key,
            'ticket_subject' => $this->ticket->subject,
            'ticket_url' => $url,
        ];

        if ($this->source instanceof User) {
            $data['actor_name'] = $this->source->name;
            $data['actor_avatar'] = $this->source->avatar_display;
        } elseif ($this->source instanceof TicketContact) {
            $data['contact_name'] = $this->source->name ?: $this->source->email;
            $data['contact_email'] = $this->source->email;
        }

        return $data;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;

        // Different URL for contacts vs users (use token passed from controller so we don't invalidate the one returned in API)
        if ($notifiable instanceof TicketContact) {
            $url = $this->ticket->publicUrl();
            $subject = "Ticket {$this->ticket->key} created: {$this->plainSubject()}";
            $line = "Your ticket has been created and we'll get back to you soon.";
            $action = 'View Ticket';
        } else {
            $url = route('tickets.show', [
                $workspace->slug,
                $project->slug,
                $helpdesk->slug,
                $this->ticket->id,
            ]);
            $subject = "New ticket {$this->ticket->key}: {$this->plainSubject()}";
            $sourceName = $this->source instanceof User
                ? $this->source->name
                : ($this->source instanceof TicketContact
                    ? ($this->source->name ?: $this->source->email)
                    : 'A user');
            $line = "{$sourceName} created a new ticket.";
            $action = 'Review Ticket';
        }

        $mail = (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->line($this->plainSubject())
            ->action($action, $url);

        // the contact learns who runs the helpdesk (BRAI-156)
        return $notifiable instanceof TicketContact ? $mail->line(PublicOperator::mailLine($workspace, nameFallback: true)) : $mail;
    }

    /** The visitor-written subject as plain text: no Markdown links, images or formatting in mails. */
    private function plainSubject(): string
    {
        return NewLeadNotification::plain((string) $this->ticket->subject);
    }
}
