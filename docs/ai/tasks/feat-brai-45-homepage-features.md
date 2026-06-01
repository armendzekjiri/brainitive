# Update homepage with all new Brainitive features

**Branch:** feat/brai-45-homepage-features
**Status:** done
**Started:** 2026-05-31
**Board card:** BRAI-45 — https://www.brainitive.app/workspaces/personal/projects/brainitive/boards/main-board#item-130

## Problem / goal
The homepage (`resources/js/Pages/Welcome.tsx`) lists only 12 features in a flat grid and
omits many capabilities the app has shipped since. The landing page should communicate the
current product value without feeling overloaded, and work in light + dark mode on desktop
and mobile.

## Approach
Replace the flat 12-item `features` grid with **4 themed feature groups** (chosen with the
user over flat-grid / tabbed alternatives — grouping keeps ~20 features visible, organized,
and not overloaded, with no new client state). Each group: a labelled header (icon + title +
one-line tagline) and a responsive grid of compact feature items (icon + title + short
description). Reuses the existing design tokens (`bg-card`, `border-border`, brand colors,
`RevealSection` scroll-reveal) so light/dark and mobile behavior come for free.

Only **verified** features are advertised (confirmed against models/controllers/routes):
- Verified present: workspaces+roles, boards/Kanban (labels, priorities, subtasks,
  attachments, comments), task lists, calendar (Google Calendar + Tasks sync + reschedule),
  My Work, wiki/docs (tree, draft/publish), rich editors, Excalidraw, snippets, bookmarks,
  watchers (items/pages/lists/tickets), publish & share, helpdesk + embeddable portal,
  profile & avatars, AI agents via MCP (ChatGPT/Claude), in-app AI, notifications + web push,
  social login (Google/GitHub), global search, PWA install.
- **Not** advertised: per-document version history — no revision table exists (pages have
  `content` + `status` draft/publish + watchers only; the `ActivityLog` is workspace/member
  scoped, not page revisions). Avoid false advertising.

### Groups (4 × 5 = 20)
1. **Plan & organize** — Workspaces, Boards & Kanban, Task lists, Calendar sync, My Work
2. **Create & write** — Wiki & docs, Rich editors, Whiteboards (Excalidraw), Snippets, Bookmarks
3. **Collaborate & share** — Roles & permissions, Watchers, Publish & share, Helpdesk portal, Profiles & avatars
4. **Connect & automate** — AI agents (MCP), In-app AI, Notifications & push, Social login, Search & install (PWA)

## Changes
- Migrations: none
- Models / relationships: none
- Policy: none
- Controller / routes: none
- Frontend: `resources/js/Pages/Welcome.tsx` only — swap `features` array for `featureGroups`,
  update the Features section render + section subtitle, adjust lucide-react imports.
- MCP tool(s): none

## Test plan
- `npx tsc --noEmit` clean, `npm run build` clean, `./vendor/bin/pint --test` clean.
- Manual: homepage in light + dark, desktop + mobile widths — all 4 groups render, no overflow,
  hover/reveal animations work, nav "Features" anchor still scrolls to `#features`.

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
- ARCHITECTURE: marketing homepage content is self-contained in `Welcome.tsx`; no structural
  change — no doc update required. (Note here if that changes.)

## Open questions
- None — layout approach confirmed with user (grouped categories).
