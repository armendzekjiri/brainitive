<?php

namespace App\Support\Helpdesks\Mail;

use App\Models\HelpdeskMailbox;

/**
 * An in-memory mailbox for e2e (HELPDESK_MAIL_FAKE, APP_ENV=e2e only) and PHPUnit: no network.
 * The password "wrong-password" fails the login; `$failWith` fails the connection.
 * Messages are raw RFC 5322 strings keyed by UID; processed UIDs are recorded.
 */
class FakeMailboxConnector implements MailboxConnector
{
    /** @var list<int> */
    public array $processed = [];

    public int $opened = 0;

    /**
     * @param  array<int, string>  $messages  uid => raw message
     * @param  array<int, int>  $sizes  uid => size override (e.g. oversized)
     */
    public function __construct(
        public array $messages = [],
        public array $sizes = [],
        public ?string $failWith = null,
    ) {}

    public function open(HelpdeskMailbox $mailbox): MailboxConnection
    {
        if (($problem = MailHost::problem((string) $mailbox->imap_host, (int) $mailbox->imap_port, 'imap', resolve: false)) !== null) {
            throw new MailboxException($problem);
        }
        if ($this->failWith !== null) {
            throw new MailboxException($this->failWith);
        }
        if ($mailbox->imap_password === 'wrong-password') {
            throw new MailboxException('Login failed: check the user name and password (AUTHENTICATIONFAILED).');
        }
        $this->opened++;

        $fake = $this;

        return new class($fake) implements MailboxConnection
        {
            public function __construct(private FakeMailboxConnector $fake) {}

            public function uidValidity(): int
            {
                return 1;
            }

            public function unseenUids(int $limit): array
            {
                $uids = array_values(array_diff(array_keys($this->fake->messages), $this->fake->processed));
                sort($uids);

                return array_slice($uids, 0, $limit);
            }

            public function size(int $uid): int
            {
                return $this->fake->sizes[$uid] ?? strlen($this->fake->messages[$uid] ?? '');
            }

            public function header(int $uid): string
            {
                $raw = str_replace("\r\n", "\n", $this->fake->messages[$uid] ?? '');

                return strstr($raw, "\n\n", true) ?: $raw;
            }

            public function message(int $uid): string
            {
                return $this->fake->messages[$uid] ?? throw new MailboxException("Message {$uid} could not be read.");
            }

            public function markProcessed(int $uid): void
            {
                $this->fake->processed[] = $uid;
            }

            public function close(): void {}
        };
    }
}
