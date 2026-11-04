<?php

namespace App\Support\Mcp;

use DOMDocument;
use DOMNode;
use Illuminate\Support\Str;

/**
 * Converts between Markdown (what an AI assistant produces) and the HTML that
 * Brainitive's Lexical editor reads/writes.
 *
 *  - Pages store content as { "type": "lexical", "html": "<h1>…</h1>…" }
 *    (see resources/js/Pages/Pages/Show.tsx → extractHtmlContent / save).
 *  - Board item descriptions store the raw HTML string directly
 *    (see resources/js/components/item/ItemDetail.tsx).
 *
 * So markdownToHtml() feeds writes and htmlToMarkdown() feeds reads. A legacy
 * docToMarkdown() is kept for pages created before the Lexical migration, whose
 * content is the old TipTap/ProseMirror { type:'doc', content:[…] } shape.
 */
class PageContentConverter
{
    /** Wrap HTML in the Lexical page-content envelope the editor expects. */
    public static function htmlToPageContent(string $html): array
    {
        return ['type' => 'lexical', 'html' => $html];
    }

    /** Pull the editable HTML out of a stored page content value (any format). */
    public static function pageContentToHtml(mixed $content): string
    {
        if (! is_array($content)) {
            return '';
        }

        // Current Lexical format.
        if (($content['type'] ?? null) === 'lexical' && is_string($content['html'] ?? null)) {
            return $content['html'];
        }

        // Legacy TipTap doc → render to HTML via Markdown bridge.
        if (($content['type'] ?? null) === 'doc') {
            return self::markdownToHtml(self::docToMarkdown($content));
        }

        return '';
    }

    // ── Markdown → HTML ──────────────────────────────────────────────────────

    public static function markdownToHtml(string $markdown): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown);
        $html = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            // GFM table: a header row, then the |---|:--:| delimiter row with the same cell count.
            if (self::tableStartsAt($lines, $i)) {
                [$table, $i] = self::table($lines, $i, $count);
                $html[] = $table;

                continue;
            }

            // Fenced code block.
            if (preg_match('/^```\s*([\w+-]*)\s*$/', $line, $m)) {
                $code = [];
                $i++;
                while ($i < $count && ! preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;
                $lang = $m[1] ? ' class="language-'.$m[1].'"' : '';
                $html[] = '<pre><code'.$lang.'>'.self::esc(implode("\n", $code)).'</code></pre>';

                continue;
            }

            // Horizontal rule.
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $html[] = '<hr>';
                $i++;

                continue;
            }

            // Heading.
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]);
                $html[] = "<h{$level}>".self::inline(trim($m[2]))."</h{$level}>";
                $i++;

                continue;
            }

            // Blockquote.
            if (preg_match('/^>\s?(.*)$/', $line)) {
                $quote = [];
                while ($i < $count && preg_match('/^>\s?(.*)$/', $lines[$i], $m)) {
                    $quote[] = $m[1];
                    $i++;
                }
                $html[] = '<blockquote><p>'.self::inline(trim(implode(' ', $quote))).'</p></blockquote>';

                continue;
            }

            // Bullet list.
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line)) {
                [$items, $i] = self::collectList($lines, $i, $count, '/^\s*[-*+]\s+(.*)$/');
                $html[] = '<ul>'.implode('', array_map(fn ($t) => '<li>'.self::inline($t).'</li>', $items)).'</ul>';

                continue;
            }

            // Ordered list.
            if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line)) {
                [$items, $i] = self::collectList($lines, $i, $count, '/^\s*\d+[.)]\s+(.*)$/');
                $html[] = '<ol>'.implode('', array_map(fn ($t) => '<li>'.self::inline($t).'</li>', $items)).'</ol>';

                continue;
            }

            // Paragraph (gather consecutive plain lines).
            $para = [$line];
            $i++;
            while ($i < $count
                && trim($lines[$i]) !== ''
                && ! self::tableStartsAt($lines, $i)
                && ! preg_match('/^(#{1,6}\s|```|>\s?|\s*[-*+]\s+|\s*\d+[.)]\s+|(-{3,}|\*{3,}|_{3,})\s*$)/', $lines[$i])) {
                $para[] = $lines[$i];
                $i++;
            }
            $html[] = '<p>'.self::inline(trim(implode(' ', $para))).'</p>';
        }

        return implode('', $html);
    }

    /** One GFM delimiter cell: `---`, `:--`, `--:` or `:-:`. */
    private const DELIMITER_CELL = '/^:?-+:?$/';

    /**
     * A GFM table starts here: this line has a pipe and the next one is a delimiter row with as many cells.
     *
     * @param  array<int, string>  $lines
     */
    private static function tableStartsAt(array $lines, int $i): bool
    {
        $header = $lines[$i] ?? null;
        $delimiter = $lines[$i + 1] ?? null;
        if ($header === null || $delimiter === null || ! self::hasPipe($header) || ! str_contains($delimiter, '|')) {
            return false;
        }
        // indented 4+ spaces is code in CommonMark, not a table
        if (preg_match('/^ {4,}/', $header)) {
            return false;
        }
        $cells = self::tableCells($delimiter);
        foreach ($cells as $cell) {
            if (! preg_match(self::DELIMITER_CELL, $cell)) {
                return false;
            }
        }

        return $cells !== [] && count($cells) === count(self::tableCells($header));
    }

    /**
     * The table starting at $i (tableStartsAt) → HTML in the shape the Lexical editor imports as its own table:
     * every cell holds a paragraph, the header row uses <th>, a column's alignment is its paragraphs' text-align
     * (Lexical keeps alignment on the paragraph, not the cell). Body rows run until a blank line or a line without
     * a pipe; a short row is padded, a long one cut to the header's width, like GitHub does.
     *
     * @param  array<int, string>  $lines
     * @return array{0: string, 1: int}
     */
    private static function table(array $lines, int $i, int $count): array
    {
        $header = self::tableCells($lines[$i]);
        $aligns = array_map(fn (string $cell) => match (true) {
            str_starts_with($cell, ':') && str_ends_with($cell, ':') => 'center',
            str_ends_with($cell, ':') => 'right',
            str_starts_with($cell, ':') => 'left',
            default => null,
        }, self::tableCells($lines[$i + 1]));
        $end = self::tableEnd($lines, $i, $count);
        $rows = array_map(fn (string $line) => self::tableCells($line), array_slice($lines, $i + 2, $end - $i - 2));
        $i = $end;

        $width = count($header);
        $row = function (array $cells, string $tag) use ($width, $aligns): string {
            $out = '<tr>';
            for ($c = 0; $c < $width; $c++) {
                $style = $aligns[$c] !== null ? ' style="text-align: '.$aligns[$c].';"' : '';
                $text = str_replace('\|', '|', $cells[$c] ?? '');
                $out .= "<{$tag}><p{$style}>".self::inline($text)."</p></{$tag}>";
            }

            return $out.'</tr>';
        };

        $html = '<table><tbody>'.$row($header, 'th');
        foreach ($rows as $cells) {
            $html .= $row($cells, 'td');
        }

        return [$html.'</tbody></table>', $i];
    }

    /**
     * The line after the last body row of the table starting at $i.
     *
     * @param  array<int, string>  $lines
     */
    private static function tableEnd(array $lines, int $i, int $count): int
    {
        $i += 2;
        while ($i < $count && trim($lines[$i]) !== '' && self::hasPipe($lines[$i])
            && ! preg_match('/^(#{1,6}\s|```|>)/', $lines[$i])) {
            $i++;
        }

        return $i;
    }

    /**
     * Where markdownToHtml() would read GFM tables in these lines: [first line, line after the last row] each
     * (MarkdownTableRepair uses it to find what to rebuild). Ignores fenced code like markdownToHtml does.
     *
     * @param  list<string>  $lines
     * @return list<array{0: int, 1: int}>
     */
    public static function tableSpans(array $lines): array
    {
        $spans = [];
        $count = count($lines);
        $fenced = false;
        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^```/', $lines[$i])) {
                $fenced = ! $fenced;

                continue;
            }
            if (! $fenced && self::tableStartsAt($lines, $i)) {
                $end = self::tableEnd($lines, $i, $count);
                $spans[] = [$i, $end];
                $i = $end - 1;
            }
        }

        return $spans;
    }

    /** A pipe that isn't escaped (`\|` is a literal pipe). */
    private static function hasPipe(string $line): bool
    {
        return (bool) preg_match('/(?<!\\\\)\|/', $line);
    }

    /**
     * The trimmed cells of a table row: an optional leading / trailing pipe, split on unescaped pipes
     * (escaped ones stay `\|` here, the cell text turns them into `|`).
     *
     * @return list<string>
     */
    private static function tableCells(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (preg_match('/(?<!\\\\)\|$/', $line)) {
            $line = substr($line, 0, -1);
        }

        return array_map('trim', preg_split('/(?<!\\\\)\|/', $line) ?: []);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private static function collectList(array $lines, int $i, int $count, string $pattern): array
    {
        $items = [];
        while ($i < $count && preg_match($pattern, $lines[$i], $m)) {
            $items[] = trim($m[1]);
            $i++;
        }

        return [$items, $i];
    }

    /** Inline Markdown (bold, italic, code, links) → HTML, escaping the rest. */
    private static function inline(string $text): string
    {
        // Tokenise so we can escape literal text but keep generated tags.
        // images first: `![alt](src)` would otherwise read as "!" + a link
        $pattern = '/(!\[([^\]]*)\]\((\S+?)\)|\*\*(.+?)\*\*|__(.+?)__|\*(.+?)\*|_(.+?)_|`(.+?)`|\[(.+?)\]\((\S+?)\))/';
        $out = '';
        $offset = 0;

        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            if ($start > $offset) {
                $out .= self::esc(substr($text, $offset, $start - $offset));
            }

            $token = $m[0][0];
            if (Str::startsWith($token, '![')) {
                // http(s) or a path on this site only (no data: / javascript:)
                $src = self::safeHref($m[3][0]);
                $out .= $src !== null && ! str_starts_with(strtolower($src), 'mailto:')
                    ? '<img src="'.self::esc($src).'" alt="'.self::esc($m[2][0]).'">'
                    : self::esc($m[2][0]);
            } elseif (Str::startsWith($token, '**') || Str::startsWith($token, '__')) {
                $out .= '<strong>'.self::esc($m[4][0] !== '' ? $m[4][0] : $m[5][0]).'</strong>';
            } elseif (Str::startsWith($token, '`')) {
                $out .= '<code>'.self::esc($m[8][0]).'</code>';
            } elseif (Str::startsWith($token, '[')) {
                $out .= self::safeHref($m[10][0]) !== null
                    ? '<a href="'.self::esc(self::safeHref($m[10][0])).'">'.self::esc($m[9][0]).'</a>'
                    : self::esc($m[9][0]);
            } else {
                $out .= '<em>'.self::esc($m[6][0] !== '' ? $m[6][0] : $m[7][0]).'</em>';
            }

            $offset = $start + strlen($token);
        }

        if ($offset < strlen($text)) {
            $out .= self::esc(substr($text, $offset));
        }

        return $out;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── HTML → Markdown ──────────────────────────────────────────────────────

    public static function htmlToMarkdown(?string $html): string
    {
        if (! is_string($html) || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        // Force UTF-8 and wrap so a fragment parses predictably.
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = $dom->getElementById('__root');
        if (! $root) {
            return trim(strip_tags($html));
        }

        $blocks = [];
        foreach ($root->childNodes as $child) {
            $rendered = self::block($child);
            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return trim(implode("\n\n", $blocks));
    }

    private static function block(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return trim($node->textContent) === '' ? '' : trim($node->textContent);
        }

        $name = strtolower($node->nodeName);

        return match ($name) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => str_repeat('#', (int) substr($name, 1)).' '.self::inlineText($node),
            'p', 'div' => self::inlineText($node),
            'pre' => "```\n".trim($node->textContent)."\n```",
            'blockquote' => '> '.str_replace("\n", "\n> ", trim(self::inlineText($node))),
            'hr' => '---',
            'ul' => self::list($node, false),
            'ol' => self::list($node, true),
            'table' => self::tableMarkdown($node),
            default => self::inlineText($node),
        };
    }

    /** Block elements a table cell may hold (the rest is inline content). */
    private const BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'blockquote', 'hr', 'ul', 'ol', 'table'];

    /**
     * A table → a GFM table: the first row is the header (GFM has no header-less table), cells are one line each
     * (blocks and line breaks inside a cell joined by spaces, pipes escaped), a colspan repeats as empty cells, short
     * rows are padded. Alignment comes from the header cells' paragraphs (the editor) or the cells (`align` / style).
     */
    private static function tableMarkdown(DOMNode $table): string
    {
        $rows = [];
        $aligns = [];
        foreach (self::tableRows($table) as $r => $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                $tag = strtolower($cell->nodeName);
                if ($tag !== 'td' && $tag !== 'th') {
                    continue;
                }
                $parts = [];
                foreach ($cell->childNodes as $child) {
                    $parts[] = in_array(strtolower($child->nodeName), self::BLOCKS, true) ? self::block($child) : self::inlineNode($child);
                }
                $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_map('trim', $parts))));
                $text = (string) preg_replace('/(?<!\\\\)\|/', '\\|', $text);
                if ($r === 0) {
                    $aligns[count($cells)] = self::cellAlign($cell);
                }
                $cells[] = $text;
                $span = (int) ($cell->attributes?->getNamedItem('colspan')?->nodeValue ?? 1);
                for ($extra = 1; $extra < min($span, 50); $extra++) {
                    $cells[] = '';
                }
            }
            $rows[] = $cells;
        }
        if ($rows === []) {
            return '';
        }

        $width = max(1, ...array_map('count', $rows));
        $line = fn (array $cells) => '| '.implode(' | ', array_pad($cells, $width, '')).' |';
        $delimiter = [];
        for ($c = 0; $c < $width; $c++) {
            $delimiter[] = match ($aligns[$c] ?? null) {
                'left' => ':---',
                'center' => ':---:',
                'right' => '---:',
                default => '---',
            };
        }

        $out = [$line($rows[0]), '| '.implode(' | ', $delimiter).' |'];
        foreach (array_slice($rows, 1) as $cells) {
            $out[] = $line($cells);
        }

        return implode("\n", $out);
    }

    /**
     * The <tr> of a table, also inside <thead> / <tbody> / <tfoot>.
     *
     * @return list<DOMNode>
     */
    private static function tableRows(DOMNode $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            $tag = strtolower($child->nodeName);
            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $tr) {
                    if (strtolower($tr->nodeName) === 'tr') {
                        $rows[] = $tr;
                    }
                }
            }
        }

        return $rows;
    }

    /** left / center / right from a cell's `align`, its style or its first paragraph's style; null for the default
     * (the editor writes `text-align: start` on every cell, which is the default). */
    private static function cellAlign(DOMNode $cell): ?string
    {
        $candidates = [$cell];
        foreach ($cell->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $candidates[] = $child;
                break;
            }
        }
        foreach ($candidates as $node) {
            $align = strtolower(trim((string) ($node->attributes?->getNamedItem('align')?->nodeValue ?? '')));
            if ($align === '' && preg_match('/text-align\s*:\s*(\w+)/i', (string) ($node->attributes?->getNamedItem('style')?->nodeValue ?? ''), $m)) {
                $align = strtolower($m[1]);
            }
            if (in_array($align, ['left', 'center', 'right'], true)) {
                return $align;
            }
        }

        return null;
    }

    private static function list(DOMNode $node, bool $ordered): string
    {
        $out = [];
        $n = 1;
        foreach ($node->childNodes as $li) {
            if (strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $prefix = $ordered ? ($n++).'. ' : '- ';
            $out[] = $prefix.trim(self::inlineText($li));
        }

        return implode("\n", $out);
    }

    /**
     * Render inline children of a node to Markdown. `$active` are the marks an ancestor already wrote: the editor
     * exports bold as <b><strong>…</strong></b> (italic <i><em>), which must read as **x**, not ****x****.
     *
     * @param  list<string>  $active
     */
    private static function inlineText(DOMNode $node, array $active = []): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::inlineNode($child, $active);
        }

        return $out;
    }

    /** @param  list<string>  $active */
    private static function inlineNode(DOMNode $child, array $active = []): string
    {
        if ($child->nodeType === XML_TEXT_NODE) {
            return $child->textContent;
        }

        $name = strtolower($child->nodeName);
        $mark = match ($name) {
            'strong', 'b' => '**',
            'em', 'i' => '*',
            'code' => '`',
            default => null,
        };
        if ($mark !== null) {
            if (in_array($mark, $active, true)) {
                return self::inlineText($child, $active);
            }

            return $mark.self::inlineText($child, [...$active, $mark]).$mark;
        }

        $inner = self::inlineText($child, $active);

        return match ($name) {
            'a' => '['.$inner.']('.($child->attributes?->getNamedItem('href')?->nodeValue ?? '').')',
            'img' => '!['.str_replace([']', "\n"], ['', ' '], (string) ($child->attributes?->getNamedItem('alt')?->nodeValue ?? '')).']('.($child->attributes?->getNamedItem('src')?->nodeValue ?? '').')',
            'br' => "\n",
            default => $inner,
        };
    }

    // ── Legacy TipTap doc → Markdown (pages created before Lexical) ───────────

    /** @param array<string, mixed>|null $doc */
    public static function docToMarkdown(?array $doc): string
    {
        if (! is_array($doc) || ! is_array($doc['content'] ?? null)) {
            return '';
        }

        $blocks = [];
        foreach ($doc['content'] as $node) {
            $rendered = self::legacyBlock($node);
            if ($rendered !== null) {
                $blocks[] = $rendered;
            }
        }

        return trim(implode("\n\n", $blocks));
    }

    /** @param array<string, mixed> $node */
    private static function legacyBlock(array $node): ?string
    {
        $type = $node['type'] ?? null;
        $text = self::legacyInline($node['content'] ?? []);

        return match ($type) {
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 1)).' '.$text,
            'paragraph' => $text,
            'codeBlock' => "```\n".$text."\n```",
            'bulletList', 'orderedList' => self::legacyList($node),
            default => $text ?: null,
        };
    }

    /** @param array<string, mixed> $node */
    private static function legacyList(array $node): string
    {
        $ordered = ($node['type'] ?? '') === 'orderedList';
        $out = [];
        $n = 1;
        foreach ($node['content'] ?? [] as $item) {
            $inner = '';
            foreach ($item['content'] ?? [] as $child) {
                $inner .= self::legacyInline($child['content'] ?? []);
            }
            $out[] = ($ordered ? ($n++).'. ' : '- ').$inner;
        }

        return implode("\n", $out);
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private static function legacyInline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'text') {
                $out .= self::legacyInline($node['content'] ?? []);

                continue;
            }
            $t = $node['text'] ?? '';
            foreach ($node['marks'] ?? [] as $mark) {
                $t = match ($mark['type'] ?? '') {
                    'bold' => "**{$t}**",
                    'italic' => "*{$t}*",
                    'code' => "`{$t}`",
                    'link' => '['.$t.']('.($mark['attrs']['href'] ?? '').')',
                    default => $t,
                };
            }
            $out .= $t;
        }

        return $out;
    }

    /** Link targets an AI client may write: http(s), mailto or a relative path — never javascript: & co. */
    private static function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $href)) {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
            return preg_match('#^(https?://|mailto:)#i', $href) ? $href : null;
        }

        return str_starts_with($href, '//') ? null : $href;
    }
}
