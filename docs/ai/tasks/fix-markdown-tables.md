# Fix: Markdown tables from AI assistants become real tables

**Branch:** fix/markdown-tables (from release/v1.51.0)
**Status:** done (not pushed)
**Started:** 2026-11-04

## Problem
Tables written as Markdown through the MCP (`create_page` / `update_page`, board items, comments) and the App API's
`markdown` fields showed as raw `| a | b |` text (71 docs in production). `PageContentConverter::markdownToHtml` is a
hand-written parser without GFM tables; it joined the table's lines into one paragraph.

## Approach
- **Converter, Markdown → HTML:** a table block in the hand parser (not a switch to the CommonMark converter of the
  imports: that passes raw HTML through, changes list / quote markup and the task-list / code-language / mention
  behaviour the existing tests pin). Header row + delimiter row with the same cell count, optional edge pipes, `\|`,
  alignment, `inline()` inside cells, ragged rows padded / cut; a table may follow a paragraph line directly. Output in
  the shape Lexical's TableNode / TableCellNode import: `<table><tbody><tr><th><p>…</p></th>…`, alignment as the cells'
  paragraph `text-align` (Lexical keeps alignment on paragraphs). SafeHtml keeps the table tags.
- **HTML → Markdown:** tables (editor export with colgroup / styled cells, imported thead/tbody) → GFM; cells one line,
  pipes escaped, colspan → empty cells, alignment from the header cells. Also the editor's `<b><strong>` reads as
  `**x**` once (it read `****x****` before).
- **Repair:** `Support\Pages\MarkdownTableRepair` finds runs of top-level paragraphs that form a table (one joined
  paragraph, one paragraph per row, `<br>` rows) and rebuilds only those through the same converter.
  `pages:repair-markdown-tables` (dry run lists id / uuid / title; `--force`; `--items` for issue descriptions).
  Docs via `PageWrites::repairHtml` (content + draft in place, edit_revision + 1, Page::saved resets the live document,
  no version / notification / updated_at); issues saved quietly + `ItemCollab::reset` + live `item.updated`.

## Tests
`tests/Unit/MarkdownTablesTest.php`, `tests/Feature/Pages/RepairMarkdownTablesTest.php`, MCP create_page / get_page in
`PageMcpToolsTest`, App API in `AppApi/PagesTest`, e2e `app-api-pages.spec.ts` (the web page renders a `<table>`).

## After deploy
`php artisan pages:repair-markdown-tables --items` (dry run), then the same with `--force`.
