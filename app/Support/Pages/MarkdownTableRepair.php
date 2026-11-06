<?php

namespace App\Support\Pages;

use App\Support\Mcp\PageContentConverter;

/**
 * Stored editor HTML whose GFM tables were written as text (`| a | b |`, before the Markdown converter knew
 * tables, v1.51.0) → real tables, for `pages:repair-markdown-tables`. Two shapes exist:
 *
 *  - one paragraph holding the whole table: the old converter joined consecutive lines with spaces
 *    (`<p>| a | b | |---|---| | 1 | 2 |</p>`, also after the editor saved it again with its own markup);
 *  - one paragraph (or `<br>` line) per row, e.g. Markdown pasted into the editor.
 *
 * Only top-level paragraphs that make up a table are rebuilt (their text read back as Markdown, then the same
 * PageContentConverter::markdownToHtml as every write); the rest of the HTML stays byte for byte. A paragraph with
 * table syntax that can't be rebuilt safely (mentions / smart links in it, ragged rows in a joined paragraph) is
 * counted as skipped and left alone. Running it again finds nothing: idempotent.
 */
final class MarkdownTableRepair
{
    /** A delimiter row's trace in text: `|---|`, `| :--: |`. */
    private const DELIMITER_TRACE = '/(?<!\\\\)\|\s*:?-{3,}:?\s*(?<!\\\\)\|/';

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * @return array{html: string, tables: int, skipped: int} the repaired HTML (unchanged when tables is 0)
     */
    public static function repair(string $html): array
    {
        $tables = 0;
        $skipped = 0;
        $replacements = [];

        foreach (self::paragraphGroups($html) as $group) {
            // the group's lines, each with the paragraph it came from
            $lines = [];
            $owner = [];
            $usable = [];
            foreach ($group as $index => [, , $outer]) {
                $markdown = self::paragraphMarkdown($outer);
                $usable[$index] = $markdown !== null;
                // a paragraph left as it is reads as a blank line: no table reaches across it
                foreach ($markdown === null ? [''] : self::lines($markdown) as $line) {
                    $lines[] = $line;
                    $owner[] = $index;
                }
            }

            $touched = [];
            $inTable = array_fill(0, count($lines), false);
            foreach (PageContentConverter::tableSpans($lines) as [$from, $to]) {
                $paragraphs = array_unique(array_slice($owner, $from, $to - $from));
                if (in_array(false, array_map(fn ($p) => $usable[$p], $paragraphs), true)) {
                    continue;
                }
                $tables++;
                for ($l = $from; $l < $to; $l++) {
                    $inTable[$l] = true;
                    $touched[$owner[$l]] = true;
                }
            }

            foreach ($group as $index => [, , $outer]) {
                if (! isset($touched[$index]) && preg_match(self::DELIMITER_TRACE, html_entity_decode(strip_tags($outer), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                    $skipped++;
                }
            }

            // each run of touched paragraphs → one replacement
            $run = [];
            foreach (array_keys($group) as $index) {
                if (isset($touched[$index])) {
                    $run[] = $index;

                    continue;
                }
                if ($run !== []) {
                    $replacements[] = self::replacement($group, $run, $lines, $owner, $inTable);
                    $run = [];
                }
            }
            if ($run !== []) {
                $replacements[] = self::replacement($group, $run, $lines, $owner, $inTable);
            }
        }

        // back to front, so earlier offsets stay valid
        usort($replacements, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($replacements as [$start, $end, $new]) {
            $html = substr($html, 0, $start).$new.substr($html, $end);
        }

        return ['html' => $html, 'tables' => $tables, 'skipped' => $skipped];
    }

    /** Whether stored HTML may hold a text table (a cheap pre-check before repair()). */
    public static function mayContainTable(string $html): bool
    {
        return (bool) preg_match('/\|\s*:?-+:?\s*\|/', $html);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string}>  $group
     * @param  list<int>  $run
     * @param  list<string>  $lines
     * @param  list<int>  $owner
     * @param  list<bool>  $inTable
     * @return array{0: int, 1: int, 2: string}
     */
    private static function replacement(array $group, array $run, array $lines, array $owner, array $inTable): array
    {
        $markdown = '';
        $previous = null;
        foreach ($lines as $l => $line) {
            if (! in_array($owner[$l], $run, true)) {
                continue;
            }
            if ($previous !== null) {
                // lines of one table or one paragraph stay together, separate paragraphs stay separate
                $markdown .= ($owner[$previous] === $owner[$l] || ($inTable[$previous] && $inTable[$l])) ? "\n" : "\n\n";
            }
            $markdown .= $line;
            $previous = $l;
        }

        return [$group[$run[0]][0], $group[$run[count($run) - 1]][1], PageContentConverter::markdownToHtml($markdown)];
    }

    /**
     * The paragraph's lines (`<br>`) as Markdown, a whole table joined into one line split back into rows.
     *
     * @return list<string>
     */
    private static function lines(string $markdown): array
    {
        $lines = [];
        foreach (preg_split("/\n/", $markdown) ?: [] as $line) {
            array_push($lines, ...self::splitJoinedTable($line));
        }

        return $lines;
    }

    /**
     * `Intro | a | b | |---|---| | 1 | 2 | after` → [Intro, | a | b |, | --- | --- |, | 1 | 2 |, '', after]: rows
     * joined by a space, each with a leading and trailing pipe. The cell count comes from the delimiter row; when
     * the pieces don't fall into rows of that width (ragged rows) the line stays as it is.
     *
     * @return list<string>
     */
    private static function splitJoinedTable(string $line): array
    {
        $tokens = preg_split('/(?<!\\\\)\|/', $line) ?: [$line];
        $last = count($tokens) - 1;
        $isDelimiter = fn (int $t) => $t >= 1 && $t < $last && preg_match('/^\s*:?-+:?\s*$/', $tokens[$t]);
        $blank = fn (int $t) => trim($tokens[$t]) === '';

        for ($a = 1; $a < $last; $a++) {
            if (! $isDelimiter($a) || $isDelimiter($a - 1)) {
                continue;
            }
            $b = $a;
            while ($isDelimiter($b + 1)) {
                $b++;
            }
            $width = $b - $a + 1;
            $headerFrom = $a - 1 - $width;
            // a whole header row before it, separated by a space
            if ($headerFrom < 1 || ! $blank($a - 1)) {
                continue;
            }

            $row = fn (int $from) => '| '.implode(' | ', array_map('trim', array_slice($tokens, $from, $width))).' |';
            $out = [];
            $prefix = implode('|', array_slice($tokens, 0, $headerFrom));
            if (trim($prefix) !== '') {
                array_push($out, rtrim($prefix), '');
            }
            array_push($out, $row($headerFrom), $row($a));

            // body rows: a space, then `width` cells and the piece after the closing pipe
            $t = $b + 1;
            while ($t + $width + 1 <= $last && $blank($t)) {
                $out[] = $row($t + 1);
                $t += $width + 1;
            }
            $suffix = implode('|', array_slice($tokens, $t));
            if ($t < $last) {
                // pieces with pipes left over: another joined table, else ragged rows (not safe to guess)
                $rest = self::splitJoinedTable(ltrim($suffix));
                if ($rest === [ltrim($suffix)]) {
                    return [$line];
                }
                array_push($out, '', ...$rest);
            } else {
                // the paragraph ended the table: a next paragraph with a pipe is no row of it
                array_push($out, '', ...(trim($suffix) !== '' ? [ltrim($suffix)] : []));
            }

            return $out;
        }

        return [$line];
    }

    /**
     * A paragraph's content as Markdown, or null when it holds what Markdown can't carry back (mentions, smart
     * links, other data-* markup).
     */
    private static function paragraphMarkdown(string $outer): ?string
    {
        if (preg_match('/\sdata-[a-z-]+=/i', $outer)) {
            return null;
        }
        // the editor exports bold / italic as <b><strong>…</strong></b> / <i><em>…</em></i>
        $outer = preg_replace('#<b>\s*(<strong\b[^>]*>.*?</strong>)\s*</b>#s', '$1', $outer) ?? $outer;
        $outer = preg_replace('#<i>\s*(<em\b[^>]*>.*?</em>)\s*</i>#s', '$1', $outer) ?? $outer;
        // emphasis the old converter matched across cells (`foo_bar | baz_qux`): back to the underscores
        $outer = preg_replace('#<(strong|b)\b[^>]*>([^<]*(?<!\\\\)\|[^<]*)</\1>#', '__$2__', $outer) ?? $outer;
        $outer = preg_replace('#<(em|i)\b[^>]*>([^<]*(?<!\\\\)\|[^<]*)</\1>#', '_$2_', $outer) ?? $outer;

        return PageContentConverter::htmlToMarkdown($outer);
    }

    /**
     * Runs of top-level <p> elements with only whitespace between them: [start offset, end offset, outer HTML].
     *
     * @return list<list<array{0: int, 1: int, 2: string}>>
     */
    private static function paragraphGroups(string $html): array
    {
        if (! preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(/?)>#', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $groups = [];
        $group = [];
        $depth = 0;
        $open = null;
        $lastEnd = null;
        foreach ($tags as $tag) {
            $offset = $tag[0][1];
            $closing = $tag[1][0] === '/';
            $name = strtolower($tag[2][0]);
            if (in_array($name, self::VOID, true) || $tag[3][0] === '/') {
                continue;
            }
            if (! $closing) {
                if ($depth === 0) {
                    $open = $name === 'p' ? $offset : null;
                    if ($open === null || ($lastEnd !== null && trim(substr($html, $lastEnd, $offset - $lastEnd)) !== '')) {
                        if ($group !== []) {
                            $groups[] = $group;
                        }
                        $group = [];
                    }
                }
                $depth++;

                continue;
            }
            $depth = max(0, $depth - 1);
            if ($depth === 0) {
                $end = $offset + strlen($tag[0][0]);
                if ($open !== null && $name === 'p') {
                    $group[] = [$open, $end, substr($html, $open, $end - $open)];
                }
                $lastEnd = $end;
                $open = null;
            }
        }
        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }
}
