<?php

namespace App\Support\Helpdesks\Inbound;

use Carbon\CarbonImmutable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

/**
 * Raw RFC 5322 mail → InboundMessage (v1.40.0, BRAI-79), with zbateson/mail-mime-parser (decodes
 * RFC 2047 / 2231 words, charsets and transfer encodings). Used by the IMAP poller; a transport
 * that receives raw MIME (e.g. a provider's "body-mime") can use it too.
 *
 * Attachments are written to temp files; InboundMail::receive() moves what it keeps and deletes
 * the rest. Call cleanup() yourself when the message never reaches receive().
 */
final class MimeParser
{
    /** Headers copied into InboundMessage::$headers (lower-cased names). */
    public const HEADERS = [
        'auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond', 'x-auto-response-suppress',
        'list-id', 'list-post', 'list-unsubscribe', 'return-path', 'content-type', 'x-loop',
        'x-brainitive-loop', 'x-failed-recipients', 'authentication-results',
    ];

    /** More parts than this are ignored (receive() keeps far fewer anyway). */
    private const MAX_PARTS = 50;

    /**
     * @param  'imap'|'forwarding'  $transport
     * @param  string|null  $fallbackId  dedupe key when the mail has no Message-ID (e.g. "imap:<mailbox>:<uidvalidity>:<uid>")
     */
    public static function parse(string $raw, string $transport = 'imap', ?string $fallbackId = null, bool $withAttachments = true): InboundMessage
    {
        $message = (new MailMimeParser)->parse($raw, false);

        $from = $message->getHeader('From');
        $fromEmail = $from instanceof AddressHeader ? strtolower(trim((string) $from->getEmail())) : '';
        $fromName = $from instanceof AddressHeader ? self::clean((string) $from->getPersonName(), 255) : null;

        $to = [];
        foreach (['To', 'Cc'] as $name) {
            $header = $message->getHeader($name);
            if ($header instanceof AddressHeader) {
                foreach ($header->getAddresses() as $address) {
                    $email = strtolower(trim((string) $address->getEmail()));
                    if ($email !== '' && ! in_array($email, $to, true)) {
                        $to[] = $email;
                    }
                }
            }
        }

        $messageId = self::ids((string) $message->getHeaderValue('Message-ID'))[0] ?? null;
        $messageId ??= $fallbackId ?? 'nomid.'.hash('sha256', $raw).'@inbound';

        $headers = [];
        foreach (self::HEADERS as $name) {
            $value = $message->getHeader($name)?->getRawValue();
            if ($value !== null) {
                $headers[$name] = self::clean($value, 2000) ?? '';
            }
        }

        return new InboundMessage(
            transport: $transport,
            messageId: $messageId,
            fromEmail: $fromEmail,
            fromName: $fromName !== '' ? $fromName : null,
            to: $to,
            subject: self::clean((string) $message->getSubject(), 1000) ?? '',
            text: $message->getTextContent(),
            html: $message->getHtmlContent(),
            inReplyTo: self::ids((string) $message->getHeader('In-Reply-To')?->getRawValue())[0] ?? null,
            references: array_slice(self::ids((string) $message->getHeader('References')?->getRawValue()), -50),
            attachments: $withAttachments ? self::attachments($message) : [],
            headers: $headers,
            receivedAt: CarbonImmutable::now(),
        );
    }

    /** Delete the temp files of a message that never reached receive(). */
    public static function cleanup(InboundMessage $message): void
    {
        foreach ($message->attachments as $attachment) {
            if (is_file($attachment['path'])) {
                @unlink($attachment['path']);
            }
        }
    }

    /**
     * Message-IDs in a header value ("<a@b> <c@d>"), without <>, in order.
     *
     * @return list<string>
     */
    public static function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]{1,900})>/', $value, $matches);
        $ids = $matches[1];
        if ($ids === [] && preg_match('/^\s*([^<>\s]+@[^<>\s]+)\s*$/', $value, $bare)) {
            $ids = [$bare[1]];   // some clients omit the brackets
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{filename: string, mime: string, size: int, path: string}>
     */
    private static function attachments(Message $message): array
    {
        $files = [];
        foreach (array_slice($message->getAllAttachmentParts(), 0, self::MAX_PARTS) as $i => $part) {
            $content = $part->getBinaryContentStream();
            if ($content === null) {
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'helpdesk-mail-');
            if ($path === false) {
                continue;
            }
            $out = fopen($path, 'wb');
            $size = 0;
            while (! $content->eof()) {
                $chunk = $content->read(65536);
                $size += strlen($chunk);
                fwrite($out, $chunk);
            }
            fclose($out);

            $mime = strtolower((string) $part->getContentType('application/octet-stream'));
            $name = self::clean((string) $part->getFilename(), 250);
            if ($name === null || $name === '') {
                $name = $mime === 'message/rfc822' ? 'message.eml' : 'attachment-'.($i + 1);
            }

            $files[] = ['filename' => $name, 'mime' => $mime, 'size' => $size, 'path' => $path];
        }

        return $files;
    }

    /** UTF-8 only, no control characters, trimmed, at most $max characters. */
    private static function clean(string $value, int $max): ?string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
