# Doc editor: Outline / Evernote-style writing experience

**Branch:** feat/doc-editor-outline-style
**Status:** done
**Started:** 2026-10-18
**Board card:** —

## Problem / goal
The page (docs) editor looks like a classic word processor: a two-row fixed toolbar (font
family, font size, colors, case, align…) sits between the title and the text, the canvas is
a card with separators, and the typography is small. Goal: a calm, document-first writing
experience like Outline / Evernote — text first, formatting on demand.

## Approach
Frontend only, no data/format change (content stays `{ type: 'lexical', html }`).

- **Floating format toolbar** (`FloatingFormatToolbarPlugin`) appears above a text
  selection: bold, italic, strikethrough, highlight, inline code, H1–H3, quote, checklist,
  link. Enabled in `documentMode`.
- **Classic toolbar hidden by default** on pages; a header toggle ("Formatting toolbar",
  remembered in `localStorage`) brings it back for font/color/insert/AI. Rendered as a
  rounded bar in `documentMode`.
- **Empty-line hint** (`EmptyBlockPlaceholderPlugin`): the focused empty paragraph shows
  "Type / for commands" like Outline.
- **Typography** moved to one scoped CSS block (`.lexical-doc` in `app.css`): 16px / 1.7 body,
  tighter bold headings, quiet blockquote, subtle links/rules. Replaces the long
  `prose-*` modifier list in `RichLexicalEditorFull.tsx`.
- **Page chrome** (`Pages/Pages/Show.tsx`): single white surface, ~46rem text column with
  a gutter for the drag handle, title editable in place (textarea, Enter → jump into
  body), one quiet meta line with relative time, no separator, borderless TOC, header
  border only after scrolling.

Not changed: draft/publish/edit-mode flow, autosave, watchers, exports.

## Changes
- Frontend: `components/editor/RichLexicalEditorFull.tsx`, `Pages/Pages/Show.tsx`,
  `resources/css/app.css`.

## Test plan
- `npx tsc --noEmit`, `npm run build`.
- Visual check (screenshots) in light/dark: view mode, edit mode, selection toolbar,
  formatting toolbar toggle, empty-line hint.

Result: tsc + build green. `Show.tsx` was rendered in an isolated Vite harness (Inertia /
layout stubbed) and driven with Playwright: selection bar hidden while dragging and shown
on release, bold / H2 toggle / link (`example.com` → `https://example.com`) / highlight
applied, bar hides on collapse, title Enter → focus body, Escape discards. No PHP changes;
`php artisan test` / Pint not run in that environment (container had PHP 8.3, lockfile
needs 8.4).

## Docs to update
- `CONVENTIONS.md` → rich text editors: document-mode styling lives in `.lexical-doc`.

## Open questions
- Seamless editing (no Edit/Done for published pages, like Outline) — needs a product
  decision because "Update" is what notifies watchers.
