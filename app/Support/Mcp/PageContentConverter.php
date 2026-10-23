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
                && ! preg_match('/^(#{1,6}\s|```|>\s?|\s*[-*+]\s+|\s*\d+[.)]\s+|(-{3,}|\*{3,}|_{3,})\s*$)/', $lines[$i])) {
                $para[] = $lines[$i];
                $i++;
            }
            $html[] = '<p>'.self::inline(trim(implode(' ', $para))).'</p>';
        }

        return implode('', $html);
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
        $pattern = '/(\*\*(.+?)\*\*|__(.+?)__|\*(.+?)\*|_(.+?)_|`(.+?)`|\[(.+?)\]\((\S+?)\))/';
        $out = '';
        $offset = 0;

        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            if ($start > $offset) {
                $out .= self::esc(substr($text, $offset, $start - $offset));
            }

            $token = $m[0][0];
            if (Str::startsWith($token, '**') || Str::startsWith($token, '__')) {
                $out .= '<strong>'.self::esc($m[2][0] !== '' ? $m[2][0] : $m[3][0]).'</strong>';
            } elseif (Str::startsWith($token, '`')) {
                $out .= '<code>'.self::esc($m[6][0]).'</code>';
            } elseif (Str::startsWith($token, '[')) {
                $out .= self::safeHref($m[8][0]) !== null
                    ? '<a href="'.self::esc(self::safeHref($m[8][0])).'">'.self::esc($m[7][0]).'</a>'
                    : self::esc($m[7][0]);
            } else {
                $out .= '<em>'.self::esc($m[4][0] !== '' ? $m[4][0] : $m[5][0]).'</em>';
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
            default => self::inlineText($node),
        };
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

    /** Render inline children of a node to Markdown. */
    private static function inlineText(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->textContent;

                continue;
            }

            $name = strtolower($child->nodeName);
            $inner = self::inlineText($child);
            $out .= match ($name) {
                'strong', 'b' => "**{$inner}**",
                'em', 'i' => "*{$inner}*",
                'code' => "`{$inner}`",
                'a' => '['.$inner.']('.($child->attributes?->getNamedItem('href')?->nodeValue ?? '').')',
                'br' => "\n",
                default => $inner,
            };
        }

        return $out;
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
