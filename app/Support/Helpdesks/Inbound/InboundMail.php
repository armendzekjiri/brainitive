<?php

namespace App\Support\Helpdesks\Inbound;

use App\Models\Helpdesk;

/**
 * The one entry point for received helpdesk email (v1.40.0), whatever the transport.
 * Ticket 3 (IMAP) implements it: dedupe by message id, loop/auto-reply checks, threading
 * (In-Reply-To / References + the ticket routing token) into a reply or a new ticket, contact
 * matching, sanitized body, safe attachments. Ticket 4 (forwarding address) only calls it.
 *
 * Returns one of the RESULT_* values; never throws for a bad message (it is recorded instead).
 * Foundation stub: ticket 3 replaces the body. Keep the signature and the result values.
 */
final class InboundMail
{
    public const RESULT_TICKET_CREATED = 'ticket_created';

    public const RESULT_REPLY_ADDED = 'reply_added';

    public const RESULT_DUPLICATE = 'duplicate';

    /** auto-replies, bounces, mailing lists, our own outgoing mail */
    public const RESULT_IGNORED = 'ignored';

    /** e.g. a reply to a ticket from someone who isn't on it */
    public const RESULT_REJECTED = 'rejected';

    public static function receive(Helpdesk $helpdesk, InboundMessage $message): string
    {
        return self::RESULT_IGNORED;
    }
}
