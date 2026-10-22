# Automatic releases: tag + GitHub Release + Docker image on push to main

**Branch:** chore/release-workflow
**Status:** done
**Started:** 2026-10-22
**Board card:** BRAI-50

## Problem / goal
The repo has no tags, so `docker-publish.yml` only ever built `:edge` and `compose.yaml`'s
default `:latest` image didn't exist. Releasing should happen on merge to `main`.

## Approach
The version is the newest `CHANGELOG` entry in `HelpModal.tsx` (existing convention), so
bumping stays a deliberate, human-written step and the in-app "What's new" always matches
the release. Alternative considered: auto-bump from conventional commits — rejected
because it needs a bot committing back to `main` to keep the in-app version in sync.

- `.github/scripts/changelog-release.mjs` — reads version / title / items, writes notes.
- `.github/workflows/release.yml` — on push to main: create tag + release if the version is
  new, then call the image build.
- `.github/workflows/docker-publish.yml` — now `workflow_call` (+ hand-pushed `v*` tags,
  manual dispatch); no separate `main` trigger, so each push builds once.

## Test plan
- Script run locally against the current HelpModal (v1.15.0, 6 items).
- Workflows parse; first real run happens on merge to main (creates v1.15.0).

## Docs updated
ARCHITECTURE (self-hosting / releases), WORKFLOW (version bump releases), KNOWLEDGE
(GITHUB_TOKEN tags don't trigger workflows), self-hosting.md (image tags).
