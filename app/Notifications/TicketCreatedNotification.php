<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Support\Mail\BrandedMail;
use Illuminate\Bus\Queueable;
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
     * Get the mail representation of the notification: the customer's confirmation (signed by the
     * helpdesk) or the team's "new ticket" mail.
     */
    public function toMail(object $notifiable)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;

        // Different URL for contacts vs users (use token passed from controller so we don't invalidate the one returned in API)
        if ($notifiable instanceof TicketContact) {
            $sender = BrandedMail::helpdeskName($helpdesk);

            return $this->brandedMail($notifiable, 'ticket_created', "You get this email because you contacted {$sender}.")
                ->subject("Ticket {$this->ticket->key} created: {$this->plainSubject()}")
                ->eyebrow($sender)
                ->headline("We received your ticket {$this->ticket->key}")
                ->line("Your ticket has been created and we'll get back to you soon.")
                ->quote($this->plainSubject())
                ->action('View ticket', $this->ticket->publicUrl())
                ->context($sender)
                ->signOff($sender);
        }

        $url = route('tickets.show', [
            $workspace->slug,
            $project->slug,
            $helpdesk->slug,
            $this->ticket->id,
        ]);
        $sourceName = $this->source instanceof User
            ? $this->source->name
            : ($this->source instanceof TicketContact
                ? ($this->source->name ?: $this->source->email)
                : 'A user');

        return $this->brandedMail($notifiable, 'ticket_created')
            ->subject("New ticket {$this->ticket->key}: {$this->plainSubject()}")
            ->eyebrow("{$project->name} · {$helpdesk->name}")
            ->headline("New ticket {$this->ticket->key}")
            ->line(NewLeadNotification::plain((string) $sourceName).' created a new ticket.')
            ->quote($this->plainSubject())
            ->context($workspace->name)
            ->action('Review ticket', $url);
    }

    /** The visitor-written subject as plain text: no Markdown links, images or formatting in mails. */
    private function plainSubject(): string
    {
        return NewLeadNotification::plain((string) $this->ticket->subject);
    }
}
