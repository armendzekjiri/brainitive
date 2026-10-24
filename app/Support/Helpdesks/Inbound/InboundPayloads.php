<?php

namespace App\Support\Helpdesks\Inbound;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Maps a provider's inbound webhook payload to an InboundMessage (BRAI-124).
 *
 * - Postmark: JSON (https://postmarkapp.com/developer/webhooks/inbound-webhook): FromFull, ToFull,
 *   CcFull, OriginalRecipient, Subject, TextBody, HtmlBody, Headers [{Name, Value}], Attachments
 *   [{Name, Content (base64), ContentType, ContentLength}].
 * - Mailgun: a route's forward() posts form data (https://documentation.mailgun.com/docs/mailgun/user-manual/receive-forward-store/):
 *   recipient, sender, from, To, Cc, subject, body-plain, body-html, message-headers (JSON
 *   [[name, value], …]), Message-Id, In-Reply-To, References, attachment-1…N (files).
 *
 * recipients*() lists the addresses to match against a helpdesk (envelope recipient first), so the
 * controller can refuse unknown recipients before any attachment is written. Bodies are passed on
 * whole (receive() strips quoted history); attachments over the configured limits are dropped.
 */
final class InboundPayloads
{
    private const MAX_BODY_BYTES = 1024 * 1024;

    private const MAX_HEADERS = 200;

    private const MAX_HEADER_VALUE = 4000;

    /** @return list<string> */
    public static function postmarkRecipients(array $data): array
    {
        $candidates = [self::str($data['OriginalRecipient'] ?? null)];
        foreach (['ToFull', 'CcFull', 'BccFull'] as $key) {
            foreach (self::list($data[$key] ?? []) as $entry) {
                $candidates[] = is_array($entry) ? self::str($entry['Email'] ?? null) : '';
            }
        }
        array_push($candidates, ...self::addresses(self::str($data['To'] ?? null)), ...self::addresses(self::str($data['Cc'] ?? null)));

        return self::unique($candidates);
    }

    /** @throws InvalidArgumentException when the payload has no usable sender */
    public static function postmark(array $data, string $tempDir): InboundMessage
    {
        $from = is_array($data['FromFull'] ?? null) ? $data['FromFull'] : [];
        $fromEmail = self::str($from['Email'] ?? null);
        $fromName = self::str($from['Name'] ?? null) ?: null;
        if (! self::validEmail($fromEmail)) {
            [$fromEmail, $fromName] = self::address(self::str($data['From'] ?? null)) ?? ['', null];
        }
        if (! self::validEmail($fromEmail)) {
            throw new InvalidArgumentException('The message has no sender.');
        }

        $pairs = [];
        foreach (self::list($data['Headers'] ?? []) as $header) {
            if (is_array($header)) {
                $pairs[] = [self::str($header['Name'] ?? null), self::str($header['Value'] ?? null)];
            }
        }
        $headers = self::headers($pairs);

        $to = self::postmarkRecipients($data);
        $postmarkId = substr((string) preg_replace('/[^A-Za-z0-9-]/', '', self::str($data['MessageID'] ?? null)), 0, 100);

        $writer = new AttachmentWriter($tempDir);
        foreach (self::list($data['Attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $writer->addBase64(
                    self::str($attachment['Name'] ?? null),
                    self::str($attachment['ContentType'] ?? null),
                    self::str($attachment['Content'] ?? null),
                );
            }
        }

        return self::message(
            messageId: self::messageId($headers['message-id'] ?? null)
                ?? ($postmarkId !== '' ? "postmark-{$postmarkId}@inbound.invalid" : self::hashId($fromEmail, [$data['Subject'] ?? '', $data['Date'] ?? '', $data['TextBody'] ?? ''])),
            fromEmail: $fromEmail,
            fromName: $fromName,
            to: $to,
            subject: self::str($data['Subject'] ?? null),
            text: is_string($data['TextBody'] ?? null) ? $data['TextBody'] : null,
            html: is_string($data['HtmlBody'] ?? null) ? $data['HtmlBody'] : null,
            headers: $headers,
            attachments: $writer->finish('postmark'),
        );
    }

    /**
     * @param  array<string, mixed>  $fields  the form fields
     * @return list<string>
     */
    public static function mailgunRecipients(array $fields): array
    {
        $candidates = self::addresses(self::str($fields['recipient'] ?? null));
        foreach (['To', 'to', 'Cc', 'cc'] as $key) {
            array_push($candidates, ...self::addresses(is_string($fields[$key] ?? null) ? $fields[$key] : ''));
        }

        return self::unique($candidates);
    }

    /**
     * @param  array<string, mixed>  $fields  the form fields
     * @param  array<string, UploadedFile>  $files  attachment-1…N
     *
     * @throws InvalidArgumentException when the payload has no usable sender
     */
    public static function mailgun(array $fields, array $files, string $tempDir): InboundMessage
    {
        $str = fn (string $key): ?string => is_string($fields[$key] ?? null) ? $fields[$key] : null;

        $pairs = [];
        $raw = $str('message-headers');
        $decoded = $raw !== null ? json_decode($raw, true) : null;
        foreach (self::list(is_array($decoded) ? $decoded : []) as $pair) {
            if (is_array($pair) && count($pair) === 2) {
                $pairs[] = [self::str($pair[0]), self::str($pair[1])];
            }
        }
        // the parsed top-level fields, when message-headers lacks them
        foreach (['Message-Id', 'In-Reply-To', 'References', 'From', 'Subject'] as $name) {
            if ($str($name) !== null) {
                $pairs[] = [$name, $str($name)];
            }
        }
        $headers = self::headers($pairs);

        [$fromEmail, $fromName] = self::address($str('from') ?? $headers['from'] ?? '') ?? ['', null];
        if (! self::validEmail($fromEmail)) {
            // the envelope sender (may be a bounce / SRS address on forwarded mail): last resort
            [$fromEmail, $fromName] = self::address($str('sender') ?? '') ?? ['', null];
        }
        if (! self::validEmail($fromEmail)) {
            throw new InvalidArgumentException('The message has no sender.');
        }

        $writer = new AttachmentWriter($tempDir);
        $count = min((int) ($fields['attachment-count'] ?? count($files)), 100);
        for ($i = 1; $i <= max($count, count($files)); $i++) {
            $file = $files["attachment-{$i}"] ?? null;
            if ($file instanceof UploadedFile) {
                $writer->addUpload($file);
            }
        }

        $subject = $str('subject') ?? $headers['subject'] ?? '';

        return self::message(
            messageId: self::messageId($headers['message-id'] ?? null) ?? self::hashId($fromEmail, [$subject, $headers['date'] ?? '', $str('body-plain') ?? '']),
            fromEmail: $fromEmail,
            fromName: $fromName,
            to: self::mailgunRecipients($fields),
            subject: $subject,
            text: $str('body-plain'),
            html: $str('body-html'),
            headers: $headers,
            attachments: $writer->finish('mailgun'),
        );
    }

    /**
     * @param  list<string>  $to
     * @param  array<string, string>  $headers
     * @param  list<array{filename: string, mime: string, size: int, path: string}>  $attachments
     */
    private static function message(string $messageId, string $fromEmail, ?string $fromName, array $to, string $subject, ?string $text, ?string $html, array $headers, array $attachments): InboundMessage
    {
        $references = [];
        if (isset($headers['references'])) {
            preg_match_all('/<([^<>\s]+)>/', $headers['references'], $m);
            $references = array_values(array_unique($m[1] ?: preg_split('/\s+/', trim($headers['references']), -1, PREG_SPLIT_NO_EMPTY)));
            $references = array_slice($references, -50);
        }

        $fromName = $fromName !== null ? trim(self::clean($fromName, 255), " \t\"'") : null;

        return new InboundMessage(
            transport: 'forwarding',
            messageId: $messageId,
            fromEmail: strtolower($fromEmail),
            fromName: $fromName === '' ? null : $fromName,
            to: $to,
            subject: trim(self::clean($subject, 998)),
            text: $text !== null ? self::clean($text, self::MAX_BODY_BYTES, keepNewlines: true) : null,
            html: $html !== null ? self::clean($html, self::MAX_BODY_BYTES, keepNewlines: true) : null,
            inReplyTo: self::messageId($headers['in-reply-to'] ?? null),
            references: $references,
            attachments: $attachments,
            headers: $headers,
            receivedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Lower-cased name => value; the first occurrence wins (Received lines repeat).
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, string>
     */
    private static function headers(array $pairs): array
    {
        $headers = [];
        foreach ($pairs as [$name, $value]) {
            $name = strtolower(trim($name));
            if (count($headers) >= self::MAX_HEADERS || isset($headers[$name]) || ! preg_match('/^[a-z0-9-]{1,100}$/', $name)) {
                continue;
            }
            $headers[$name] = trim(self::clean(self::decodeMime($value), self::MAX_HEADER_VALUE));
        }

        return $headers;
    }

    /** A Message-ID without the angle brackets, or null. */
    private static function messageId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $id = preg_match('/<([^<>\s]+)>/', $value, $m) ? $m[1] : trim($value);

        return $id === '' || strlen($id) > 500 || preg_match('/\s/', $id) ? null : $id;
    }

    /** A stable id for mail without a Message-ID, so a provider retry is still a duplicate. */
    private static function hashId(string $from, array $parts): string
    {
        return 'noid-'.hash('sha256', strtolower($from)."\n".json_encode($parts)).'@inbound.invalid';
    }

    /**
     * "Name <a@b.c>" or "a@b.c" → [email, name|null].
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function address(string $value): ?array
    {
        $value = trim(self::decodeMime($value));
        if (preg_match('/^(.*)<([^<>\s]+@[^<>\s]+)>\s*$/s', $value, $m)) {
            $name = trim($m[1], " \t\"'");

            return [trim($m[2]), $name === '' ? null : $name];
        }
        $addresses = self::addresses($value);

        return $addresses ? [$addresses[0], null] : null;
    }

    /**
     * Every address in a header like `"Doe, Jo" <jo@x.io>, help@y.io` (lower-cased).
     *
     * @return list<string>
     */
    private static function addresses(string $value): array
    {
        preg_match_all('/<([^<>\s,;"]+@[^<>\s,;"]+)>|([^\s<>,;:"()\[\]]+@[^\s<>,;:"()\[\]]+)/', $value, $m, PREG_SET_ORDER);
        $found = [];
        foreach (array_slice($m, 0, 100) as $match) {
            $found[] = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        }

        return self::unique($found);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if (self::validEmail($value) && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return array_slice($out, 0, 100);
    }

    private static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function decodeMime(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    /** Valid UTF-8, no control characters (except newlines / tabs in bodies), at most $max bytes. */
    private static function clean(string $value, int $max, bool $keepNewlines = false): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = $keepNewlines
            ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)
            : preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return mb_strcut((string) $value, 0, $max, 'UTF-8');
    }

    /** A safe display name for an attachment (never used in a stored path). */
    public static function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', self::decodeMime($name)));
        $name = trim(self::clean($name, 200), " .\t");

        return $name === '' ? 'attachment' : $name;
    }

    public static function mime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mime) ? $mime : 'application/octet-stream';
    }
}
