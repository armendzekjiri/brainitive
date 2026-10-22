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
| [Move a project to another workspace](./feat-move-project-between-workspaces.md) | done |
| [Helpdesk: agent ticket form, resolve message, support page](./feat-helpdesk-support-page.md) | done |
| [Security review fixes](./fix-security-review.md) | done |
| [Browser extension (Chrome, Edge, Firefox)](./feat-browser-extension.md) | done |
| [Automatic releases: tag + release + Docker image on push to main](./chore-release-workflow.md) | done |
| [Instance admin console; no content access for instance admins](./feat-instance-admin-console.md) | done |
| [Dependency updates within current majors + security fixes](./chore-dependency-updates.md) | done |
| [Open source + Brainitive Cloud pricing](./feat-open-source-cloud.md) | done |
| [Homepage + Welcome tab refresh](./feat-homepage-feature-refresh.md) | done |
| [Plans ↔ board issues](./feat-plan-issue-links.md) | done |
| [Plans: Tom's Planner–style Gantt per project](./feat-project-plans.md) | done |
| [Whiteboards: autosave, history, templates, issue cards](./feat-whiteboard-essentials.md) | done |
| [Boards: Scrum — sprints, backlog, reports](./feat-board-sprints.md) | done |
| [Boards: Jira-style design](./feat-board-jira-design.md) | done |
| [Boards: Jira essentials (phase 1)](./feat-board-jira-essentials.md) | done |
| [Pages: Confluence-style draft / publish workflow](./feat-page-draft-publish-workflow.md) | done |
| [Doc editor: Outline / Evernote-style writing](./feat-doc-editor-outline-style.md) | done |
| [Better AI Agent Documentation](./chore-brai-41-ai-agent-docs.md) | done |
| [Subtask / parent implementation](./2026-02-26-subtask-parent-implementation.md) | shipped |
| [Board cards profile images](./2026-02-26-board-cards-profile-images.md) | shipped |
