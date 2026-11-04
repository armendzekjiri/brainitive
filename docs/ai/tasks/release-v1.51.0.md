# Release v1.51.0: live whiteboards + poll votes for the mobile app (server side)

**Branch:** release/v1.51.0 (ticket branches below, merged locally)
**Status:** in progress
**Started:** 2026-11-05
**Board cards:** BRAI-208 (whiteboards on tablets), BRAI-200 (docs live / polls)

## Ticket branches (merged into release/v1.51.0)
- `feat/api-whiteboard-live`: App API live whiteboard drawing (`collab-token`, `collab/bootstrap`, `collab/scene`, `live` on whiteboards) + poll votes (`GET|POST /api/v1/polls/…`, shared `Support\Polls\PollVotes`). [plan](./feat-api-whiteboard-live.md)
  - security review fixes: malformed doc id → 404, votes stored under the record's own id, TV refusal covers polls.
- `fix/docs-site-vite`: docs-site vite 6.4.5 (Dependabot #256).

## Release content
- HelpModal `CHANGELOG`: v1.51.0 entry.
- Base: release/v1.50.0 (PR #144 not merged yet); merge into pre-release after v1.50.0.

## Gate
Pint, tsc, build, PHPUnit (PostgreSQL), e2e, docs-site build: see the release PR.
