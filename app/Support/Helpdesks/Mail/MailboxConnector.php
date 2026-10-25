<?php

namespace App\Support\Helpdesks\Mail;

use App\Models\HelpdeskMailbox;

/**
 * Opens a helpdesk's IMAP mailbox (v1.40.0, BRAI-79). Bound in AppServiceProvider: the webklex
 * socket client in real environments, FakeMailboxConnector in e2e (HELPDESK_MAIL_FAKE) — PHPUnit
 * binds its own fake. Throws MailboxException with a message that is safe to show.
 */
interface MailboxConnector
{
    public function open(HelpdeskMailbox $mailbox): MailboxConnection;
}
