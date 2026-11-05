# Release v1.51.0: live whiteboards + poll votes for the mobile app (server side)

**Branch:** release/v1.51.0 (ticket branches below, merged locally)
**Status:** in progress
**Started:** 2026-11-05
**Board cards:** BRAI-208 (whiteboards on tablets), BRAI-200 (docs live / polls)

## Ticket branches (merged into release/v1.51.0)
- `feat/api-whiteboard-live`: App API live whiteboard drawing (`collab-token`, `collab/bootstrap`, `collab/scene`, `live` on whiteboards) + poll votes (`GET|POST /api/v1/polls/…`, shared `Support\Polls\PollVotes`). [plan](./feat-api-whiteboard-live.md)
  - security review fixes: malformed doc id → 404, votes stored under the record's own id, TV refusal covers polls.
- `feat/push-origin`: `data.origin` in mobile pushes (the app opens a push on the server that sent it; mobile security review).
- `fix/privacy-no-camera`: privacy policy 2.5 — photos via the picker only, no camera (the app blocks CAMERA).
- `fix/markdown-tables`: GFM tables in the MCP / App API Markdown converter (both directions) + `pages:repair-markdown-tables` for the docs (and `--items` issues) that stored them as text. [plan](./fix-markdown-tables.md)
- `fix/docs-site-vite`: docs-site vite 6.4.5 (Dependabot #256).

## Release content
- HelpModal `CHANGELOG`: v1.51.0 entry.
- Base: release/v1.50.0 (PR #144 not merged yet); merge into pre-release after v1.50.0.

## Gate
Pint, tsc, build, PHPUnit (PostgreSQL), e2e, docs-site build: see the release PR.
