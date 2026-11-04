<?php

namespace Tests\Unit;

use App\Support\Mcp\PageContentConverter;
use App\Support\Pages\MarkdownTableRepair;
use PHPUnit\Framework\TestCase;

/**
 * GitHub-flavoured tables in the Markdown converter (MCP, App API, issues) and the repair of tables that were
 * stored as text before it knew them (pages:repair-markdown-tables).
 */
class MarkdownTablesTest extends TestCase
{
    private function html(string $markdown): string
    {
        return PageContentConverter::markdownToHtml($markdown);
    }

    public function test_a_table_becomes_the_editors_table_shape(): void
    {
        $this->assertSame(
            '<table><tbody>'
            .'<tr><th><p>Name</p></th><th><p>Qty</p></th></tr>'
            .'<tr><td><p>Apples</p></td><td><p>3</p></td></tr>'
            .'<tr><td><p>Pears</p></td><td><p>5</p></td></tr>'
            .'</tbody></table>',
            $this->html("| Name | Qty |\n|------|-----|\n| Apples | 3 |\n| Pears | 5 |"),
        );
    }

    public function test_alignment_becomes_the_paragraph_alignment_of_the_column(): void
    {
        $html = $this->html("| L | C | R | N |\n|:--|:-:|--:|---|\n| a | b | c | d |");

        $this->assertStringContainsString('<th><p style="text-align: left;">L</p></th>', $html);
        $this->assertStringContainsString('<td><p style="text-align: center;">b</p></td>', $html);
        $this->assertStringContainsString('<td><p style="text-align: right;">c</p></td>', $html);
        $this->assertStringContainsString('<td><p>d</p></td>', $html);
    }

    public function test_pipes_are_optional_at_the_edges_and_escaped_ones_stay_in_the_cell(): void
    {
        $html = $this->html("Command | Meaning\n--- | ---\n`a \\| b` | either a \\| b");

        $this->assertStringContainsString('<th><p>Command</p></th><th><p>Meaning</p></th>', $html);
        $this->assertStringContainsString('<td><p><code>a | b</code></p></td><td><p>either a | b</p></td>', $html);
    }

    public function test_inline_formatting_links_and_code_inside_cells_and_html_is_escaped(): void
    {
        $html = $this->html("| A | B |\n|---|---|\n| **bold** *it* | [site](https://example.com) `x` <script>alert(1)</script> |\n| [bad](javascript:alert(1)) | ok |");

        $this->assertStringContainsString('<td><p><strong>bold</strong> <em>it</em></p></td>', $html);
        $this->assertStringContainsString('<a href="https://example.com">site</a> <code>x</code> &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_ragged_rows_are_padded_or_cut_to_the_header(): void
    {
        $html = $this->html("| a | b | c |\n|---|---|---|\n| 1 |\n| 1 | 2 | 3 | 4 |");

        $this->assertStringContainsString('<tr><td><p>1</p></td><td><p></p></td><td><p></p></td></tr>', $html);
        $this->assertStringContainsString('<tr><td><p>1</p></td><td><p>2</p></td><td><p>3</p></td></tr>', $html);
        $this->assertStringNotContainsString('4', $html);
    }

    public function test_a_table_right_after_a_paragraph_or_heading_and_text_after_it(): void
    {
        $this->assertSame(
            '<p>Prices below:</p><table><tbody><tr><th><p>a</p></th></tr><tr><td><p>1</p></td></tr></tbody></table><p>That is all.</p>',
            $this->html("Prices below:\n| a |\n|---|\n| 1 |\nThat is all."),
        );
        $this->assertSame(
            '<h2>Plan</h2><table><tbody><tr><th><p>a</p></th><th><p>b</p></th></tr></tbody></table><ul><li>next</li></ul>',
            $this->html("## Plan\n| a | b |\n| --- | --- |\n- next"),
        );
    }

    public function test_pipes_that_are_not_a_table_stay_text(): void
    {
        $this->assertSame('<p>Use a | b or c | d here.</p>', $this->html('Use a | b or c | d here.'));
        // the delimiter row must match the header's cell count
        $this->assertSame('<p>| a | b | |---|</p>', $this->html("| a | b |\n|---|"));
        // no pipe in the delimiter row: a heading underline / rule, not a table
        $this->assertStringNotContainsString('<table>', $this->html("a | b\n---"));
        // inside a code block
        $this->assertSame('<pre><code>| a |'."\n".'|---|</code></pre>', $this->html("```\n| a |\n|---|\n```"));
    }

    public function test_tables_read_back_as_gfm_and_survive_a_round_trip(): void
    {
        $markdown = "Intro\n\n| Name | Qty \\| unit | Note |\n| :--- | :---: | ---: |\n| **a** | `x` | [l](https://example.com) |\n| b | 2 |  |\n\nAfter";

        $this->assertSame($markdown, PageContentConverter::htmlToMarkdown($this->html($markdown)));
        $this->assertSame($this->html($markdown), $this->html(PageContentConverter::htmlToMarkdown($this->html($markdown))));
    }

    public function test_the_editors_own_table_html_reads_as_gfm(): void
    {
        // what Lexical exports: colgroup, styled cells (text-align: start is the default), paragraphs, <b><strong>
        $html = '<table class="t"><colgroup><col style="width: 92px;"><col></colgroup><tbody><tr>'
            .'<th class="h" style="border: 1px solid black; width: 75px; vertical-align: top; text-align: start; background-color: rgb(242, 243, 245);">'
            .'<p class="p" dir="ltr"><b><strong class="b" style="white-space: pre-wrap;">Bold</strong></b></p></th>'
            .'<th colspan="2"><p dir="ltr" style="text-align: center;"><span>line<br>two</span></p></th></tr>'
            .'<tr><td><p>a|b</p><p>second</p></td></tr></tbody></table>';

        $this->assertSame(
            "| **Bold** | line two |  |\n| --- | :---: | --- |\n| a\\|b second |  |  |",
            PageContentConverter::htmlToMarkdown($html),
        );
    }

    public function test_imported_tables_read_alignment_from_the_cells(): void
    {
        $html = '<table><thead><tr><th align="right">h</th><th style="text-align:center">c</th></tr></thead><tbody><tr><td><strong>s</strong> t</td><td></td></tr></tbody></table>';

        $this->assertSame("| h | c |\n| ---: | :---: |\n| **s** t |  |", PageContentConverter::htmlToMarkdown($html));
    }

    public function test_bold_and_italic_the_editor_wraps_twice_read_once(): void
    {
        $this->assertSame(
            '**bold** and *it*',
            PageContentConverter::htmlToMarkdown('<p><b><strong class="x">bold</strong></b> and <i><em class="y">it</em></i></p>'),
        );
    }

    // ── repair ──────────────────────────────────────────────────────────────────────────────

    public function test_repair_rebuilds_a_table_the_old_converter_joined_into_one_paragraph(): void
    {
        $stored = '<h2>Stock</h2><p>Intro | Name | Qty | |---|--:| | <strong>a</strong> | 1 | | foo<em>bar | baz</em>qux | after</p><p>Keep <u>me</u></p>';

        $result = MarkdownTableRepair::repair($stored);

        $this->assertSame(1, $result['tables']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(
            '<h2>Stock</h2><p>Intro</p><table><tbody>'
            .'<tr><th><p>Name</p></th><th><p style="text-align: right;">Qty</p></th></tr>'
            .'<tr><td><p><strong>a</strong></p></td><td><p style="text-align: right;">1</p></td></tr>'
            .'<tr><td><p>foo_bar</p></td><td><p style="text-align: right;">baz_qux</p></td></tr>'
            .'</tbody></table><p>after</p><p>Keep <u>me</u></p>',
            $result['html'],
        );

        // idempotent
        $again = MarkdownTableRepair::repair($result['html']);
        $this->assertSame(['html' => $result['html'], 'tables' => 0, 'skipped' => 0], $again);
    }

    public function test_repair_rebuilds_one_paragraph_per_row_as_the_editor_saves_it(): void
    {
        $p = fn (string $text) => '<p class="editor-paragraph" dir="ltr"><span style="white-space: pre-wrap;">'.$text.'</span></p>';
        $stored = '<p dir="ltr">Before</p>'.$p('| a | b |')."\n".$p('| --- | --- |').$p('| 1 | 2 |')
            .'<ul><li><p>| x | y |</p></li></ul><p>Not a | table</p>'.$p('| c |').$p('|---|').$p('| 3 |');

        $result = MarkdownTableRepair::repair($stored);

        $this->assertSame(2, $result['tables']);
        $this->assertSame(
            '<p dir="ltr">Before</p>'
            .'<table><tbody><tr><th><p>a</p></th><th><p>b</p></th></tr><tr><td><p>1</p></td><td><p>2</p></td></tr></tbody></table>'
            .'<ul><li><p>| x | y |</p></li></ul><p>Not a | table</p>'
            .'<table><tbody><tr><th><p>c</p></th></tr><tr><td><p>3</p></td></tr></tbody></table>',
            $result['html'],
        );
    }

    public function test_repair_reads_rows_split_by_line_breaks(): void
    {
        $result = MarkdownTableRepair::repair('<p>| a | b |<br>|---|---|<br>| 1 | 2 |</p>');

        $this->assertSame('<table><tbody><tr><th><p>a</p></th><th><p>b</p></th></tr><tr><td><p>1</p></td><td><p>2</p></td></tr></tbody></table>', $result['html']);
    }

    public function test_repair_leaves_what_it_cannot_rebuild_safely_and_counts_it(): void
    {
        $ragged = '<p>| a | b | |---|---| | 1 | | 3 | 4 | 5 |</p>';
        $mention = '<p>| a | <span class="mention" data-mention-user-id="1">@Ann</span> |<br>|---|---|</p>';
        $across = '<p>| a | b |</p><p>| <a href="/x" data-smart-link="1">x</a> |</p><p>|---|---|</p>';
        $this->assertSame(['html' => $across, 'tables' => 0, 'skipped' => 1], MarkdownTableRepair::repair($across));
        $plain = '<p>Pipes | in | text</p><pre><code>| a |'."\n".'|---|</code></pre><table><tbody><tr><td><p>real</p></td></tr></tbody></table>';

        foreach ([$ragged => 1, $mention => 1, $plain => 0] as $html => $skipped) {
            $result = MarkdownTableRepair::repair($html);
            $this->assertSame(['html' => $html, 'tables' => 0, 'skipped' => $skipped], $result, $html);
        }
    }
}
