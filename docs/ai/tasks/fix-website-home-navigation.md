# Website home navigation

**Branch:** fix/website-home-navigation
**Status:** in-progress
**Started:** 2026-10-30

## Problem / goal
The marketing homepage can be served outside Inertia. Inertia links to `/` show its HTML in an error modal instead of navigating.

## Approach and changes
Use native anchors for app links to `/`, including legal and public pages and guest layouts. Force a full browser navigation after logout while preserving `/` for self-hosted instances. Leave app-internal Inertia links intact.

## Test plan
Run TypeScript checks and a Playwright regression that serves a static homepage and checks legal home links perform a document navigation.

## Docs
Record the navigation boundary in shared knowledge.
