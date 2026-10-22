# Fix the last open Dependabot alerts

**Branch:** fix/dependabot-alerts
**Status:** done
**Started:** 2026-10-22
**Board card:** BRAI-51 — https://www.brainitive.app/workspaces/personal/projects/brainitive/boards/main-board#item-150

## Problem / goal
After the dependency updates, Dependabot still had 4 open alerts on `main`:

| # | Package | Severity | Lockfile | Installed | Fixed in |
|---|---------|----------|----------|-----------|----------|
| 231 | katex (GHSA-238p-pmpm-9mq7) | low | `package-lock.json` | 0.16.47 (via mermaid) | 0.18.2 |
| 108 | katex (GHSA-238p-pmpm-9mq7) | low | `excalidraw-build/package-lock.json` | 0.16.47 (via mermaid) | 0.18.2 |
| 229 | postcss-selector-parser (GHSA-rj75-hqrm-r3gf) | medium | `package-lock.json` | 6.1.5 (via Tailwind 3) | 7.1.6 |
| 227 | braces (GHSA-vfj7-8cjw-p6xm) | high | `package-lock.json` | 3.0.3 (via Tailwind 3) | — |

## Approach
- npm `overrides`: `katex` → `"$katex"` in the root (it's already a direct `^0.18.2` dep) and
  `^0.18.10` in `excalidraw-build`; `postcss-selector-parser` → `^7.1.7` in the root.
- Rebuild `public/js/excalidraw-dist/` (mermaid's katex is bundled there).
- `braces`: no patched release exists. It's only used for build-time globbing in Tailwind 3,
  so the plan is to dismiss the alert as tolerable risk until the Tailwind 4 migration.

## Result
- `npm ls`: katex 0.18.10 everywhere, postcss-selector-parser 7.1.7. `npm audit` in
  `excalidraw-build`: 0. In the root, only braces is left (no fix available).
- Built app CSS is byte-identical before/after the postcss-selector-parser override.
- Gate: `pint --dirty` ✓, `tsc` ✓, `php artisan test` ✓, `npm run build` ✓,
  `npm ci --dry-run` ✓ (both lockfiles in sync).

## Docs updated
KNOWLEDGE (overrides entry and the braces note).
