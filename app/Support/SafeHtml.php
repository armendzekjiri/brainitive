<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allowlist HTML sanitizer for rich text that reaches other people's browsers — above all
 * anything the public helpdesk widget / portal accepts. Keeps formatting, links and images;
 * drops scripts, event handlers, javascript: URLs, iframes, styles and data-* attributes (except
 * the smart link ones on links).
 * The frontend sanitizes again when rendering (resources/js/lib/sanitize.ts).
 */
class SafeHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes(['https', 'http'])
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                // smart links (BRAI-143): a plain link everywhere, a live chip in the editor
                ->allowAttribute('data-smart-link', 'a')
                ->allowAttribute('data-smart-link-view', 'a')
                ->withMaxInputLength(500_000)
        );
    }
}
