# Brainitive — AI Agent Knowledge Base

**Single source of truth for every AI coding agent** (Claude Code, GitHub Copilot, OpenAI
Codex, Cursor). The per-agent entry files are thin pointers to the docs here.

## Start here — what to read, when

| You are about to… | Read |
|---|---|
| **Find where some feature lives in the code** | [`ARCHITECTURE.md`](./ARCHITECTURE.md) — module map + file pointers |
| **Write code** (any backend/frontend change) | [`CONVENTIONS.md`](./CONVENTIONS.md) — the rules & patterns |
| **Start a feature or fix** | [`WORKFLOW.md`](./WORKFLOW.md) — branch → plan → build → update docs |
| **Do a common task the project's way** | [`skills/`](./skills) — step-by-step recipes |
| **Avoid a known trap / understand a decision** | [`KNOWLEDGE.md`](./KNOWLEDGE.md) — gotchas & decisions |
| **See plans for past/in-flight work** | [`tasks/`](./tasks) — one plan file per branch |

## 30-second orientation

Brainitive is an all-in-one team workspace: Kanban boards, wiki/docs pages, whiteboards,
bookmarks, code snippets, task lists, a **helpdesk/ticketing** system (with an embeddable
public widget), **calendar** (Google Calendar/Tasks), and an **MCP server** exposing it all
to AI clients. It is a **Laravel 12 + Inertia.js v2 + React 18 + TypeScript monolith** — no
separate API layer; controllers render Inertia pages with props.

```bash
composer run dev           # run the app (serve + queue + pail + vite)
./vendor/bin/pint --test   # PHP lint        npx tsc --noEmit   # TS check
php artisan test           # PHPUnit          npm run build      # frontend build
```

> **The gate:** all four commands green + verified in the running app = done. See
> [`WORKFLOW.md`](./WORKFLOW.md#definition-of-done--the-gate).

## Per-agent entry files (thin pointers — don't put conventions in them)

| Agent | File |
|---|---|
| Claude Code | [`/CLAUDE.md`](../../CLAUDE.md) |
| GitHub Copilot | [`/.github/copilot-instructions.md`](../../.github/copilot-instructions.md) |
| Codex / Cursor Cloud | [`/AGENTS.md`](../../AGENTS.md) |
| Cursor | [`/.cursor/rules/*.mdc`](../../.cursor/rules) |

Claude Code also has reviewer subagents in [`/.claude/agents/`](../../.claude/agents). They're
thin wrappers around [`skills/review-checklist.md`](./skills/review-checklist.md), so the
checklist stays the one copy every agent uses.

## Maintenance rule

When something changes, update the doc that owns it — **conventions** → `CONVENTIONS.md`,
**a module's shape** → `ARCHITECTURE.md`, **a new gotcha/decision** → `KNOWLEDGE.md`. Keep
the per-agent files as thin pointers. The `WORKFLOW.md` makes "update docs" the last step
of every task, which is what keeps these from going stale (they had, before this base
existed).
