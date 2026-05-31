# CLAUDE.md

Guidance for Claude Code working in this repository.

## Read this first

The full architecture, conventions, and patterns live in the shared AI knowledge base —
**start there, not here.** Index: **[`docs/ai/README.md`](docs/ai/README.md)**.

- **[`docs/ai/ARCHITECTURE.md`](docs/ai/ARCHITECTURE.md)** — module map + file pointers
  (find where code lives without scanning the repo)
- **[`docs/ai/CONVENTIONS.md`](docs/ai/CONVENTIONS.md)** — coding rules & patterns
- **[`docs/ai/WORKFLOW.md`](docs/ai/WORKFLOW.md)** — branch → plan → build → update-docs → gate
- **[`docs/ai/skills/`](docs/ai/skills)** — step-by-step task recipes
- **[`docs/ai/KNOWLEDGE.md`](docs/ai/KNOWLEDGE.md)** — decisions & gotchas (read before fresh
  DB setup or running tests)
- **[`docs/ai/tasks/`](docs/ai/tasks)** — one plan file per branch

This file is intentionally thin. Keep project conventions in `docs/ai/`, not here, so all
agents (Claude, Copilot, Codex, Cursor) stay in sync.

## Quick reference

```bash
composer run dev           # run the app (serve + queue + pail + vite)
./vendor/bin/pint --test   # PHP lint
npx tsc --noEmit           # TS check
php artisan test           # PHPUnit (SQLite :memory:)
npm run build              # frontend build
```

Run the full gate (the four commands above, all green) before claiming a change is done —
see [`docs/ai/WORKFLOW.md`](docs/ai/WORKFLOW.md#definition-of-done--the-gate).

## Claude-specific notes

- Start any feature/fix on a new branch and write a plan in `docs/ai/tasks/<branch>.md`
  before coding (see WORKFLOW.md). Check `docs/ai/skills/` for a recipe first.
- After the work, update the doc your change invalidated (ARCHITECTURE / CONVENTIONS /
  KNOWLEDGE) — that's the last step before the PR, not an afterthought.
