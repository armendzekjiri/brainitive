<?php

namespace App\Support\Helpdesks\Mail;

use App\Models\HelpdeskMailbox;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;

/**
 * The real IMAP client: webklex/php-imap's socket protocol (pure PHP — ext-imap is gone from PHP
 * 8.4). Only the low-level commands are used (LOGIN, SELECT, UID SEARCH / FETCH / STORE / MOVE);
 * parsing is MimeParser's job. The socket goes to the address MailHost checked; TLS verifies the
 * certificate against the host name (peer_name), so pinning doesn't weaken it.
 */
class ImapMailboxConnector implements MailboxConnector
{
    public function open(HelpdeskMailbox $mailbox): MailboxConnection
    {
        $host = strtolower(trim($mailbox->imap_host));
        $port = (int) $mailbox->imap_port;
        $address = MailHost::connectAddress($host, $port, 'imap');
        $encryption = $mailbox->imap_encryption;
        if ($encryption === 'none' && ! MailHost::allowPrivate()) {
            throw new MailboxException('The IMAP connection must be encrypted (SSL/TLS or STARTTLS).');
        }
        $password = (string) $mailbox->imap_password;

        try {
            $protocol = new ImapProtocol(Config::make(), true, $encryption === 'none' ? false : $encryption);
            $protocol->setConnectionTimeout((int) config('helpdesk_mail.timeout', 20));
            $protocol->setSslOptions(['peer_name' => $host, 'SNI_enabled' => true]);
            $protocol->connect($address, $port);
        } catch (Throwable $e) {
            throw new MailboxException("Can't connect to {$host}:{$port} (".self::safe($e, $password).').', 0, $e);
        }

        try {
            $protocol->login((string) $mailbox->imap_username, $password)->validatedData();
        } catch (Throwable $e) {
            $protocol->logout();
            throw new MailboxException('Login failed: check the user name and password ('.self::safe($e, $password).').', 0, $e);
        }

        $folder = $mailbox->imap_folder ?: 'INBOX';
        try {
            $selected = $protocol->selectFolder(self::utf7($folder))->validatedData();
        } catch (Throwable $e) {
            $protocol->logout();
            throw new MailboxException("The folder \"{$folder}\" can't be opened (".self::safe($e, $password).').', 0, $e);
        }

        return new class($protocol, (int) ($selected['uidvalidity'] ?? 0), $mailbox->processed_folder ? self::utf7($mailbox->processed_folder) : null) implements MailboxConnection
        {
            public function __construct(private ImapProtocol $protocol, private int $uidValidity, private ?string $processedFolder) {}

            public function uidValidity(): int
            {
                return $this->uidValidity;
            }

            public function unseenUids(int $limit): array
            {
                $uids = array_map('intval', (array) $this->protocol->search(['UNSEEN'], IMAP::ST_UID)->validatedData());
                sort($uids);

                return array_slice(array_values(array_filter($uids, fn (int $uid) => $uid > 0)), 0, $limit);
            }

            public function size(int $uid): int
            {
                $sizes = (array) $this->protocol->sizes([$uid], IMAP::ST_UID)->validatedData();

                return (int) ($sizes[$uid] ?? 0);
            }

            public function header(int $uid): string
            {
                return $this->part($uid, 'BODY.PEEK[HEADER]', 'BODY[HEADER]');
            }

            public function message(int $uid): string
            {
                return $this->part($uid, 'BODY.PEEK[]', 'BODY[]');
            }

            public function markProcessed(int $uid): void
            {
                $this->protocol->store(['\Seen'], $uid, null, '+', true, IMAP::ST_UID)->validatedData();
                if ($this->processedFolder !== null) {
                    $this->protocol->moveMessage($this->processedFolder, $uid, null, IMAP::ST_UID)->validatedData();
                }
            }

            public function close(): void
            {
                try {
                    $this->protocol->logout();
                } catch (Throwable) {
                    // already gone
                }
            }

            /** BODY.PEEK doesn't set \Seen; a crash before markProcessed() leaves the mail unseen (dedupe handles the retry). */
            private function part(int $uid, string $item, string $responseKey): string
            {
                $rows = (array) $this->protocol->fetch(['UID', $item], [$uid], null, IMAP::ST_UID)->validatedData();
                $row = $rows[$uid] ?? (count($rows) === 1 ? reset($rows) : null);

                if (! is_array($row) || ! isset($row[$responseKey]) || ! is_string($row[$responseKey])) {
                    throw new MailboxException("Message {$uid} could not be read.");
                }

                return $row[$responseKey];
            }
        };
    }

    /** IMAP folder names are modified UTF-7 (RFC 3501). */
    private static function utf7(string $folder): string
    {
        return mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
    }

    private static function safe(Throwable $e, string $password): string
    {
        $message = MailboxException::from($e)->getMessage();

        return $password !== '' ? str_replace($password, '***', $message) : $message;
    }
}
