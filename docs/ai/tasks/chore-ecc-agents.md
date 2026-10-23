# Review agents adapted from ECC

**Branch:** chore/ecc-agents
**Status:** done
**Started:** 2026-10-23
**Board card:** — (MCP not connected)

## Problem / goal
We want reviewer agents (PHP, frontend, database, security) like the ones in
[ECC](https://github.com/affaan-m/ECC) (MIT), without importing ECC wholesale. ECC ships ~300
skills, 70+ agents and hooks; most don't apply, and its own rule files would compete with
`docs/ai/` as a second source of truth. An old, untracked `skills/` folder (a Codex skill
from March) duplicated `docs/ai/` and is removed.

## Approach
- Take the review lanes from ECC's `php-reviewer`, `react-reviewer` + `typescript-reviewer`,
  `database-reviewer`, `security-reviewer` and the `laravel-security` / `laravel-verification`
  skills, and rewrite them against this repo's rules (CONVENTIONS + KNOWLEDGE).
- Drop what doesn't fit: `strict_types`/`final` (not used here), PHPStan/Psalm/ESLint (not
  installed), FormRequests (we validate inline), Sanctum (Passport), Supabase RLS, Next.js
  RSC/Server Actions, Livewire/Filament, Pest.
- **The checklists live in `docs/ai/skills/review-checklist.md`**, so every agent can use
  them. `.claude/agents/*.md` are thin Claude Code wrappers that read their section.
- ECC itself, for personal use, goes in as a **user-scope** plugin on the developer's machine,
  not in the repo.

## Changes
- `docs/ai/skills/review-checklist.md`: backend, frontend, database, security sections and
  a shared report format
- `.claude/agents/`: `php-reviewer`, `frontend-reviewer`, `database-reviewer`,
  `security-reviewer`
- `docs/ai/WORKFLOW.md`: review step before the PR
- `docs/ai/skills/README.md`, `docs/ai/README.md`: index entries
- Delete untracked `skills/`

## Test plan
Docs/config only, no app code. Run the gate to make sure nothing else changed, and do a
trial run of each agent on a recent merged diff (e.g. `feat/swimlanes`) to check that the
findings are relevant to this repo.

## Docs to update
WORKFLOW (review step), skills index, README.

## Result
Trial run of the four reviewers on the swimlanes commit (990e7d3). The findings were relevant
and there were no noise findings about `strict_types` / FormRequests / PHPStan:
- frontend: **High**, the swimlane settings page keeps "Unsaved changes" after a successful
  save (local state compared against the server-normalized prop); no `onError` / toast on the
  save and on lane drops; a stale `custom` lane mode from localStorage
- database: release picker `orderBy('release_date')` on a nullable column sorts differently
  on SQLite and Postgres
- backend: cosmetic only; security: nothing found

Those swimlane bugs are left for a separate `fix/` branch. The reviewers' feedback on the
checklist itself (Postgres doesn't index FKs, portable query semantics, JSON settings columns,
accepted foreign-id scoping pattern, `router.*` errors, throttle wording, scopeBindings) was
folded into `review-checklist.md`, and the `{}` → `[]` cast trap went into KNOWLEDGE.
