<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use App\Support\Mail\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences, SendsWebPush;

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
        // contacts: mail only (RespectsPreferences); users: their helpdesk preferences
        return $this->channelsFor($notifiable, 'ticket_reply');
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
     * Get the mail representation: the customer's copy (signed by the helpdesk, portal link) or a
     * watcher's (internal link).
     */
    public function toMail(object $notifiable)
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk->project;
        $workspace = $project->workspace;
        $excerpt = Str::limit(trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $this->reply->body)), ENT_QUOTES | ENT_HTML5)), 1000);

        if ($notifiable instanceof TicketContact) {
            // Client: link to this ticket in the portal
            $sender = BrandedMail::helpdeskName($helpdesk);

            return $this->brandedMail($notifiable, 'ticket_reply', "You get this email because you have a ticket with {$sender}.")
                ->subject("Reply on ticket {$this->ticket->key}: {$this->plainSubject()}")
                ->eyebrow("{$sender} · {$this->ticket->key}")
                ->headline('New reply on your ticket')
                ->line('Support has replied to your ticket.')
                ->line('Ticket: '.$this->plainSubject())
                ->quote($excerpt)
                ->action('View ticket', $this->ticket->publicUrl())
                ->context($sender)
                ->signOff($sender);
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

        return $this->brandedMail($notifiable, 'ticket_reply')
            ->subject("Reply on ticket {$this->ticket->key}: {$this->plainSubject()}")
            ->eyebrow("{$project->name} · {$helpdesk->name}")
            ->headline("New reply on {$this->ticket->key}")
            ->line(NewLeadNotification::plain((string) $actorName).' replied to this ticket.')
            ->quote($excerpt)
            ->context($workspace->name)
            ->action('View ticket', $url);
    }

    /** The visitor-written subject as plain text: no Markdown links, images or formatting in mails. */
    private function plainSubject(): string
    {
        return NewLeadNotification::plain((string) $this->ticket->subject);
    }
}
