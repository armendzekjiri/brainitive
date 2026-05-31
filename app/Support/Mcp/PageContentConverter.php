<?php

namespace App\Support\Mcp;

use Illuminate\Support\Str;

/**
 * Converts between Markdown (what an AI assistant naturally produces) and the
 * TipTap / ProseMirror document JSON that Brainitive pages store:
 *
 *   { "type": "doc", "content": [ { "type": "paragraph", ... }, ... ] }
 *
 * The page editor is TipTap StarterKit (see resources/js/components/editor/
 * PageEditor.tsx), so the node names here match StarterKit's schema.
 *
 * Supported block nodes: heading, paragraph, bulletList, orderedList,
 * blockquote, codeBlock, horizontalRule. Supported inline marks: bold,
 * italic, code, link. Anything else degrades to plain paragraphs so a round
 * trip never loses the user's text.
 */
class PageContentConverter
{
    /**
     * Markdown string -> TipTap doc array.
     *
     * @return array{type: string, content: array<int, array<string, mixed>>}
     */
    public static function markdownToDoc(string $markdown): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown);
        $nodes = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Blank line — skip.
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block: ```lang ... ```
            if (preg_match('/^```\s*([\w+-]*)\s*$/', $line, $m)) {
                $language = $m[1] ?: null;
                $code = [];
                $i++;
                while ($i < $count && ! preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // consume closing fence
                $nodes[] = [
                    'type' => 'codeBlock',
                    'attrs' => ['language' => $language],
                    'content' => self::textContent(implode("\n", $code)),
                ];
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $nodes[] = ['type' => 'horizontalRule'];
                $i++;
                continue;
            }

            // ATX heading: # .. ######
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $nodes[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => strlen($m[1])],
                    'content' => self::inlineToNodes(trim($m[2])),
                ];
                $i++;
                continue;
            }

            // Blockquote (consecutive `>` lines).
            if (preg_match('/^>\s?(.*)$/', $line)) {
                $quote = [];
                while ($i < $count && preg_match('/^>\s?(.*)$/', $lines[$i], $m)) {
                    $quote[] = $m[1];
                    $i++;
                }
                $nodes[] = [
                    'type' => 'blockquote',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => self::inlineToNodes(trim(implode(' ', $quote))),
                    ]],
                ];
                continue;
            }

            // Bullet list (-, *, +).
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line)) {
                [$items, $i] = self::collectListItems($lines, $i, $count, '/^\s*[-*+]\s+(.*)$/');
                $nodes[] = ['type' => 'bulletList', 'content' => $items];
                continue;
            }

            // Ordered list (1. 2. ...).
            if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line)) {
                [$items, $i] = self::collectListItems($lines, $i, $count, '/^\s*\d+[.)]\s+(.*)$/');
                $nodes[] = ['type' => 'orderedList', 'content' => $items];
                continue;
            }

            // Otherwise a paragraph — gather consecutive non-blank, non-block lines.
            $para = [$line];
            $i++;
            while ($i < $count
                && trim($lines[$i]) !== ''
                && ! preg_match('/^(#{1,6}\s|```|>\s?|\s*[-*+]\s+|\s*\d+[.)]\s+|(-{3,}|\*{3,}|_{3,})\s*$)/', $lines[$i])) {
                $para[] = $lines[$i];
                $i++;
            }
            $nodes[] = [
                'type' => 'paragraph',
                'content' => self::inlineToNodes(trim(implode(' ', $para))),
            ];
        }

        return ['type' => 'doc', 'content' => $nodes];
    }

    /**
     * TipTap doc array -> Markdown string.
     *
     * @param  array<string, mixed>|null  $doc
     */
    public static function docToMarkdown(?array $doc): string
    {
        if (! is_array($doc)) {
            return '';
        }

        $content = $doc['content'] ?? [];
        if (! is_array($content)) {
            return '';
        }

        $blocks = [];
        foreach ($content as $node) {
            $rendered = self::renderBlock($node);
            if ($rendered !== null) {
                $blocks[] = $rendered;
            }
        }

        return trim(implode("\n\n", $blocks));
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function collectListItems(array $lines, int $i, int $count, string $pattern): array
    {
        $items = [];
        while ($i < $count && preg_match($pattern, $lines[$i], $m)) {
            $items[] = [
                'type' => 'listItem',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => self::inlineToNodes(trim($m[1])),
                ]],
            ];
            $i++;
        }

        return [$items, $i];
    }

    /** Wrap plain text as a single text node array (empty -> empty array). */
    private static function textContent(string $text): array
    {
        return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
    }

    /**
     * Parse inline markdown (bold, italic, code, links) into TipTap text nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function inlineToNodes(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $nodes = [];
        $pattern = '/(\*\*(.+?)\*\*|__(.+?)__|\*(.+?)\*|_(.+?)_|`(.+?)`|\[(.+?)\]\((\S+?)\))/';
        $offset = 0;

        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            if ($start > $offset) {
                $nodes[] = ['type' => 'text', 'text' => substr($text, $offset, $start - $offset)];
            }

            $token = $m[0][0];
            if (Str::startsWith($token, '**') || Str::startsWith($token, '__')) {
                $nodes[] = self::markedText($m[2][0] !== '' ? $m[2][0] : $m[3][0], [['type' => 'bold']]);
            } elseif (Str::startsWith($token, '`')) {
                $nodes[] = self::markedText($m[6][0], [['type' => 'code']]);
            } elseif (Str::startsWith($token, '[')) {
                $nodes[] = self::markedText($m[7][0], [['type' => 'link', 'attrs' => ['href' => $m[8][0]]]]);
            } else { // single * or _ => italic
                $nodes[] = self::markedText($m[4][0] !== '' ? $m[4][0] : $m[5][0], [['type' => 'italic']]);
            }

            $offset = $start + strlen($token);
        }

        if ($offset < strlen($text)) {
            $nodes[] = ['type' => 'text', 'text' => substr($text, $offset)];
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function markedText(string $text, array $marks): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /** @param  array<string, mixed>  $node */
    private static function renderBlock(array $node, int $depth = 0): ?string
    {
        $type = $node['type'] ?? null;

        return match ($type) {
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 1)).' '.self::renderInline($node['content'] ?? []),
            'paragraph' => self::renderInline($node['content'] ?? []),
            'codeBlock' => "```".($node['attrs']['language'] ?? '')."\n".self::renderInline($node['content'] ?? [], true)."\n```",
            'blockquote' => self::renderQuote($node['content'] ?? []),
            'horizontalRule' => '---',
            'bulletList' => self::renderList($node['content'] ?? [], false),
            'orderedList' => self::renderList($node['content'] ?? [], true),
            default => self::renderInline($node['content'] ?? []) ?: null,
        };
    }

    /** @param array<int, array<string,mixed>> $items */
    private static function renderList(array $items, bool $ordered): string
    {
        $out = [];
        $n = 1;
        foreach ($items as $item) {
            $inner = '';
            foreach ($item['content'] ?? [] as $child) {
                $inner .= self::renderInline($child['content'] ?? []);
            }
            $prefix = $ordered ? ($n++).'. ' : '- ';
            $out[] = $prefix.$inner;
        }

        return implode("\n", $out);
    }

    /** @param array<int, array<string,mixed>> $content */
    private static function renderQuote(array $content): string
    {
        $lines = [];
        foreach ($content as $child) {
            $lines[] = '> '.self::renderInline($child['content'] ?? []);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private static function renderInline(array $nodes, bool $raw = false): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'text') {
                // Nested unknown inline node — render its children if present.
                $out .= self::renderInline($node['content'] ?? []);
                continue;
            }

            $text = $node['text'] ?? '';
            if ($raw) {
                $out .= $text;
                continue;
            }

            foreach ($node['marks'] ?? [] as $mark) {
                $text = match ($mark['type'] ?? '') {
                    'bold' => "**{$text}**",
                    'italic' => "*{$text}*",
                    'code' => "`{$text}`",
                    'link' => '['.$text.']('.($mark['attrs']['href'] ?? '').')',
                    default => $text,
                };
            }
            $out .= $text;
        }

        return $out;
    }
}
