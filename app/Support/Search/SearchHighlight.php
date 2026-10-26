<?php

namespace App\Support\Search;

/**
 * Safe highlighted HTML (BRAI-55): the engine marks matches with the private-use characters
 * U+E000 / U+E001 (removed from all indexed text by SearchText), everything is HTML-escaped, and
 * only then do the markers become <mark></mark>. The result never contains another tag.
 */
final class SearchHighlight
{
    public const START = "\u{E000}";

    public const STOP = "\u{E001}";

    public static function toHtml(?string $marked): string
    {
        if ($marked === null || $marked === '') {
            return '';
        }
        $escaped = htmlspecialchars($marked, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if (substr_count($escaped, self::START) !== substr_count($escaped, self::STOP)) {
            return str_replace([self::START, self::STOP], '', $escaped);
        }

        return str_replace([self::START, self::STOP], ['<mark>', '</mark>'], $escaped);
    }

    /**
     * Mark the words in plain text and cut an excerpt around the first match — for engines
     * without their own highlighter (BasicSearchEngine) and for titles.
     *
     * @param  list<string>  $words  lower-case letters / digits
     */
    public static function mark(string $text, array $words, ?int $excerpt = null): string
    {
        $text = SearchText::plain($text);
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));
        if ($words === []) {
            return $excerpt !== null ? mb_substr($text, 0, $excerpt) : $text;
        }
        $pattern = '/(?<![\p{L}\p{N}])('.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)).')/iu';

        if ($excerpt !== null && mb_strlen($text) > $excerpt) {
            $start = 0;
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $start = max(0, mb_strlen(substr($text, 0, $m[0][1])) - intdiv($excerpt, 3));
            }
            $text = ($start > 0 ? '… ' : '').mb_substr($text, $start, $excerpt).($start + $excerpt < mb_strlen($text) ? ' …' : '');
        }

        return preg_replace($pattern, self::START.'$1'.self::STOP, $text) ?? $text;
    }
}
