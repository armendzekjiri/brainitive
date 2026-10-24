<?php

namespace App\Support\Helpdesks\Mail;

/**
 * An open IMAP session on the mailbox's folder (selected). UIDs are IMAP UIDs, stable while
 * uidValidity() stays the same. Reading never sets \Seen (BODY.PEEK); markProcessed() does.
 */
interface MailboxConnection
{
    public function uidValidity(): int;

    /** @return list<int> UIDs of unseen messages, oldest first, at most $limit */
    public function unseenUids(int $limit): array;

    /** RFC822.SIZE in bytes. */
    public function size(int $uid): int;

    /** The raw header block only (for messages too large to download). */
    public function header(int $uid): string;

    /** The whole raw message (RFC 5322). */
    public function message(int $uid): string;

    /** Mark as seen, then move to the processed folder when one is set. */
    public function markProcessed(int $uid): void;

    public function close(): void;
}
