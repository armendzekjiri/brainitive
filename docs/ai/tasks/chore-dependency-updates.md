# Dependency updates within current majors + security fixes

**Branch:** chore/dependency-updates
**Status:** done
**Started:** 2026-10-22
**Board card:** BRAI-48 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-147

## Problem / goal
Many Composer and npm packages are behind, and `composer audit` / `npm audit` report known
advisories, several high: Laravel (CRLF in the default email rule, signed-URL path
confusion), league/commonmark, guzzle/psr7, phpseclib, symfony/http-kernel + mime,
prosemirror-view (paste XSS in the editor), excalidraw, tiptap, postcss, nanoid, lodash-es…

Phase 1 = everything reachable **without a major-version bump**, so no code changes are
expected.

## Approach
1. `composer update` within the existing `composer.json` constraints.
2. `npm update` + `npm audit fix` (never `--force`) in the root and `excalidraw-build/`.
3. Raise `package.json` / `composer.json` lower bounds only where needed to pin a patched
   version.
4. Gate + check the app in the browser (editor paste, whiteboard, boards).

Out of scope (later PRs, one each): Laravel 13 + Inertia 3 (+ spatie/laravel-permission 8,
tinker 3), React 19, Tailwind 4 (clears the advisories that remain in the Tailwind 3
toolchain), Vite 8 / plugin-react 6 / laravel-vite-plugin 3, Lexical 0.52, PHPUnit 13,
TypeScript 7.

## Changes
- `composer.lock`, `package-lock.json`, `excalidraw-build/package-lock.json`
- `package.json` + `excalidraw-build/package.json`: `overrides` block (no constraint changes)
- `public/js/excalidraw-dist/excalidraw-bundle.iife.js` rebuilt
- `.gitignore`, untrack `excalidraw-build/node_modules`

## Test plan
- `./vendor/bin/pint --dirty`, `npx tsc --noEmit`, `php artisan test`, `npm run build`
- `composer audit` / `npm audit --omit=dev` before/after
- Smoke test in the running app: page editor (Tiptap paste, Lexical), whiteboard, board.

## Result
- **Composer:** `composer update` within constraints, 85 packages (Laravel 12.61 → 12.70,
  guzzle 7.16, commonmark 2.10, symfony 7.4.21 …). `composer audit`: 40 advisories → **0**.
  `phpseclib` 3 → 4 comes from Passport/Socialite; the app doesn't use it directly. The PHP
  floor is unchanged (the lock already required ≥ 8.4.1 via symfony 8.x components).
- **npm (root):** `npm update` + `npm audit fix` + `overrides` for Excalidraw's pinned
  `nanoid`/`sass`/`lodash-es`. 35 vulnerabilities (2 critical, 23 high) → 12 (5 high, 3
  moderate, 4 low). Remaining: the Tailwind 3 toolchain (`braces`/`chokidar`/`micromatch`/
  `fast-glob`/`postcss-selector-parser`, build-time only, needs Tailwind 4) and
  `katex` < 0.18.2 (low; mermaid needs `^0.16`).
- **excalidraw-build:** same, 21 → 4 (all low, katex chain). Bundle rebuilt.
- **Repo hygiene:** `excalidraw-build/node_modules` (10,487 files) untracked; `.gitignore`
  `/node_modules` → `node_modules/`.
- **Gate:** `pint --dirty` ✓, `tsc` ✓, `php artisan test` 133 passed ✓, `npm run build` ✓.
- **Browser smoke (headless Chrome, throwaway SQLite DB):** login, Lexical page view + edit
  (typing, HTML paste; `onerror` payload did not run), whiteboard draw + autosave, board with
  card. No console errors, no failed requests (apart from the paste test's fake image).

## Docs updated
- KNOWLEDGE.md: stale route cache / out-of-sync vendor, npm overrides for Excalidraw,
  Tiptap is dead code, `node_modules` ignore fix.

## Follow-ups (separate PRs)
- Remove the unused Tiptap editor + `@tiptap/*` packages.
- Laravel 13 + Inertia 3 (+ spatie/laravel-permission 8, tinker 3).
- Tailwind 4 (clears the remaining high npm advisories), React 19, Vite 8 toolchain,
  Lexical 0.52, katex 0.18+, PHPUnit 13, TypeScript 7.
