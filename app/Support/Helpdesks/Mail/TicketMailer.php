<?php

namespace App\Support\Helpdesks\Mail;

use App\Jobs\SendTicketEmail;
use App\Models\Helpdesk;
use App\Models\HelpdeskMailbox;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use App\Support\Helpdesks\Inbound\TicketMailThread;
use App\Support\Mail\BrandedMail;
use App\Support\Webhooks\WebhookDispatcher;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Outgoing ticket email of a helpdesk with a connected mailbox (v1.40.0, BRAI-79).
 *
 * - Agent public replies go to the contact (+ the ticket's Cc participants) as a real email in the
 *   thread: a stable Message-ID stored on the reply (ticket_replies.email_message_id, and in
 *   ticket_email_messages), In-Reply-To / References from the thread, the routing tag in the subject,
 *   Reply-To the mailbox. Internal notes never send. The caller then skips TicketReplyNotification
 *   for the contact, so nothing is sent twice.
 * - Email-created tickets get a threaded acknowledgment (Auto-Submitted: auto-replied) instead of
 *   TicketCreatedNotification.
 * - Every mail carries X-Brainitive-Loop, so a copy that comes back is ignored (InboundFilters).
 *
 * Sending is a queued job (SendTicketEmail); a failed reply shows in the ticket with "Retry".
 * Without an enabled mailbox nothing changes: the usual notifications (link to the portal) go out.
 */
final class TicketMailer
{
    public const LOOP_HEADER = 'X-Brainitive-Loop';

    /** The enabled mailbox of the helpdesk, if any. */
    public static function mailbox(Helpdesk $helpdesk): ?HelpdeskMailbox
    {
        $mailbox = $helpdesk->relationLoaded('mailbox') ? $helpdesk->mailbox : $helpdesk->mailbox()->first();

        return $mailbox && $mailbox->enabled ? $mailbox : null;
    }

    /**
     * The address customers write to (lower-cased), or null. The single place that decides where
     * ticket email comes from — the forwarding address (ticket 4) can be added here.
     */
    public static function replyAddress(Helpdesk $helpdesk): ?string
    {
        return self::mailbox($helpdesk)?->address();
    }

    /** true = the reply goes out by email (the caller must not send TicketReplyNotification to the contact). */
    public static function queueReply(Ticket $ticket, TicketReply $reply): bool
    {
        if ($reply->is_internal_note || ! $ticket->contact || ! ($mailbox = self::mailbox($ticket->helpdesk))) {
            return false;
        }

        $messageId = self::newMessageId($mailbox);
        $reply->forceFill(['email_message_id' => $messageId, 'email_status' => 'sending', 'email_error' => null])->save();
        TicketEmailMessage::record($ticket, $messageId, 'out', $reply);

        self::dispatch(new SendTicketEmail($ticket->id, $reply->id, $messageId));

        return true;
    }

    /** Send a failed reply again (same Message-ID: clients that got it already drop the copy). */
    public static function retryReply(Ticket $ticket, TicketReply $reply): bool
    {
        if ($reply->is_internal_note || ! $reply->email_message_id || ! $ticket->contact || ! self::mailbox($ticket->helpdesk)) {
            return false;
        }

        $reply->forceFill(['email_status' => 'sending', 'email_error' => null])->save();
        self::dispatch(new SendTicketEmail($ticket->id, $reply->id, $reply->email_message_id), manual: true);

        return true;
    }

    /** true = the threaded acknowledgment is on its way (instead of TicketCreatedNotification). */
    public static function queueAcknowledgment(Ticket $ticket): bool
    {
        if (! $ticket->contact || ! ($mailbox = self::mailbox($ticket->helpdesk))) {
            return false;
        }

        $messageId = self::newMessageId($mailbox);
        TicketEmailMessage::record($ticket, $messageId, 'out');
        self::dispatch(new SendTicketEmail($ticket->id, null, $messageId));

        return true;
    }

    /** Build and send one mail (the job calls this). Throws on failure. */
    public static function send(Ticket $ticket, ?TicketReply $reply, string $messageId): void
    {
        $ticket->loadMissing(['helpdesk.mailbox', 'contact']);
        $mailbox = self::mailbox($ticket->helpdesk);
        if (! $mailbox || ! $ticket->contact) {
            throw new MailboxException('The helpdesk has no connected mailbox any more.');
        }

        $email = $reply ? self::replyEmail($mailbox, $ticket, $reply) : self::acknowledgmentEmail($mailbox, $ticket);
        self::thread($email, $mailbox, $ticket, $messageId);

        app(MailTransports::class)->forMailbox($mailbox)->send($email);
    }

    /**
     * The mail as it would go out (body + subject, no addresses, threading or sending) — for the admin
     * email previews (BRAI-155).
     */
    public static function compose(Ticket $ticket, ?TicketReply $reply): Email
    {
        $mailbox = new HelpdeskMailbox;
        $email = $reply ? self::replyEmail($mailbox, $ticket, $reply) : self::acknowledgmentEmail($mailbox, $ticket);

        return $email->subject(TicketMailThread::replySubject($ticket));
    }

    private static function replyEmail(HelpdeskMailbox $mailbox, Ticket $ticket, TicketReply $reply): Email
    {
        $body = (string) $reply->body;
        $plain = self::plain($body);

        $email = (new Email)
            ->html(self::branded($ticket, $body, Str::limit(preg_replace('/\s+/u', ' ', $plain) ?? '', 140)))
            ->text($plain."\n\n--\n{$ticket->key} · View the ticket online: {$ticket->publicUrl()}\nReply to this email to answer.");

        foreach (TicketMailThread::participants($ticket) as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $email->addCc($cc);
            }
        }

        return $email;
    }

    private static function acknowledgmentEmail(HelpdeskMailbox $mailbox, Ticket $ticket): Email
    {
        $name = $ticket->contact->name ? 'Hi '.$ticket->contact->name.',' : 'Hi,';
        $lines = [
            $name,
            "we received your message and opened ticket {$ticket->key}. We'll get back to you soon.",
            'Reply to this email to add anything — it goes to the same ticket.',
        ];

        $email = (new Email)
            ->html(self::branded($ticket, implode('', array_map(fn ($line) => '<p>'.e($line).'</p>', array_slice($lines, 1))), $lines[1], BrandedMail::greeting($ticket->contact->name)))
            ->text(implode("\n\n", $lines)."\n\nView the ticket online: ".$ticket->publicUrl());

        // RFC 3834: an automatic answer, so the customer's autoresponder doesn't answer it
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');

        return $email;
    }

    /** The HTML part on the branded mail layout (BRAI-155), signed by the helpdesk; $bodyHtml is trusted (stored, sanitised). */
    private static function branded(Ticket $ticket, string $bodyHtml, string $preheader, ?string $greeting = null): string
    {
        return (string) app(Markdown::class)->render('emails.helpdesk.ticket-message', [
            'sender' => BrandedMail::helpdeskName($ticket->helpdesk),
            'ticketKey' => (string) $ticket->key,
            'ticketUrl' => $ticket->publicUrl(),
            'bodyHtml' => $bodyHtml,
            'greeting' => $greeting,
            'preheader' => $preheader,
            'signOff' => $greeting !== null, // an agent's reply carries their own sign-off
        ]);
    }

    /** From / To / Reply-To / subject and the threading headers. */
    private static function thread(Email $email, HelpdeskMailbox $mailbox, Ticket $ticket, string $messageId): void
    {
        $name = Str::limit(str_replace(["\r", "\n"], ' ', $mailbox->from_name ?: $ticket->helpdesk->name), 100, '');

        // Own SMTP: from the mailbox address. App mailer: from the app's address (the customer's
        // domain can't be sent from our servers without failing SPF / DMARC), Reply-To the mailbox.
        $appFrom = (string) config('mail.from.address');
        $from = $mailbox->smtp_mode === 'smtp' || $appFrom === '' ? $mailbox->address() : $appFrom;

        $email->from(new Address($from, $name))
            ->replyTo(new Address($mailbox->address(), $name))
            ->to(new Address($ticket->contact->email, (string) $ticket->contact->name))
            ->subject(TicketMailThread::replySubject($ticket));

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        $thread = TicketMailThread::headersFor($ticket, $messageId);
        if ($thread['in_reply_to'] !== null) {
            $headers->addIdHeader('In-Reply-To', $thread['in_reply_to']);
            $headers->addIdHeader('References', $thread['references']);
        }
        $headers->addTextHeader(self::LOOP_HEADER, 'helpdesk');
    }

    private static function newMessageId(HelpdeskMailbox $mailbox): string
    {
        $domain = Str::after($mailbox->address(), '@') ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'brainitive.local');

        return 'ticket.'.Str::uuid()->toString().'@'.$domain;
    }

    private static function dispatch(SendTicketEmail $job, bool $manual = false): void
    {
        if (! WebhookDispatcher::syncQueue()) {
            dispatch($job);
        } elseif ($manual || app()->runningInConsole()) {
            dispatch_sync($job);
        } else {
            // sync queue (dev, e2e, small installs): after the response, so a slow SMTP server never holds the request
            dispatch($job)->afterResponse();
        }
    }

    /** Rich text → plain text for the text/plain part. */
    public static function plain(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|h[1-6]|blockquote|tr)>/i'], ["\n", "\n\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
