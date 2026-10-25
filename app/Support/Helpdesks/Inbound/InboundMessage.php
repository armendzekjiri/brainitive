<?php

namespace App\Support\Helpdesks\Inbound;

use Carbon\CarbonImmutable;

/**
 * One received email, the same for every transport (v1.40.0). The IMAP poller (ticket 3) and the
 * forwarding-address webhook (ticket 4) build this, then hand it to InboundMail::receive().
 * Contract: docs/ai/tasks/release-v1.40.0.md.
 *
 * Header values are raw (decoded to UTF-8), never trusted for authorization on their own.
 */
final class InboundMessage
{
    /**
     * @param  'imap'|'forwarding'  $transport
     * @param  string  $messageId  the Message-ID header without <>, or a transport id when missing (dedupe key)
     * @param  list<string>  $to  every To/Cc address, lower-cased
     * @param  list<string>  $references  Message-IDs from References (without <>), oldest first
     * @param  list<array{filename: string, mime: string, size: int, path: string}>  $attachments  temp files the transport wrote; receive() moves what it keeps
     * @param  array<string, string>  $headers  lower-cased name => value: at least auto-submitted, precedence, x-autoreply, list-id, return-path when present
     */
    public function __construct(
        public readonly string $transport,
        public readonly string $messageId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $attachments,
        public readonly array $headers,
        public readonly CarbonImmutable $receivedAt,
    ) {}
}
