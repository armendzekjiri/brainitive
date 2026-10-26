<?php

namespace App\Support\Imports\Jira;

use App\Support\Mcp\PageContentConverter;
use App\Support\SafeHtml;

/**
 * Jira text → sanitized HTML for issue descriptions and comments (v1.41.0, BRAI-139).
 *
 * Jira CSV exports hold wiki markup (Server/DC, and Cloud converts its documents to it); text that
 * clearly is Markdown (fenced code, **bold**, [text](url)) goes through PageContentConverter instead.
 *
 * Covered wiki markup: h1.–h6., bq., {quote}, {code[:lang]}, {noformat}, {panel} (as a quote),
 * nested * / # / - lists, ||header|| and |cell| tables, ----, *bold*, _italic_, -strike-, +under+,
 * ^sup^, ~sub~, ??cite??, {{monospace}}, [text|url], [url], bare URLs, [~mention], [^attachment],
 * !image!, \\ line breaks and \-escapes. {color} and {anchor} are dropped (text kept).
 * Nothing is fetched: images become text (attachments aren't imported) or links (external URLs).
 * The result always goes through SafeHtml.
 */
final class JiraMarkup
{
    private const OPEN = "\u{E000}";

    private const CLOSE = "\u{E001}";

    /** @var list<string> placeholders (code spans, links, escapes) restored after formatting */
    private array $tokens = [];

    /** @param array<string, string> $people Jira account id / user name → display name (for [~mentions]) */
    private function __construct(private readonly array $people) {}

    /** @param array<string, string> $people */
    public static function toHtml(?string $text, array $people = []): string
    {
        $text = trim(str_replace(["\r\n", "\r", self::OPEN, self::CLOSE], ["\n", "\n", '', ''], (string) $text));
        if ($text === '') {
            return '';
        }
        $html = self::looksLikeMarkdown($text)
            ? PageContentConverter::markdownToHtml($text)
            : (new self($people))->blocks($text);

        return (string) SafeHtml::clean($html);
    }

    /** Markdown only with unambiguous markers and no wiki-only syntax (`# item` is a wiki list). */
    public static function looksLikeMarkdown(string $text): bool
    {
        if (preg_match('/^h[1-6]\.\s|^bq\.\s|\{code|\{noformat\}|\{quote\}|\|\||\[[^\]\n]*\|[^\]\n]*\]|\{\{/mi', $text)) {
            return false;
        }

        return (bool) preg_match('/^```|\*\*[^*\n]+\*\*|\[[^\]\n]+\]\((?:https?:|mailto:)|^>\s/m', $text);
    }

    private function blocks(string $text): string
    {
        $lines = explode("\n", $text);
        $count = count($lines);
        $out = [];
        $paragraph = [];
        $flush = function () use (&$paragraph, &$out) {
            if ($paragraph !== []) {
                $out[] = '<p>'.implode('<br>', array_map(fn ($line) => $this->inline($line), $paragraph)).'</p>';
                $paragraph = [];
            }
        };

        $i = 0;
        while ($i < $count) {
            $trim = trim($lines[$i]);

            // {code:java} … {code} / {noformat} … {noformat}
            if (preg_match('/^\{(code|noformat)(?::([^}]*))?\}(.*)$/is', $trim, $m)) {
                $flush();
                $tag = strtolower($m[1]);
                [$body, $i] = $this->collect($lines, $i, $tag, $m[3]);
                $language = $tag === 'code' ? $this->language($m[2] ?? '') : '';
                $out[] = '<pre><code'.($language !== '' ? ' class="language-'.$language.'"' : '').'>'.self::esc(implode("\n", $body)).'</code></pre>';

                continue;
            }

            // {quote} … {quote} / {panel:title=…} … {panel}
            if (preg_match('/^\{(quote|panel)(?::([^}]*))?\}(.*)$/is', $trim, $m)) {
                $flush();
                $tag = strtolower($m[1]);
                [$body, $i] = $this->collect($lines, $i, $tag, $m[3]);
                $title = $tag === 'panel' && preg_match('/(?:^|\|)title=([^|]*)/', $m[2] ?? '', $t) ? '<p><strong>'.$this->inline($t[1]).'</strong></p>' : '';
                $out[] = '<blockquote>'.$title.$this->blocks(implode("\n", $body)).'</blockquote>';

                continue;
            }

            if (preg_match('/^h([1-6])\.\s*(.*)$/s', $trim, $m)) {
                $flush();
                $out[] = "<h{$m[1]}>".$this->inline($m[2])."</h{$m[1]}>";
                $i++;

                continue;
            }

            if (preg_match('/^bq\.\s*(.*)$/s', $trim, $m)) {
                $flush();
                $out[] = '<blockquote><p>'.$this->inline($m[1]).'</p></blockquote>';
                $i++;

                continue;
            }

            if (preg_match('/^-{4,}$/', $trim)) {
                $flush();
                $out[] = '<hr>';
                $i++;

                continue;
            }

            if (str_starts_with($trim, '|')) {
                $flush();
                $rows = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                    $rows[] = trim($lines[$i]);
                    $i++;
                }
                $out[] = $this->table($rows);

                continue;
            }

            if (preg_match('/^([*#-]+)\s+\S/', $trim) && ! preg_match('/^-[^\s-]/', $trim)) {
                $flush();
                $items = [];
                while ($i < $count && preg_match('/^([*#-]+)\s+(.*)$/s', trim($lines[$i]), $m)) {
                    $items[] = [str_replace('-', '*', $m[1]), $m[2]];
                    $i++;
                }
                $out[] = $this->lists($items);

                continue;
            }

            if ($trim === '') {
                $flush();
                $i++;

                continue;
            }

            $paragraph[] = $trim;
            $i++;
        }
        $flush();

        return implode('', $out);
    }

    /**
     * The lines of a {tag}…{tag} block starting at line $i (the opening may already hold text).
     *
     * @param  list<string>  $lines
     * @return array{0: list<string>, 1: int} body lines and the index after the block
     */
    private function collect(array $lines, int $i, string $tag, string $rest): array
    {
        $close = '{'.$tag.'}';
        $at = stripos($rest, $close);
        if ($at !== false) {
            return [[substr($rest, 0, $at)], $i + 1];
        }
        $body = $rest !== '' ? [$rest] : [];
        $i++;
        while ($i < count($lines)) {
            $at = stripos($lines[$i], $close);
            if ($at !== false) {
                $before = substr($lines[$i], 0, $at);
                if (trim($before) !== '') {
                    $body[] = $before;
                }

                return [$body, $i + 1];
            }
            $body[] = $lines[$i];
            $i++;
        }

        return [$body, $i]; // unclosed: the rest of the text
    }

    private function language(string $params): string
    {
        foreach (explode('|', $params) as $param) {
            if ($param !== '' && ! str_contains($param, '=')) {
                return (string) preg_replace('/[^a-z0-9+#-]/', '', strtolower($param));
            }
        }

        return '';
    }

    /** @param list<array{0: string, 1: string}> $items marker string (e.g. "*#") and text */
    private function lists(array $items): string
    {
        $html = '';
        $stack = [];
        foreach ($items as [$markers, $content]) {
            $types = array_map(fn ($c) => $c === '#' ? 'ol' : 'ul', str_split($markers));
            $depth = count($types);
            $common = 0;
            while ($common < count($stack) && $common < $depth && $stack[$common] === $types[$common]) {
                $common++;
            }
            while (count($stack) > $common) {
                $html .= '</li></'.array_pop($stack).'>';
            }
            if (count($stack) === $depth) {
                $html .= '</li><li>';
            } else {
                while (count($stack) < $depth) {
                    $type = $types[count($stack)];
                    $html .= "<{$type}><li>";
                    $stack[] = $type;
                }
            }
            $html .= $this->inline($content);
        }
        while ($stack !== []) {
            $html .= '</li></'.array_pop($stack).'>';
        }

        return $html;
    }

    /** @param list<string> $rows lines starting with | */
    private function table(array $rows): string
    {
        $html = '<table><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($this->cells($row) as [$header, $content]) {
                $tag = $header ? 'th' : 'td';
                $html .= "<{$tag}>".$this->inline(trim($content))."</{$tag}>";
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * Split a table row on | and || (header cells), not inside [links] or {macros}.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function cells(string $row): array
    {
        $cells = [];
        $buffer = '';
        $depth = 0;
        $header = false;
        $started = false;
        $length = strlen($row);
        for ($k = 0; $k < $length; $k++) {
            $char = $row[$k];
            if ($char === '[' || $char === '{') {
                $depth++;
            } elseif (($char === ']' || $char === '}') && $depth > 0) {
                $depth--;
            }
            if ($char === '|' && $depth === 0 && ($k === 0 || $row[$k - 1] !== '\\')) {
                if ($started) {
                    $cells[] = [$header, $buffer];
                }
                $header = $k + 1 < $length && $row[$k + 1] === '|';
                if ($header) {
                    $k++;
                }
                $buffer = '';
                $started = true;

                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $cells[] = [$header, $buffer];
        }

        return $cells;
    }

    /** One line of text → escaped HTML with inline formatting. */
    private function inline(string $text): string
    {
        $text = (string) preg_replace('/\{(?:color(?::[^}]*)?|anchor(?::[^}]*)?)\}/i', '', $text);

        // protect what must not be formatted: line breaks, \-escapes, {{code}}, [links], !images!, URLs
        $text = str_replace('\\\\', $this->token('<br>'), $text);
        $text = (string) preg_replace_callback('/\\\\([*_\-+^~{}\[\]!|?#])/', fn ($m) => $this->token(self::esc($m[1])), $text);
        $text = (string) preg_replace_callback('/\{\{(.+?)\}\}/s', fn ($m) => $this->token('<code>'.self::esc($m[1]).'</code>'), $text);
        $text = (string) preg_replace_callback('/\[([^\[\]\n]+)\]/', fn ($m) => $this->token($this->link($m[1])), $text);
        $text = (string) preg_replace_callback('/!((?:https?:\/\/)?[^!\s|]+\.(?:png|jpe?g|gif|svg|webp|bmp)|https?:\/\/[^!\s|]+)(?:\|[^!\n]*)?!/i', fn ($m) => $this->token($this->image($m[1])), $text);
        $text = (string) preg_replace_callback('#\bhttps?://[^\s<>"\[\]{}|]+#i', function ($m) {
            $url = rtrim($m[0], '.,;:!?)*_');
            $rest = substr($m[0], strlen($url));

            return $this->token('<a href="'.self::esc($url).'">'.self::esc($url).'</a>').$rest;
        }, $text);

        return $this->restore($this->format(self::esc($text)));
    }

    /** Wiki emphasis on escaped text (placeholders contain no markup characters). */
    private function format(string $html): string
    {
        $rules = [
            '/(?<![\p{L}\p{N}*])\*(?=\S)(.+?)(?<=\S)\*(?![\p{L}\p{N}*])/u' => '<strong>$1</strong>',
            '/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u' => '<em>$1</em>',
            '/(?<![\p{L}\p{N}\-])-(?=[^\s\-])(.+?)(?<=[^\s\-])-(?![\p{L}\p{N}\-])/u' => '<s>$1</s>',
            '/(?<![\p{L}\p{N}+])\+(?=\S)(.+?)(?<=\S)\+(?![\p{L}\p{N}+])/u' => '<u>$1</u>',
            '/\^(?=\S)([^\^\s]+?)\^/u' => '<sup>$1</sup>', // x^2^: no word boundary needed
            '/~(?=\S)([^~\s]+?)~/u' => '<sub>$1</sub>',
            '/\?\?(?=\S)(.+?)(?<=\S)\?\?/u' => '<cite>$1</cite>',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $html);
    }

    /** [text|url], [url], [~mention], [^attachment], [#anchor] */
    private function link(string $content): string
    {
        if (str_starts_with($content, '~')) {
            $who = substr($content, 1);
            $id = str_starts_with($who, 'accountid:') ? substr($who, 10) : $who;
            $name = $this->people[$id] ?? $this->people[$who] ?? (preg_match('/^[0-9a-f]{6,}:|^[0-9a-f-]{20,}$/i', $id) ? 'a Jira user' : $id);

            return '<strong>@'.self::esc($name).'</strong>';
        }
        if (str_starts_with($content, '^')) {
            return '<em>'.self::esc(substr($content, 1)).' (attachment, not imported)</em>';
        }

        $parts = explode('|', $content);
        $label = count($parts) > 1 ? $parts[0] : null;
        $url = trim(count($parts) > 1 ? $parts[1] : $parts[0]);
        if (! preg_match('#^(?:https?://|mailto:)#i', $url)) {
            return self::esc($label ?? $content); // an anchor, an issue key, a smart link: keep the text
        }
        $text = $label !== null && trim($label) !== '' ? $this->format(self::esc($label)) : self::esc($url);

        return '<a href="'.self::esc($url).'">'.$text.'</a>';
    }

    private function image(string $source): string
    {
        if (preg_match('#^https?://#i', $source)) {
            return '<a href="'.self::esc($source).'">'.self::esc($source).'</a>';
        }

        return '<em>[image: '.self::esc($source).' (not imported)]</em>';
    }

    private function token(string $html): string
    {
        $this->tokens[] = $html;

        return self::OPEN.(count($this->tokens) - 1).self::CLOSE;
    }

    private function restore(string $html): string
    {
        $pattern = '/'.self::OPEN.'(\d+)'.self::CLOSE.'/u';
        // tokens may contain tokens (a link label with a code span): resolve until none are left
        for ($round = 0; $round < 5 && str_contains($html, self::OPEN); $round++) {
            $html = (string) preg_replace_callback($pattern, fn ($m) => $this->tokens[(int) $m[1]] ?? '', $html);
        }

        return $html;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
