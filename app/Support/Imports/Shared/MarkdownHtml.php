<?php

namespace App\Support\Imports\Shared;

use App\Support\SafeHtml;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown from another tool → sanitized HTML the page editor (Lexical) and issue descriptions read
 * (v1.41.0 imports). GitHub-flavoured: tables, strikethrough, autolinks, nested lists, fenced code.
 * Unlike PageContentConverter::markdownToHtml (the small MCP converter), this one handles tables,
 * images and nested lists, which exports are full of.
 *
 * The output always went through SafeHtml. Two things SafeHtml would drop are put back afterwards,
 * from values this class controls: task lists ("- [ ] x") become <li aria-checked> (Lexical's check
 * list) and a fenced code block's language becomes data-language on its <pre>.
 */
final class MarkdownHtml
{
    /** SafeHtml truncates longer input; callers should cap their Markdown below this. */
    public const MAX_HTML = 500_000;

    private static ?MarkdownConverter $converter = null;

    private static ?MarkdownConverter $converterWithBreaks = null;

    /**
     * @param  bool  $lineBreaks  single newlines become <br> (Notion: one block per line)
     */
    public static function toHtml(string $markdown, bool $lineBreaks = false): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (str_starts_with($markdown, "\u{FEFF}")) {
            $markdown = substr($markdown, 3);
        }
        if (trim($markdown) === '') {
            return '';
        }

        $raw = (string) self::converter($lineBreaks)->convert($markdown);
        preg_match_all('#<pre><code(?: class="language-([^"]*)")?>#', $raw, $languages);

        $html = (string) SafeHtml::clean($raw);

        return self::restore($html, $languages[1]);
    }

    /** Escaped text for HTML built by an importer. */
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param list<string> $languages */
    private static function restore(string $html, array $languages): string
    {
        // "- [ ] todo" / "- [x] done" (no TaskListExtension: SafeHtml would strip its <input>)
        $html = preg_replace_callback('#<li>(\s*(?:<p>)?)\[( |x|X)\]\s+#u', fn ($m) => '<li aria-checked="'.($m[2] === ' ' ? 'false' : 'true').'">'.$m[1], $html) ?? $html;

        $index = 0;

        return preg_replace_callback('#<pre>(?=<code)#', function () use (&$index, $languages) {
            $language = $languages[$index++] ?? '';

            return preg_match('/^[A-Za-z0-9_+#.-]{1,30}$/', $language) ? '<pre data-language="'.strtolower($language).'">' : '<pre>';
        }, $html) ?? $html;
    }

    private static function converter(bool $lineBreaks): MarkdownConverter
    {
        $existing = $lineBreaks ? self::$converterWithBreaks : self::$converter;
        if ($existing !== null) {
            return $existing;
        }

        $environment = new Environment([
            'html_input' => 'allow', // sanitized right after (SafeHtml), so callouts / <details> survive
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => $lineBreaks ? "<br>\n" : "\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new AutolinkExtension);
        $converter = new MarkdownConverter($environment);

        return $lineBreaks ? (self::$converterWithBreaks = $converter) : (self::$converter = $converter);
    }
}
