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
| [Release v1.38.0: time tracking & client billing (BRAI-99/100/101)](./release-v1.38.0-billing.md) | done |
| [Release v1.39.0: notifications, webhooks, insights, custom sidebar (BRAI-102…111)](./release-v1.39.0.md) | done |
| [Release v1.40.0: automate — automation, recurring issues, email → helpdesk, SLA (BRAI-63/79/123/124/125)](./release-v1.40.0.md) | done |
| [Release v1.41.0: bring your data — Trello, Jira, Notion imports, project export/restore (BRAI-65/139/66/81)](./release-v1.41.0.md) | done |
| [Release v1.42.0: work with clients — feedback portal, client portal, document review, GitHub App connect, calendar feed + providers (Outlook, CalDAV / iCloud, ICS), smart links, automation conditions, meetings, Sign in with Microsoft (BRAI-60/73/80/61/142/140/141/143/144/147/148)](./release-v1.42.0.md) | in progress |
| Release v1.43.0: find anything, write together, work offline — search, live docs, offline mode (BRAI-55/54/82) | in progress |
| Release v1.44.0: everything live, push devices, branded email, legal & privacy (BRAI-151/152/153/154/155/156) | in progress |
| [Live channels foundation: pub/sub, presence, signals; instant bell (BRAI-151, part 1)](./feat-live-foundation.md) | done |
| [Live basics: comments, who's here, typing, live issue descriptions (BRAI-151, part 2)](./feat-live-basics.md) | done |
| [Live boards + plans (BRAI-152)](./feat-live-boards.md) | done |
| [Live whiteboards: draw together (BRAI-153)](./feat-live-whiteboards.md) | done |
| [Web push devices + test notification (BRAI-154)](./feat-push-test.md) | done |
| [Branded email templates (BRAI-155)](./feat-email-templates.md) | done |
| [Legal & privacy compliance review (BRAI-156)](./feat-legal-compliance.md) | done |
| [Find anything: real search on PostgreSQL (BRAI-55)](./feat-search.md) | done |
| [Offline notes and tasks: opt-in local drafts with explicit sync conflicts (BRAI-82)](./feat-offline.md) | done |
| [Live co-editing of documents: Yjs + Hocuspocus, offline merge, Docker service (BRAI-54)](./feat-live-docs.md) | done |
| [Public feedback portal (BRAI-60)](./feat-feedback-portal.md) | done |
| [Client portal: deliverables, version-specific approvals (BRAI-73)](./feat-client-portal.md) | done |
| [Internal document review (BRAI-80)](./feat-document-review.md) | done |
| [GitHub App connect + merge transitions (BRAI-61)](./feat-github-app.md) | done |
| [Calendar feed: subscribe in other apps (BRAI-142)](./feat-calendar-feed.md) | done |
| [Calendar provider layer, Google moved onto it (BRAI-140)](./feat-calendar-providers.md) | done |
| [Outlook / Microsoft 365 calendar provider (BRAI-140)](./feat-calendar-outlook.md) | done |
| [Calendar providers: Apple iCloud / CalDAV + ICS links (BRAI-141)](./feat-calendar-caldav.md) | done |
| [Smart links in documents + side "+" menu fix (BRAI-143)](./feat-smart-links.md) | done |
| [Automation conditions: field / operator / value, match all / any (BRAI-144)](./feat-automation-conditions.md) | done |
| [Online meetings: Google Meet / Teams from issues, docs, calendar (BRAI-147)](./feat-meetings.md) | done |
| [Sign in with Microsoft (BRAI-148)](./feat-microsoft-login.md) | done |
| [Admin insights (BRAI-105)](./feat-admin-insights.md) | done |
| [Billing settings + documents: PDF, Excel, email (BRAI-101, part 1)](./feat-billing-documents.md) | done |
| [Time log: activity on logging, timer, legacy backfill, My timesheet (BRAI-99 part 2)](./feat-time-log.md) | done |
| [Billing reports: builder, finalize / lock, status (BRAI-101 reports)](./feat-billing-reports.md) | done |
| [Homepage: features of the latest releases (BRAI-98)](./feat-homepage-features.md) | done |
| [Meeting notes to tasks: AI extraction with review (BRAI-62)](./feat-meeting-notes-to-tasks.md) | done |
| [Project health report (BRAI-64)](./feat-project-health.md) | done |
| [Workload planning: capacity, allocations, overload (BRAI-75)](./feat-workload-planning.md) | done |
| [Quick capture: private inbox + convert to content (BRAI-70)](./feat-quick-capture.md) | done |
| [Linked content and backlinks (BRAI-71)](./feat-backlinks.md) | done |
| [Helpdesk templates (BRAI-92)](./feat-helpdesk-templates.md) | done |
| [Lead Generation forms (BRAI-93)](./feat-lead-forms.md) | done |
| [Security hardening, batch 1](./fix-security-hotfix-1.md) | done |
| [Security hardening, batch 2](./fix-security-batch-2.md) | done |
| [Security hardening, batch 3](./fix-security-batch-3.md) | done |
| [Security hardening, batch 4](./fix-security-batch-4.md) | done |
| [Saved board views (BRAI-78)](./feat-saved-board-views.md) | done |
| [CI test gate + pre-release flow (BRAI-95)](./ci-test-gate.md) | in progress |
| [Playwright e2e suite (BRAI-96)](./test-e2e-playwright.md) | done |
| [Small fixes from the e2e suite (BRAI-97)](./fix-e2e-findings.md) | done |
| [Template gallery + more project templates (BRAI-91)](./feat-template-gallery.md) | done |
| [Project templates (BRAI-72)](./feat-project-templates.md) | done |
| [Board custom fields (BRAI-77)](./feat-board-custom-fields.md) | done |
| [My Day + focus timer (BRAI-74, phase 1)](./feat-my-day-focus-timer.md) | done |
| [Task lists redesign + due reminders (BRAI-89)](./feat-task-lists-redesign.md) | done |
| [Fix: saved swimlanes don't show on the board](./fix-swimlanes-default-wins.md) | done |
| [Fix: swimlane save state, errors, release order](./fix-swimlanes-save-state.md) | done |
| [Review agents adapted from ECC](./chore-ecc-agents.md) | done |
| [Swimlanes on boards (BRAI-87)](./feat-swimlanes.md) | done |
| [Releases / fix versions + release notes (BRAI-76)](./feat-releases.md) | done |
| [Fix: wrong domain (BRAI-88)](./fix-domain-urls.md) | done |
| [GitHub development panel (BRAI-61)](./feat-github-development.md) | done |
| [Command palette v2 (BRAI-83)](./feat-command-palette.md) | done |
| [Fix: priorities and board columns (BRAI-84/85/86)](./fix-priorities-and-columns.md) | done |
| [MCP: comments, sprints and plans (BRAI-67/68/69)](./feat-mcp-comments-sprints-plans.md) | done |
| [Secure sharing for docs and issues (BRAI-15)](./feat-secure-sharing.md) | done |
| [Nested subtasks (BRAI-25)](./feat-nested-subtasks.md) | done |
| [AI with project context (BRAI-30)](./feat-ai-project-context.md) | done |
| [Polls that work (BRAI-13)](./fix-editor-polls.md) | done |
| [Duplicate issues and tasks (BRAI-24)](./feat-duplicate-issues-and-tasks.md) | done |
| [Friendly error pages (BRAI-47)](./fix-error-pages.md) | done |
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
