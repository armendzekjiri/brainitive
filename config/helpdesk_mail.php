<?php

/*
 * Email → helpdesk (v1.40.0, BRAI-79): limits for the IMAP poller and the inbound pipeline
 * (App\Support\Helpdesks\Inbound\InboundMail), plus the guards for user-entered mail servers.
 */
return [
    // Messages above this size are not downloaded (recorded as "too_large").
    'max_message_bytes' => (int) env('HELPDESK_MAIL_MAX_MESSAGE_MB', 25) * 1024 * 1024,

    // Per attachment / per message; the rest is listed as "not accepted" in the ticket.
    'max_attachment_bytes' => 10 * 1024 * 1024,
    'max_attachments' => 10,

    // Messages per mailbox and poll run (the next run continues).
    'batch_size' => 50,

    // Loop guard: new tickets from one sender per helpdesk and hour; more are ignored.
    'new_tickets_per_sender_per_hour' => 10,

    // Socket timeout (seconds) for IMAP and SMTP.
    'timeout' => 20,

    // Ports a mailbox may use (no port scanning through the poller).
    'imap_ports' => [143, 993],
    'smtp_ports' => [25, 465, 587, 2525],

    // Private / loopback mail servers and unencrypted connections: ONLY for local development
    // (APP_ENV=local) and e2e (always). Ignored in every other environment (MailHost::allowPrivate()).
    'allow_private_hosts' => (bool) env('HELPDESK_MAIL_ALLOW_PRIVATE_HOSTS', false),

    // e2e ONLY: a fake IMAP / SMTP connection ("Test connection" and the poller never touch the network).
    // Honoured only when APP_ENV=e2e.
    'fake' => (bool) env('HELPDESK_MAIL_FAKE', false),
];
