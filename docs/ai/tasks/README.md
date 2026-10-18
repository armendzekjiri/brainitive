# Tasks — Per-Branch Plans

One plan file per branch, written **before** the code (see
[`WORKFLOW.md`](../WORKFLOW.md#2--write-the-plan-docsaitasksbranchmd)). Plans are kept after
merge as a permanent decision log — don't delete them, mark them `done`.

## Naming
Branch name with slashes → dashes:
- `feat/calendar-sync` → `feat-calendar-sync.md`
- `fix/ticket-reorder` → `fix-ticket-reorder.md`

(Older entries predate this convention and are date-named historical specs.)

## Template
```markdown
# <Title>

**Branch:** feat/...
**Status:** draft | in-progress | done | superseded
**Started:** YYYY-MM-DD
**Board card:** <Brainitive card url/id, if MCP connected — see skills/track-work-in-brainitive.md>

## Problem / goal
What we're solving and why.

## Approach
The chosen design; note alternatives if the choice isn't obvious.

## Changes
- Migrations:
- Models / relationships:
- Policy (+ register in AppServiceProvider):
- Controller / routes:
- Frontend (Pages, components, types/index.d.ts):
- MCP tool(s) (if exposing to AI):

## Test plan
## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
## Open questions
```

## Index

| Plan | Status |
|---|---|
| [Boards: Jira-style design](./feat-board-jira-design.md) | done |
| [Boards: Jira essentials (phase 1)](./feat-board-jira-essentials.md) | done |
| [Pages: Confluence-style draft / publish workflow](./feat-page-draft-publish-workflow.md) | done |
| [Doc editor: Outline / Evernote-style writing](./feat-doc-editor-outline-style.md) | done |
| [Better AI Agent Documentation](./chore-brai-41-ai-agent-docs.md) | done |
| [Subtask / parent implementation](./2026-02-26-subtask-parent-implementation.md) | shipped |
| [Board cards profile images](./2026-02-26-board-cards-profile-images.md) | shipped |
