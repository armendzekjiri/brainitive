<?php

namespace App\Support\Helpdesks\Mail;

use App\Models\HelpdeskMailbox;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\MimeParser;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fetches a mailbox's unseen mail into its helpdesk (v1.40.0, BRAI-79; `app:poll-helpdesk-mailboxes`).
 *
 * Per message: size check (RFC822.SIZE; too large = only the header is read, recorded "too_large"),
 * BODY.PEEK[] (doesn't mark it read), MimeParser → InboundMail::receive(), then \Seen (or a move to
 * the processed folder). A crash between receive() and \Seen leaves the mail unseen; the next run
 * gets DUPLICATE for it and only marks it. Mail without a Message-ID is keyed by UIDVALIDITY + UID.
 *
 * A failing mailbox records last_error and backs off (2, 4, 8 … up to 60 minutes) so a wrong
 * password doesn't hammer the server or lock the account. One run per mailbox at a time (lock).
 */
final class MailboxPoller
{
    public function __construct(private MailboxConnector $connector) {}

    /** Due for a poll (backoff after failures). */
    public static function due(HelpdeskMailbox $mailbox): bool
    {
        if ($mailbox->consecutive_failures === 0 || ! $mailbox->last_checked_at) {
            return true;
        }
        $wait = min(60, 2 ** min($mailbox->consecutive_failures, 6));

        return $mailbox->last_checked_at->lte(now()->subMinutes($wait));
    }

    /**
     * @return array{processed: int, results: array<string, int>, error: ?string}
     */
    public function poll(HelpdeskMailbox $mailbox): array
    {
        $summary = ['processed' => 0, 'results' => [], 'error' => null];
        $lock = Cache::lock('helpdesk-mailbox-poll:'.$mailbox->id, 600);
        if (! $lock->get()) {
            return $summary;
        }

        $helpdesk = $mailbox->helpdesk()->first();
        $connection = null;
        try {
            if (! $helpdesk) {
                return $summary;
            }
            $connection = $this->connector->open($mailbox);
            $validity = $connection->uidValidity();
            $max = (int) config('helpdesk_mail.max_message_bytes');

            foreach ($connection->unseenUids((int) config('helpdesk_mail.batch_size', 50)) as $uid) {
                $fallbackId = "imap.{$mailbox->id}.{$validity}.{$uid}@inbound";
                $size = $connection->size($uid);

                if ($size > $max) {
                    $message = MimeParser::parse($connection->header($uid)."\r\n\r\n", 'imap', $fallbackId, withAttachments: false);
                    $result = InboundMail::reject($helpdesk, $message, 'too_large', $size);
                } else {
                    $result = InboundMail::receive($helpdesk, MimeParser::parse($connection->message($uid), 'imap', $fallbackId));
                }

                $connection->markProcessed($uid);
                $summary['processed']++;
                $summary['results'][$result] = ($summary['results'][$result] ?? 0) + 1;
            }

            $mailbox->forceFill(['last_checked_at' => now(), 'last_success_at' => now(), 'last_error' => null, 'consecutive_failures' => 0])->save();
        } catch (Throwable $e) {
            $error = MailboxException::from($e)->getMessage();
            $summary['error'] = $error;
            $mailbox->forceFill([
                'last_checked_at' => now(),
                'last_error' => mb_substr($error, 0, 500),
                'consecutive_failures' => $mailbox->consecutive_failures + 1,
            ])->save();
            if (! $e instanceof MailboxException) {
                report($e);
            }
        } finally {
            $connection?->close();
            $lock->release();
        }

        return $summary;
    }
}
