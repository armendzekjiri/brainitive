# Homepage + Welcome tab refresh

**Branch:** feat/homepage-feature-refresh (stacked on feat/plan-issue-links)
**Status:** done
**Started:** 2026-10-22
**Board card:** —

## Problem / goal
The homepage and the help modal's Welcome tab still described v1.5: no Scrum, no Gantt
plans, no draft/publish history, no whiteboard templates or issue cards.

## Approach
- `Pages/Welcome.tsx`: hero subline, features intro and "One app" strip updated; cards for
  Boards (Kanban & Scrum), Wiki & docs, Whiteboards rewritten; new **Gantt timelines** card
  ("Plans" is avoided on the homepage because the pricing section is also called plans).
- New **"See it in action"** section (`#tour`, nav link "Tour"): tabs Gantt timelines /
  Boards & Scrum / Docs & wiki / Whiteboards with screenshots in a browser frame; light and
  dark images switch with the theme.
- `HelpModal.tsx` Welcome tab: data-driven `WELCOME_FEATURES` (8 modules with "where to find
  it" and "New" badges) + getting-started note. Changelog v1.13.1.
- Pricing unchanged: plans (Gantt) have no tier limit for now.

## Changes
`resources/js/Pages/Welcome.tsx`, `resources/js/components/HelpModal.tsx`,
`public/images/screenshots/*.jpg` (2880×1720 JPEG, demo data).

## Test plan
Rendered in Chromium (light, dark, 390 px): hero, features grid, tour tabs, help modal.

## Docs to update
CONVENTIONS (keep homepage / Welcome tab in sync), tasks index.

## Open questions
- Tier limit for Gantt plans (pricing) — product decision.
