<?php

namespace App\Support\PageReviews;

/**
 * Text-quote anchors of inline review comments (BRAI-80). A comment stores the selected text plus a
 * few characters before / after it, measured on the snapshot's whitespace-normalized text, so the
 * saved page HTML never changes. The same rule lives in resources/js/components/page-review/anchors.ts
 * (keep both in sync):
 *
 * - text = the HTML's text content (tags stripped, entities decoded), every whitespace run → one space;
 * - no occurrence of the quote → outdated;
 * - exactly one → anchored there;
 * - several → the first whose prefix AND suffix match, else the only one matching either, else outdated —
 *   an anchor never attaches to the wrong text.
 */
final class QuoteAnchor
{
    /** Characters of context the client stores on each side (the columns hold up to 64). */
    public const CONTEXT = 32;

    public static function text(string $html): string
    {
        return self::normalize(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Collapse whitespace exactly like JavaScript's /\s+/ (its character set, nothing more). */
    public static function normalize(string $text): string
    {
        return preg_replace('/[\t\n\x{0B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $text) ?? $text;
    }

    /** Byte offset of the quote in the normalized $text, or null when it can't be placed unambiguously. */
    public static function locate(string $text, string $quote, ?string $prefix, ?string $suffix): ?int
    {
        $quote = self::normalize($quote);
        if (trim($quote) === '') {
            return null;
        }

        $positions = [];
        for ($offset = 0; ($found = strpos($text, $quote, $offset)) !== false; $offset = $found + 1) {
            $positions[] = $found;
        }

        if (count($positions) <= 1) {
            return $positions[0] ?? null;
        }

        $prefix = self::normalize((string) $prefix);
        $suffix = self::normalize((string) $suffix);
        $prefixMatches = fn (int $at) => $prefix === '' ? $at === 0 : str_ends_with(substr($text, 0, $at), $prefix);
        $suffixMatches = fn (int $at) => $suffix === ''
            ? $at + strlen($quote) === strlen($text)
            : str_starts_with(substr($text, $at + strlen($quote)), $suffix);

        foreach ($positions as $at) {
            if ($prefixMatches($at) && $suffixMatches($at)) {
                return $at;
            }
        }

        $either = array_values(array_filter($positions, fn (int $at) => $prefixMatches($at) || $suffixMatches($at)));

        return count($either) === 1 ? $either[0] : null;
    }

    /** Whether an anchored comment no longer finds its text in the given HTML. */
    public static function isOutdated(string $html, ?string $quote, ?string $prefix, ?string $suffix): bool
    {
        if ($quote === null || $quote === '') {
            return false; // page-level comment
        }

        return self::locate(self::text($html), $quote, $prefix, $suffix) === null;
    }
}
