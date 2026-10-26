# feat/import-jira — BRAI-139 Jira CSV importer (v1.41.0 "Bring your data")

Card: BRAI-139 (main-board item 266). Release plan + contract: [`release-v1.41.0.md`](./release-v1.41.0.md) (ticket 2).
Builds on the import foundation (`app/Support/Imports/*`, `RunImport`, `ImportController`).

## Plan
1. `Jira\CsvReader`: streaming CSV (BOM, `,` / `;` / tab detection on the header, quoted multi-line
   cells, repeated column names read positionally, Windows-1252 cells, damaged files → `ImportFailed`).
2. `Jira\JiraExport`: rows → issue arrays (key, summary, type, status + category, priority, people,
   dates, labels, sprints, story points, estimate, parent / epic link, outward links, comments),
   tallies for the preview, hierarchy (parents first, cycles cut, max depth), unsupported counts.
   Limits: 25 MB, 10 000 issues.
3. `Jira\JiraMarkup`: wiki markup → HTML (headings, emphasis, lists, code / noformat, quotes, panels,
   tables, links, mentions, images / attachments as text) or Markdown via `PageContentConverter`,
   always through `SafeHtml`.
4. `Jira\JiraImporter` (registry key `jira`, needs `createBoards`): preview with the default mapping;
   options (board name, status → column, done column, type → item type | create, priority, person →
   member, comments, sprints); run via `ImportMappings::once()`: board, columns, types, sprints, labels,
   issues (parents first), comments, links.
5. `components/imports/sources/JiraPreview.tsx` + its line in `SOURCE_PREVIEWS`.
6. Fixtures `tests/Fixtures/imports/jira/` (atlas.csv, atlas-semicolon.csv with BOM, malformed, not-jira).
7. Tests: `tests/Feature/Imports/JiraImporterTest.php`, `e2e/tests/import-jira.spec.ts`.
8. Docs: ARCHITECTURE (Jira import) + KNOWLEDGE (mapping decisions).

## Out of scope (reported as "not imported")
Custom fields, attachments, watchers, work logs, components, versions (releases weren't trivial:
no release dates in the CSV), sprint states (sprints come over as planned).
