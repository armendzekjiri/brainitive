<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketContact|User|null $source = null,
        public readonly ?string $contactAccessToken = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        // For contacts, send email only; for users, send database + email
        if ($notifiable instanceof \App\Models\TicketContact) {
            return ['mail'];
        }
        return ['database', 'mail'];
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
            'type'         => 'ticket_created',
            'ticket_key'   => $this->ticket->key,
            'ticket_subject' => $this->ticket->subject,
            'ticket_url'   => $url,
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
        if ($notifiable instanceof \App\Models\TicketContact) {
            $token = $this->contactAccessToken ?? $notifiable->generateAccessToken();
            $url = route('helpdesks.tickets.show-public', $this->ticket->uuid) . '?token=' . urlencode($token);
            $subject = "Ticket {$this->ticket->key} created: {$this->ticket->subject}";
            $line = "Your ticket has been created and we'll get back to you soon.";
            $action = 'View Ticket';
        } else {
            $url = route('tickets.show', [
                $workspace->slug,
                $project->slug,
                $helpdesk->slug,
                $this->ticket->id,
            ]);
            $subject = "New ticket {$this->ticket->key}: {$this->ticket->subject}";
            $sourceName = $this->source instanceof User 
                ? $this->source->name 
                : ($this->source instanceof TicketContact 
                    ? ($this->source->name ?: $this->source->email) 
                    : 'A user');
            $line = "{$sourceName} created a new ticket.";
            $action = 'Review Ticket';
        }

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject($subject)
            ->line($line)
            ->line($this->ticket->subject)
            ->action($action, $url);
    }
}