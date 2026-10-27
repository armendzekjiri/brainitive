<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Mail\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the customer their ticket was resolved, with the agent's closing message and the
 * ticket link (they can still read and reply there).
 */
class TicketResolvedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sender = BrandedMail::helpdeskName($this->ticket->helpdesk);
        $mail = BrandedMailMessage::guest($notifiable, "You get this email because you have a ticket with {$sender}.")
            ->subject("Resolved: {$this->plainSubject()} [{$this->ticket->key}]")
            ->eyebrow("{$sender} · {$this->ticket->key}")
            ->headline('Your ticket is resolved')
            ->line("Your ticket {$this->ticket->key} has been resolved.")
            ->context($sender)
            ->signOff($sender);

        $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $this->message))));
        foreach (array_filter(preg_split('/\n{2,}|\r\n\r\n/', $text) ?: []) as $paragraph) {
            $mail->line(Str::limit(trim($paragraph), 2000));
        }

        return $mail
            ->action('View ticket', $this->ticket->publicUrl())
            ->line('If something is still not right, open the ticket and write us a reply.');
    }

    /** The visitor-written subject as plain text: no Markdown links, images or formatting in mails. */
    private function plainSubject(): string
    {
        return NewLeadNotification::plain((string) $this->ticket->subject);
    }
}
