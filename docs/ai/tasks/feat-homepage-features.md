# Homepage: show the features of the latest releases (BRAI-98)

**Branch:** feat/homepage-features (into release/v1.37.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-98

## Problem / goal
The public homepage (`resources/js/Pages/Welcome.tsx`) was last refreshed around v1.13–v1.20
and stopped at task lists / Gantt / MCP basics. v1.21–v1.36 shipped a lot that a visitor never
sees: Quick capture, lead forms, saved board views, custom fields, templates, My Day, command
palette, releases, related content, GitHub development, MCP sprints/plans/comments.

## Approach
Compared the `CHANGELOG` in `HelpModal.tsx` (v1.20–v1.36) with the homepage, then checked each
feature against routes / controllers / components so nothing unbuilt is advertised.

- **"New in recent releases" spotlight** (`SPOTLIGHT` array, top of `#features`): two bigger
  cards — Quick capture (v1.36, with a "C" key hint) and Lead forms (v1.34). Region labelled
  "New in recent releases"; the grid adapts to 1–3 cards.
- **Feature groups** (same `featureGroups` shape + optional `isNew` → green "New" pill), now
  6 groups × 6 (Customers & leads × 3) so the 3-column grid stays even:
  1. Plan & organize — Project templates*, Boards (now with swimlanes), Saved board views*,
     Custom fields*, Gantt timelines, Releases & release notes*
  2. Capture & focus (new group) — Quick capture*, My Day & focus timer*, My Work, Task lists,
     Calendar sync, Command palette*
  3. Create & write — Wiki & docs, Rich editors, Whiteboards, Related content & backlinks*,
     Snippets, Bookmarks
  4. Collaborate & share — Workspaces (moved here: it's about members), Roles & permissions,
     Watchers, Publish & share, Share links* (BRAI-15), Profiles & avatars
  5. Customers & leads (new group) — Helpdesk portal, Helpdesk templates*, Lead forms*
  6. Connect & automate — AI agents (MCP: now comments, sprints, Gantt plans), In-app AI,
     GitHub development*, Notifications & push, Social login, Search & install
  (* = new card, marked `isNew`)
- Features intro line, extension card ("Inbox" tab for captures) updated.
- **Pattern for later releases:** comment above `SPOTLIGHT` / `featureGroups` explains how to add
  a release's features (card in the matching group with `isNew`, optional spotlight card, drop
  `isNew` from older ones). One line in WORKFLOW → Releases.

Not advertised: v1.37.0 work in progress (workload planning, project health, meeting notes →
tasks) — added by the lead when built.

## Changes
- Migrations / models / policies / routes: none
- Frontend: `resources/js/Pages/Welcome.tsx` only
- e2e: `e2e/tests/homepage.spec.ts` (guest sees the spotlight + new cards, no page errors,
  no horizontal scroll at 375 px in light and dark)

## Test plan
- Playwright screenshots: desktop 1440 + mobile 375, light + dark.
- Gate: pint --test, tsc, tsc -p e2e, build, full chromium e2e, PHPUnit.

## Docs to update
- WORKFLOW → Releases: every release updates the homepage.
- tasks/README index.

## Open questions
- None.
