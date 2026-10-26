<?php

namespace App\Support\Search;

/**
 * Plain text for the index (BRAI-55): HTML / editor JSON → words, clipped to sane sizes.
 *
 * The highlight markers (private-use characters U+E000 / U+E001) are removed here, so stored text
 * can never fake a highlight: everything else in a snippet is escaped before the markers become
 * <mark> tags (see SearchHighlight).
 */
final class SearchText
{
    public const TITLE_LIMIT = 500;

    public const KEYWORDS_LIMIT = 4000;

    /** Far below the 1 MB tsvector limit, and plenty for a doc. */
    public const BODY_LIMIT = 60000;

    public static function fromHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        // blocks and line breaks become spaces, so words of two paragraphs don't merge
        $html = preg_replace('~<(br|/p|/div|/li|/h[1-6]|/td|/th|/tr|/blockquote|/pre)\b[^>]*>~i', ' $0', $html) ?? $html;
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;

        return self::plain(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Text of an editor JSON value (Editor.js blocks, Lexical / TipTap nodes): every string under a text-like key. */
    public static function fromJson(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                return self::fromHtml($value);
            }
            $value = $decoded;
        }
        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($value, function ($leaf, $key) use (&$parts) {
            if (is_string($leaf) && in_array($key, ['text', 'html', 'content', 'caption', 'title', 'name', 'value'], true)) {
                $parts[] = str_contains($leaf, '<') ? self::fromHtml($leaf) : $leaf;
            }
        });

        return self::plain(implode(' ', $parts));
    }

    /** Collapse whitespace and drop control / marker characters. */
    public static function plain(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x{E000}\x{E001}\p{Cc}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function clip(string $text, int $limit): string
    {
        $text = self::plain($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }

    /**
     * Space-separated parts of a file name or URL, so "invoice-2024.pdf" also finds "invoice".
     */
    public static function words(?string $value): string
    {
        return self::plain(preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $value));
    }
}
