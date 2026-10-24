<?php

namespace App\Support\Helpdesks\Inbound;

use App\Support\SafeHtml;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The body of a received email as safe rich text for a ticket or reply (v1.40.0, BRAI-79).
 * New tickets keep the HTML (sanitized by SafeHtml, and again by the model on write and read);
 * replies prefer the plain-text part with the quoted history cut off ("On … wrote:", "> …",
 * Outlook's "From: … Sent: …" block), else the HTML minus its quote containers. When stripping
 * would leave nothing, the whole message is kept — a message is never lost.
 */
final class EmailBody
{
    private const MAX_TEXT = 50_000;

    private const MAX_HTML = 400_000;

    public static function html(InboundMessage $message, bool $stripQuotes): string
    {
        $text = self::normalizeText((string) $message->text);
        $html = (string) $message->html;
        $hasHtml = trim(strip_tags($html)) !== '';

        if ($stripQuotes && $text !== '') {
            return self::fromText(self::stripQuotedText($text));
        }
        if ($hasHtml) {
            $body = self::htmlBody(mb_substr($html, 0, self::MAX_HTML), $stripQuotes);
            if (trim(strip_tags($body)) !== '' || ! $stripQuotes) {
                return (string) SafeHtml::clean($body);
            }

            return (string) SafeHtml::clean(self::htmlBody(mb_substr($html, 0, self::MAX_HTML), false));
        }

        return self::fromText($stripQuotes ? self::stripQuotedText($text) : $text);
    }

    /** Plain text → paragraphs (escaped). */
    public static function fromText(string $text): string
    {
        $text = trim(mb_substr($text, 0, self::MAX_TEXT));
        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];

        return implode('', array_map(
            fn (string $p) => '<p>'.nl2br(htmlspecialchars(trim($p), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false).'</p>',
            array_filter($paragraphs, fn (string $p) => trim($p) !== ''),
        ));
    }

    /** Cut the quoted history off a plain-text reply; the whole text when nothing would remain. */
    public static function stripQuotedText(string $text): string
    {
        $lines = explode("\n", self::normalizeText($text));
        $kept = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            $next = trim($lines[$i + 1] ?? '');

            if (self::isQuoteHeader($trimmed, $next)) {
                break;
            }
            if (str_starts_with($trimmed, '>')) {
                continue;
            }
            $kept[] = $line;
        }

        $result = trim(implode("\n", $kept));

        return $result !== '' ? $result : trim($text);
    }

    private static function isQuoteHeader(string $line, string $next): bool
    {
        if ($line === '') {
            return false;
        }

        return (bool) (
            preg_match('/^(On|Am|Le|El|Il|Op)\s.+(wrote|schrieb|a écrit|escribió|ha scritto|schreef)\s*:$/iu', $line)
            // "On Mon, 1 Jan 2026, Name <x@y>" + "wrote:" on the next line
            || (preg_match('/^(On|Am)\s.+/iu', $line) && preg_match('/^.*(wrote|schrieb)\s*:$/iu', $next) && mb_strlen($line) < 200)
            || preg_match('/^-{2,}\s*(Original Message|Ursprüngliche Nachricht|Forwarded message|Weitergeleitete Nachricht)\s*-{2,}/iu', $line)
            || preg_match('/^_{10,}$/', $line)
            || (preg_match('/^(From|Von|De):\s/iu', $line) && preg_match('/^(Sent|Gesendet|Date|Datum|Envoyé|To|An):\s/iu', $next))
        );
    }

    /** The <body> content without scripts / styles and (optionally) the quoted history containers. */
    private static function htmlBody(string $html, bool $stripQuotes): string
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $remove = ['//script', '//style', '//head', '//title', '//meta', '//link'];
        if ($stripQuotes) {
            $remove = array_merge($remove, [
                '//blockquote[@type="cite"]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " gmail_extra ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " moz-cite-prefix ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]',
                '//*[@id="mail-editor-reference-message-container"]',
            ]);
        }
        foreach ($remove as $query) {
            foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Outlook: everything from the reply separator on is the quoted message
        if ($stripQuotes) {
            foreach (['//*[@id="divRplyFwdMsg"]', '//*[@id="appendonsend"]'] as $query) {
                $marker = $xpath->query($query)?->item(0);
                if ($marker instanceof DOMElement) {
                    while ($marker->nextSibling) {
                        $marker->parentNode?->removeChild($marker->nextSibling);
                    }
                    $marker->parentNode?->removeChild($marker);
                }
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function normalizeText(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
