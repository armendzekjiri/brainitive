# CI test gate + pre-release flow (BRAI-95)

**Branch:** ci/test-gate
**Status:** in progress (gate done; dev.brainitive.app + e2e follow)
**Started:** 2026-10-23
**Board card:** BRAI-95 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-204

## Problem / goal
Until now no CI ran the tests, and every push to `main` deployed to production. Tests only ran
locally on SQLite, while production runs PostgreSQL.

## Approach
- `tests.yml` runs on every feature-branch push and is reusable (`workflow_call`):
  - Pint
  - tsc + build (uploaded as an artifact, since PHPUnit needs the Vite manifest)
  - PHPUnit on SQLite and on Postgres 16 (same as production)
- `deploy.yml` (main): `needs: tests`, one deploy at a time, and the server fast-forwards to
  exactly `github.sha`.
- `pre-release.yml`: the integration branch runs the gate. Green means "ready for main".
- Flow: feature → PR into `pre-release` → PR `pre-release` → `main`.
- Versions match production: PHP 8.4, Node 22, Postgres 16.

## Found on the first Postgres run (fixed here)
- Search used `like`, which is case-sensitive on Postgres. Now `whereLike` / `orWhereLike`.
- A duplicate GitHub webhook delivery aborted the enclosing transaction. Now `createOrFirst`.
- A comment-pagination test assumed ids restart per test.

## dev.brainitive.app (done, waiting for DNS + certificate)
Same server, isolated. Every pre-release push deploys there and runs `migrate` on the existing
data. The reset + seed for e2e arrives with BRAI-96, so manual logins survive until then.
Server: own user, PHP pool, Postgres role / DB, nginx site with basic auth + noindex. Verified:
401 without the password, isolation in both directions, production unaffected.

## Next
- DNS `dev` → 162.19.229.57 (Cloudflare, DNS-only), then `certbot --nginx -d dev.brainitive.app`.
- BRAI-96: Playwright e2e against dev in `pre-release.yml`.
- Branch protection on `main` and `pre-release` (required checks) once the checks have run once.

## Test plan
- Suite green on SQLite and Postgres locally (scratch role and DB).
- Clean-checkout simulation (`.env.example`, fresh composer install, no assets → build needed).
- First CI run on this branch.
