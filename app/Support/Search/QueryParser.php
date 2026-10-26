<?php

namespace App\Support\Search;

/**
 * Turns what people type into terms (BRAI-55):
 *
 * - words must all match; the last one also matches as a prefix ("road" finds "roadmap");
 * - "quoted words" match as a phrase;
 * - -word / -"a phrase" excludes;
 * - a word with punctuation inside ("BRAI-12", "e-mail", "v1.2") is a phrase of its parts — the
 *   index splits on punctuation the same way;
 * - something shaped like an issue / ticket key ("BRAI-12") is also matched exactly and ranks first.
 *
 * Words are reduced to letters and digits, so nothing a person types reaches the query syntax.
 */
final class QueryParser
{
    private const MAX_TERMS = 12;

    private const MAX_PARTS = 8;

    private const MAX_WORD = 60;

    private const MAX_INPUT = 300;

    private const MIN_PREFIX = 2;

    private const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9]{0,15}-\d{1,9}$/';

    /**
     * @return array{text: string, terms: list<array{words: list<string>, phrase: bool, quoted: bool, negated: bool, prefix: bool}>, keys: list<string>}
     */
    public static function parse(?string $input): array
    {
        $text = trim(mb_substr(SearchText::plain($input), 0, self::MAX_INPUT));
        $terms = [];
        $keys = [];

        preg_match_all('/(-?)"([^"]*)"?|(\S+)/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
            $quoted = isset($match[2]) && ($match[3] ?? '') === '' && str_contains($match[0], '"');
            if ($quoted) {
                $words = self::words($match[2]);
                if ($words !== []) {
                    $terms[] = ['words' => $words, 'phrase' => true, 'quoted' => true, 'negated' => $match[1] === '-', 'prefix' => false];
                }

                continue;
            }

            $raw = $match[3];
            $negated = str_starts_with($raw, '-') && mb_strlen($raw) > 1;
            $word = $negated ? mb_substr($raw, 1) : $raw;
            $words = self::words($word);
            if ($words === []) {
                continue;
            }
            if (! $negated && preg_match(self::KEY_PATTERN, $word)) {
                $keys[] = mb_strtoupper($word);
            }
            $terms[] = ['words' => $words, 'phrase' => count($words) > 1, 'quoted' => false, 'negated' => $negated, 'prefix' => false];
        }

        // the last word someone typed may be unfinished (a prefix of one letter would match nearly everything)
        for ($i = count($terms) - 1; $i >= 0; $i--) {
            if (! $terms[$i]['negated']) {
                if (! str_ends_with($text, '"') && mb_strlen((string) end($terms[$i]['words'])) >= self::MIN_PREFIX) {
                    $terms[$i]['prefix'] = true;
                }
                break;
            }
        }

        return ['text' => $text, 'terms' => $terms, 'keys' => array_values(array_unique($keys))];
    }

    /**
     * The tsquery for PostgreSQL's to_tsquery('simple', …): lexemes are letters / digits only and
     * quoted anyway.
     *
     * @param  list<array{words: list<string>, phrase: bool, quoted: bool, negated: bool, prefix: bool}>  $terms
     */
    public static function toTsQuery(array $terms): string
    {
        $parts = [];
        foreach ($terms as $term) {
            $lexemes = array_map(fn (string $w) => "'".str_replace("'", "''", $w)."'", $term['words']);
            if ($term['prefix']) {
                $lexemes[count($lexemes) - 1] .= ':*';
            }
            $expression = count($lexemes) > 1 ? '('.implode(' <-> ', $lexemes).')' : $lexemes[0];
            $parts[] = $term['negated'] ? "!{$expression}" : $expression;
        }

        return implode(' & ', $parts);
    }

    /**
     * Any of the terms, OR-ed and without their "-": the words to highlight (the included terms)
     * or the words a typo match must not contain (the excluded ones).
     *
     * @param  list<array{words: list<string>, phrase: bool, quoted: bool, negated: bool, prefix: bool}>  $terms
     */
    public static function toAnyTsQuery(array $terms): string
    {
        return str_replace(' & ', ' | ', self::toTsQuery(array_map(fn (array $term) => ['negated' => false] + $term, $terms)));
    }

    /** @return list<string> */
    private static function words(string $value): array
    {
        // modifier letters (ʻ ʼ ˈ ː …) and ŉ: unaccent() turns them into ' or : — which would be
        // query syntax; the index drops them as punctuation anyway
        $value = preg_replace(['/\x{0149}/u', '/[\p{Lm}\p{Sk}]+/u'], ['n', ' '], $value) ?? '';
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_map(fn (string $w) => mb_substr($w, 0, self::MAX_WORD), $parts), 0, self::MAX_PARTS);
    }
}
