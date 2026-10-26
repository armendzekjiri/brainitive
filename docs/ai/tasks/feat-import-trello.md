# feat/import-trello — BRAI-65 Trello board JSON importer (v1.41.0 "Bring your data")

Card: BRAI-65 (main-board item 167). Release plan + contract: [`release-v1.41.0.md`](./release-v1.41.0.md) (ticket 1).
Builds on the import foundation (`app/Support/Imports/*`, `RunImport`, `ImportController`).

## Plan
1. `App\Support\Imports\Trello\TrelloExport`: read + validate the board JSON (shape, duplicate ids,
   limits: 50 MB, 5 000 cards, 500 lists, 1 000 labels / members, 20 000 checklist items), normalize
   lists / cards / checklists / labels / members, count unsupported content. Throws `ImportFailed`.
2. `TrelloDescription`: Trello Markdown → HTML (`PageContentConverter::markdownToHtml`) → `SafeHtml`,
   plus a Lexical checklist block (built from escaped text) and optional attachment links.
3. `TrelloImporter` (registry key `trello`, needs `createBoards`):
   - preview: board, lists with counts, default done list, labels, checklists, members (+ email
     match to project members), archived cards, unsupported counts;
   - options: board name, done list, include archived, checklists → description | subtasks,
     attachment links, member → project member map (`ProjectMember` rule);
   - run: new Kanban board (default columns replaced), open lists → columns, labels reused by name
     or created, cards → issues (new keys), subtasks optional; everything via `ImportMappings::once()`.
4. `components/imports/sources/TrelloPreview.tsx` + one line in `SOURCE_PREVIEWS`.
5. Fixture `tests/Fixtures/imports/trello/board.json` (+ malformed variants built in the test).
6. Tests: `tests/Feature/Imports/TrelloImporterTest.php`, `e2e/tests/import-trello.spec.ts`.
7. Docs: ARCHITECTURE (imports section) + KNOWLEDGE (Trello mapping decisions).

## Out of scope (reported as "not imported")
Comments, custom fields, attachment files (links only on request), power-ups, card covers,
stickers, votes, multiple members beyond the first assignee (named in the description).
