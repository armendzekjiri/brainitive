# Command palette v2 (BRAI-83)

**Branch:** feat/command-palette
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-83 (#item-185)

## Starting point
Two launchers: ⌘K `SearchModal` (search only) and Ctrl+. `CommandPalette` (static commands).

## Plan
- One palette (`components/CommandPalette.tsx`): ⌘K opens it; Ctrl+. opens it in command
  mode (`>` prefix, like VS Code). `SearchModal` is removed.
- Empty query: **Recent** (last destinations) + **Go to** + **Create** sections.
- Typing: matching commands first (max 5), then search results grouped by type.
  `>` prefix: commands only. Search is debounced; older requests are aborted and
  stale responses ignored (sequence id). Error state with retry.
- Recent: localStorage stores only `{type, id}` per user (`brainitive.palette.recent.<userId>`,
  other users' keys removed on load — no titles at rest). The server resolves them
  (`POST /command-palette/recent`), dropping anything the user can no longer view, so revoked
  projects or private pages never leak.
- Search: add boards and plans; results from the current project first (`project` param).
- Shortcut respects editors: ignored when the event was already handled (`defaultPrevented`,
  e.g. Excalidraw's ⌘K link) or during IME composition.
- Actions only open existing forms or navigate; nothing mutates on select. No destructive
  commands, no AI, no new search engine.

## Tests
Feature test for the recent resolver (permissions, revoked, bounded) and search additions;
browser check of keyboard flow, `>` mode, recent, dark mode and mobile width.

## Notes from verification
- cmdk's `vimBindings` (Ctrl+K = up) swallowed the toggle shortcut → disabled.
- The query is reset while rendering the opening frame (not in an effect), so ">" from
  Ctrl+. never flashes the previous text.
- Playwright request mocks need `serviceWorkers: 'block'` (the PWA service worker answers fetches).
