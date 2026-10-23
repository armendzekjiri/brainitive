<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
        public readonly User|TicketContact|null $author,
    ) {}

    /**
     * Get the notification's delivery channels.
     * Contact: email only (queued). Users: database + email + push.
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof TicketContact) {
            return ['mail'];
        }

        return $this->withWebPush($notifiable, ['database', 'mail']);
    }

    public function toWebPush(object $notifiable, $notification)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;
        $url = route('tickets.show', [$workspace->slug, $project->slug, $helpdesk->slug, $this->ticket->id]);

        $actorName = $this->author instanceof User
            ? $this->author->name
            : ($this->author instanceof TicketContact ? ($this->author->name ?: $this->author->email) : 'Support');

        return $this->webPush(
            "Reply on {$this->ticket->key}",
            "{$actorName}: ".Str::limit(strip_tags($this->reply->body), 80),
            $url,
        );
    }

    /**
     * Get the array representation for database (users only).
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

        $actorName = $this->author instanceof User
            ? $this->author->name
            : ($this->author instanceof TicketContact
                ? ($this->author->name ?: $this->author->email)
                : 'Support');

        return [
            'type' => 'ticket_reply',
            'ticket_key' => $this->ticket->key,
            'ticket_subject' => $this->ticket->subject,
            'ticket_url' => $url,
            'actor_name' => $actorName,
            'reply_preview' => Str::limit(strip_tags($this->reply->body), 100),
        ];
    }

    /**
     * Get the mail representation.
     */
    public function toMail(object $notifiable)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;

        if ($notifiable instanceof TicketContact) {
            // Client: link to this ticket in the portal
            $url = $this->ticket->publicUrl();
            $subject = "Reply on ticket {$this->ticket->key}: {$this->plainSubject()}";
            $line = 'Support has replied to your ticket.';

            return (new MailMessage)
                ->subject($subject)
                ->line($line)
                ->line('Ticket: '.$this->ticket->subject)
                ->line(Str::limit(strip_tags($this->reply->body), 200))
                ->action('View ticket', $url);
        }

        // User (watcher): link to internal ticket view
        $url = route('tickets.show', [
            $workspace->slug,
            $project->slug,
            $helpdesk->slug,
            $this->ticket->id,
        ]);
        $actorName = $this->author instanceof User
            ? $this->author->name
            : ($this->author instanceof TicketContact
                ? ($this->author->name ?: $this->author->email)
                : 'A user');
        $subject = "Reply on ticket {$this->ticket->key}: {$this->plainSubject()}";
        $line = "{$actorName} replied to this ticket.";

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->line(Str::limit(strip_tags($this->reply->body), 200))
            ->action('View ticket', $url);
    }

    /** The visitor-written subject as plain text: no Markdown links, images or formatting in mails. */
    private function plainSubject(): string
    {
        return NewLeadNotification::plain((string) $this->ticket->subject);
    }
}
