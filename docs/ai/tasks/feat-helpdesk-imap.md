# Email → helpdesk via IMAP + the shared inbound pipeline (BRAI-79)

**Branch:** feat/helpdesk-imap (release v1.40.0, ticket 3 — contract in [`release-v1.40.0.md`](./release-v1.40.0.md))
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-79 (ndizeal-everything / brainitive / main-board #181)

## Goal
A helpdesk can connect a mailbox (IMAP). Mail sent to it becomes tickets, replies thread into the
right ticket, and agent public replies go back out by email in the same thread. Ticket 4
(forwarding address) feeds the same pipeline, `InboundMail::receive()`.

## Approach
- **Libraries** (both AGPL-compatible, pure PHP, no ext-imap — removed from PHP 8.4 core):
  `webklex/php-imap` (MIT) only for its socket IMAP protocol (`ImapProtocol`: LOGIN, SELECT,
  UID SEARCH UNSEEN, RFC822.SIZE, BODY.PEEK[], STORE, MOVE); `zbateson/mail-mime-parser`
  (BSD-2) for MIME parsing. webklex's own parser leaves RFC 2047 words (subjects, names,
  file names) undecoded without ext-imap, so it isn't used for parsing.
- **Tables:** `helpdesk_mailboxes` (one per helpdesk, encrypted IMAP/SMTP passwords, status),
  `helpdesk_inbound_emails` (dedupe on helpdesk + Message-ID hash, audit trail + result),
  `ticket_email_messages` (every Message-ID of a ticket's thread, in and out),
  `ticket_replies.email_*` (outgoing Message-ID, status, error, sent time).
- **receive():** dedupe → loop/auto-reply filter → thread (Message-ID refs, then the HMAC
  subject token) → participant check → reply or new ticket (rate-limited per sender) in one
  transaction with the dedupe row → notifications after commit.
- **Outgoing:** a helpdesk with an enabled mailbox emails agent public replies itself (queued
  job, stable Message-ID, In-Reply-To / References, subject token, Reply-To the mailbox) instead
  of `TicketReplyNotification` to the contact; email tickets get a threaded acknowledgment
  (`Auto-Submitted: auto-replied`) instead of `TicketCreatedNotification`.
- **Security:** host + port checks with pinned IPs for IMAP and SMTP sockets (public IPs,
  port allowlist, TLS required outside local / e2e), secrets encrypted + hidden, settings for
  `helpdesks.update` only.

## Changes
- Migration `2026_10_29_300000_create_helpdesk_mail_tables`.
- `app/Support/Helpdesks/Inbound/*` (pipeline, MIME parser, body, filters, threading),
  `app/Support/Helpdesks/Mail/*` (host checks, IMAP connector + fake, poller, ticket mailer),
  `app/Jobs/SendTicketEmail`, `app:poll-helpdesk-mailboxes`, `HelpdeskMailboxController`.
- UI: `components/helpdesk/email/ImapMailboxSection.tsx`; email status on replies in the ticket
  dialog (`Helpdesks/Show`).

## Tests
- `tests/Feature/Helpdesk/InboundMailTest`, `HelpdeskMailboxTest`, `TicketReplyEmailTest`,
  fixtures in `tests/Fixtures/mail/`.
- e2e `e2e/tests/helpdesk-email.spec.ts`.
