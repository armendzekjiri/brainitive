# GitHub development panel (BRAI-61, reshaped)

**Branch:** feat/github-development
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-61 (#item-163)

## What the user asked
Jira-like: commits, branches and PRs connect to cards automatically from the issue key in
the commit message / branch / PR (`fix(BRAI-123): …`, `feat/brai-2423_fix_column`), and the
card shows the development state including whether it is deployed.

## Decisions
- **Per-project webhook instead of a GitHub App (v1).** Project admins create a connection in
  Project settings → Integrations, paste the URL + secret into the repo's webhook settings
  (one secret can serve several repos). No GitHub tokens are stored, no outbound API calls,
  works the same on brainitive.app and self-hosted. A GitHub App (history backfill) can follow.
- **Matching:** only the project's own key, case-insensitive, not inside a longer word
  (`(?<![A-Za-z0-9])KEY-123(?!\d)`), max 10 keys per text. Commits link by message, branches
  by name, PRs by title + head branch (Jira's rules). Unknown keys are ignored.
- **Webhook:** `POST /webhooks/github/{uuid}`, HMAC-SHA256 over the raw body
  (`X-Hub-Signature-256`, constant-time), unique `X-GitHub-Delivery` per connection,
  payload stored and processed by a queued job (retries are idempotent: upserts keyed by
  item + kind + repo + identifier). Deliveries pruned after 14 days.
- **Events:** ping, push, create/delete (branches), pull_request, deployment_status.
- **Deployments:** a deployment of ref R to environment E at time T includes the deployed sha
  plus commits that landed on R, and PRs merged into R, after the previous successful deploy to E
  (per repo) and up to T. Each included card gets one "deployment" row per environment with the
  latest state (success / failure / in progress). Our own deploy.yml gets `environment: production`
  so GitHub emits deployment events.
- **Card UI:** "Development" in the issue sidebar: branches, commits, PRs (open/draft/merged/closed),
  deployments per environment, and "Copy branch name" (`feat/brai-123-short-title`).
- **Removal:** disconnecting deletes the connection and everything it linked (no cached repo
  metadata stays). Only people who can view the issue see its development info.
- Not in v1: auto-moving cards on merge, outbound writes to GitHub, history backfill.

## Tests
Signature/ dedupe/ unknown connection; push (message + branch keys, other project's key ignored);
branch delete; PR states; deployment inclusion window; settings permissions; item details payload.

## Also fixed
`#item-123` links (search, palette, MCP `location.url`) never opened the issue — the board now
opens it from the hash (or the issue page when it isn't on the board).
