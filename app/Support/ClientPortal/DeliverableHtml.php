<?php

namespace App\Support\ClientPortal;

use App\Support\SafeHtml;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The HTML a client sees for a published page version (v1.42.0, BRAI-73). Clients see only what was
 * shared, so everything that points back into the workspace goes:
 *
 * - links into the app (relative, same host, non-http schemes) become their plain text — external
 *   http(s) and mailto links stay;
 * - @mentions become plain text (no user ids);
 * - sticky notes (internal annotations) and whiteboard embeds (raw scene data) are dropped;
 * - images stay only when they are uploads (`/storage/…`) or external http(s) images;
 * - then the allowlist sanitizer (SafeHtml) removes scripts, styles, data-* attributes, iframes.
 */
final class DeliverableHtml
{
    private const DROP_ATTRIBUTES = ['data-lexical-sticky', 'data-lexical-sticky-note', 'data-lexical-excalidraw'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        foreach (self::DROP_ATTRIBUTES as $attribute) {
            foreach (iterator_to_array($xpath->query("//*[@{$attribute}]")) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (iterator_to_array($xpath->query('//comment()')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//*[@data-mention-user-id]')) as $node) {
            $node->parentNode?->replaceChild($doc->createTextNode($node->textContent), $node);
        }
        foreach (iterator_to_array($xpath->query('//a')) as $link) {
            /** @var DOMElement $link */
            if (! self::isExternal($link->getAttribute('href'), ['http', 'https', 'mailto'])) {
                self::unwrap($link);
            }
        }
        foreach (iterator_to_array($xpath->query('//img')) as $image) {
            /** @var DOMElement $image */
            $src = $image->getAttribute('src');
            if (! self::isUpload($src) && ! self::isExternal($src, ['http', 'https'])) {
                $image->parentNode?->removeChild($image);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return (string) SafeHtml::clean($out);
    }

    /** An absolute URL with an allowed scheme on another host than this app. */
    private static function isExternal(string $url, array $schemes): bool
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, $schemes, true)) {
            return false;
        }
        if ($scheme === 'mailto') {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && ! in_array($host, self::appHosts(), true);
    }

    /** A public upload of this app: `/storage/…` (relative or on the app's host). */
    private static function isUpload(string $src): bool
    {
        $src = trim($src);
        if (str_starts_with($src, '/storage/') && ! str_contains($src, '..')) {
            return true;
        }
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        $path = (string) parse_url($src, PHP_URL_PATH);

        return in_array($host, self::appHosts(), true) && str_starts_with($path, '/storage/') && ! str_contains($path, '..');
    }

    /** @return list<string> */
    private static function appHosts(): array
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '') {
            return [];
        }
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return [$bare, 'www.'.$bare];
    }

    private static function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}
