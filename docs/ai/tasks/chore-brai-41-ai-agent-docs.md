# Better AI Agent Documentation

**Branch:** chore/brai-41-ai-agent-docs
**Status:** done
**Started:** 2026-05-31
**Board card:** BRAI-41 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-126

## Problem / goal
Agents were treating `BRAI-*` work keys as possible Jira issues and the new in-product AI
documentation needed a clear parent/child structure in Brainitive Pages.

## Approach
Make Brainitive the explicit tracker for this project, document that `BRAI-*` keys are
Brainitive board cards, and mirror the page writing/editing rules into both the repo docs
and the Brainitive "General AI Documentation" parent page.

## Changes
- Migrations: none.
- Models / relationships: none.
- Policy (+ register in AppServiceProvider): none.
- Controller / routes: none.
- Frontend (Pages, components, types/index.d.ts): none.
- MCP tool(s) (if exposing to AI): none.
- Docs: updated `WORKFLOW.md`, `KNOWLEDGE.md`, and `skills/track-work-in-brainitive.md`.
- Brainitive Pages: updated "General AI Documentation" and "AI Agent Workflow".

## Test plan
- Read the Brainitive project page list and confirmed "AI Agent Workflow" is a child of
  "General AI Documentation".
- Updated both Brainitive pages via MCP and reviewed returned page URLs.
- Ran `git diff --check`.

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
- `docs/ai/WORKFLOW.md`: tracker ownership and MCP fallback wording.
- `docs/ai/KNOWLEDGE.md`: gotcha that `BRAI-*` keys are Brainitive cards.
- `docs/ai/skills/track-work-in-brainitive.md`: MCP-dependent tracking, `BRAI-*` ownership,
  and page writing/editing rules.

## Open questions
None.
