<?php

namespace App\Support\Helpdesks\Mail;

use App\Models\HelpdeskMailbox;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Throwable;

/**
 * How a helpdesk's outgoing email leaves (v1.40.0, BRAI-79): through the app's mailer, or the
 * mailbox's own SMTP server. The SMTP transport is built from typed parts (never a DSN string a
 * password could break), connects to the address MailHost checked and verifies TLS against the
 * host name. Bound as a singleton so tests can swap it.
 */
class MailTransports
{
    public function forMailbox(HelpdeskMailbox $mailbox): TransportInterface
    {
        if ($mailbox->smtp_mode !== 'smtp' || MailHost::fake()) {
            return Mail::mailer()->getSymfonyTransport();
        }

        return $this->smtp($mailbox);
    }

    /** Connect + EHLO + AUTH, then disconnect. Throws MailboxException. */
    public function test(HelpdeskMailbox $mailbox): void
    {
        if ($mailbox->smtp_mode !== 'smtp' || MailHost::fake()) {
            return;
        }

        $transport = $this->smtp($mailbox);
        try {
            $transport->start();
        } catch (Throwable $e) {
            $message = MailboxException::from($e)->getMessage();
            $password = $mailbox->smtpPassword();
            throw new MailboxException('SMTP: '.($password !== '' ? str_replace($password, '***', $message) : $message), 0, $e);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // not connected
            }
        }
    }

    public function smtp(HelpdeskMailbox $mailbox): EsmtpTransport
    {
        $host = strtolower(trim((string) $mailbox->smtp_host));
        $port = (int) $mailbox->smtp_port;
        $address = MailHost::connectAddress($host, $port, 'smtp');
        $encryption = $mailbox->smtp_encryption ?: ($port === 465 ? 'ssl' : 'starttls');
        if ($encryption === 'none' && ! MailHost::allowPrivate()) {
            throw new MailboxException('The SMTP connection must be encrypted (SSL/TLS or STARTTLS).');
        }

        $stream = (new SocketStream)
            ->setTimeout((float) config('helpdesk_mail.timeout', 20))
            ->setStreamOptions(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);

        $transport = new EsmtpTransport($address, $port, $encryption === 'ssl', null, null, $stream);
        if ($encryption === 'starttls') {
            $transport->setRequireTls(true);
        } elseif ($encryption === 'none') {
            $transport->setAutoTls(false);
        }
        $transport->setUsername($mailbox->smtpUsername());
        $transport->setPassword($mailbox->smtpPassword());

        return $transport;
    }
}
