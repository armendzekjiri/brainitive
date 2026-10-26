# feat/import-notion — BRAI-66 Notion Markdown & CSV ZIP importer (v1.41.0 "Bring your data")

Card: BRAI-66 (main-board item 168). Release plan + contract: [`release-v1.41.0.md`](./release-v1.41.0.md) (ticket 3).
Builds on the import foundation (`app/Support/Imports/*`, `SafeZip`, `RunImport`, `ImportController`).

## Plan
1. `Imports\Shared\MarkdownHtml`: GitHub-flavoured Markdown (tables, nested lists, images, fenced code,
   strikethrough, autolinks) → `SafeHtml`, then task lists → `<li aria-checked>` and code languages →
   `<pre data-language>` put back. `PageContentConverter::markdownToHtml` has no tables / images /
   nested lists, which Notion pages are full of.
2. `Notion\NotionArchive`: unpack via `SafeZip` (plus one level of `Export-…-Part-N.zip`), parse
   `Title <32 hex>` names, build the tree (folders, databases, row pages), order children by the
   parent's links (database rows by CSV order), resolve links (relative paths, notion.so URLs by id,
   NFC paths). Limits: 2 000 entries, 256 KB Markdown per page, 5 MB per CSV, SafeZip's 5 000 files /
   512 MB.
3. `Notion\NotionContent`: one page → sanitized HTML; CSV → table + "databases come over as a table"
   note + link to the original CSV; links rewritten; broken links / disallowed files → text + reported.
   Same code in preview (placeholders) and run.
4. `Notion\NotionAssets`: images / files stored like editor uploads (FileController rules, `Uploads`
   names, public disk), UUIDv5 names per (run, path) + a ledger file → no duplicates on resume.
5. `Notion\NotionImporter` (registry key `notion`, needs `PagePolicy::create`): preview = tree,
   counts, broken links, skipped files, unsupported, target folders; options `parent_id`, `skip_empty`;
   run = pass 1 tree (`notion_folder` / `notion_page`), pass 2 content (`notion_content`, links via
   `ImportMappings::map`). Pages stay drafts.
6. `components/imports/sources/NotionPreview.tsx` + one line in `SOURCE_PREVIEWS`.
7. Fixture `tests/Fixtures/imports/notion/` (synthetic). Tests `tests/Feature/Imports/NotionImporterTest.php`,
   e2e `e2e/tests/import-notion.spec.ts`.

## Out of scope (reported as "not imported")
Database views / formulas / relations (tables only), files the editor doesn't accept (SVG, video, …),
links to pages or files missing from the export, page icons / covers, comments, permissions, live sync.
