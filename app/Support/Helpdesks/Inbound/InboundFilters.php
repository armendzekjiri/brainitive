<?php

namespace App\Support\Helpdesks\Inbound;

use App\Models\Helpdesk;
use App\Models\HelpdeskInboundEmail;
use App\Models\TicketEmailMessage;
use App\Support\Helpdesks\Mail\TicketMailer;

/**
 * Mail InboundMail::receive() must not turn into tickets or replies (v1.40.0, BRAI-79): auto-replies,
 * bounces, mailing lists and our own outgoing mail. Answering those is how mail loops start.
 */
final class InboundFilters
{
    /** Why the message is ignored, or null. */
    public static function ignoreReason(Helpdesk $helpdesk, InboundMessage $message): ?string
    {
        $h = $message->headers;
        $from = strtolower(trim($message->fromEmail));

        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'invalid_sender';
        }

        // RFC 3834: anything but "no" is machine-generated (auto-replied, auto-generated, auto-notified)
        $auto = strtolower(trim($h['auto-submitted'] ?? ''));
        if ($auto !== '' && ! str_starts_with($auto, 'no')) {
            return 'auto_submitted';
        }
        if (preg_match('/\b(bulk|junk|list|auto_reply)\b/i', $h['precedence'] ?? '')) {
            return 'bulk';
        }
        if (isset($h['x-autoreply']) || isset($h['x-autorespond'])) {
            return 'auto_reply';
        }
        if (isset($h['list-id']) || isset($h['list-post'])) {
            return 'mailing_list';
        }

        // bounces: null sender, the daemons, delivery status reports
        $local = strstr($from, '@', true) ?: '';
        if (in_array($local, ['mailer-daemon', 'postmaster', 'mail-daemon'], true)
            || trim($h['return-path'] ?? '') === '<>'
            || isset($h['x-failed-recipients'])
            || str_starts_with(strtolower(trim($h['content-type'] ?? '')), 'multipart/report')) {
            return 'bounce';
        }

        // our own mail coming back (mailbox address, app address, a header only we set, a Message-ID we sent)
        if (isset($h['x-brainitive-loop'])
            || in_array($from, array_filter([TicketMailer::replyAddress($helpdesk), strtolower((string) config('mail.from.address'))]), true)
            || TicketEmailMessage::where('message_hash', HelpdeskInboundEmail::hashOf($message->messageId))->where('direction', 'out')->exists()) {
            return 'own_message';
        }

        return null;
    }

    /** The receiving server says the sender's domain failed DMARC: not trusted to reply into a ticket. */
    public static function failsDmarc(InboundMessage $message): bool
    {
        return (bool) preg_match('/\bdmarc=fail\b/i', $message->headers['authentication-results'] ?? '');
    }
}
