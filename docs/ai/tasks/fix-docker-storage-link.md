# Docker: uploaded icons 404 because storage:link fails as www-data (GitHub #153)

**Branch:** fix/docker-storage-link
**Status:** done
**Started:** 2026-11-15
**Board card:** — (MCP not used for this task)

## Problem / goal
In the Docker image, uploads (project / bookmark / item-type icons, logos, editor images) are served from
`public/storage` → `storage/app/public`. The image never creates that link, and the entrypoint runs
`php artisan storage:link >/dev/null 2>&1 || true` — but the container runs as `USER www-data` while
`/app/public` belongs to root, so the link can't be created and the failure is swallowed. Every upload URL
answers 404 in a fresh Docker install.

## Approach
- **Dockerfile:** create the link at build time in the root `RUN` block (before `USER www-data`):
  `ln -sfn /app/storage/app/public /app/public/storage`. The target lives in the `storage` volume; the
  entrypoint already recreates `storage/app/public` on an empty volume, so the link is never dangling at
  runtime. `public/storage` is in `.dockerignore`, so a developer's local link can't leak into the build.
- **entrypoint.sh:** only run `storage:link` when `public/storage` is missing (older images, a bind-mounted
  `public/`), and don't silence it: on failure print a clear warning to stderr naming the consequence (images
  404) and the fix (update the image / create the link as root). A warning, not an exit: the app still works
  apart from uploaded files, and a crash loop would be worse.
- Alternative rejected: `chown www-data public/` so the runtime can link — widens write access to the web
  root for one symlink.

## Changes
- `Dockerfile` — `ln -sfn` in the root RUN block.
- `docker/entrypoint.sh` — conditional `storage:link` with a warning.
- `docs-site/self-hosting/troubleshooting.md` — "Uploaded images don't show": Docker bullet.
- Test: `tests/Unit/DockerStorageLinkTest.php`.

## Test plan
- There is no existing Docker / entrypoint test (nothing in `tests/` or the workflows runs the image). Added a
  static regression test in the style of `CliInstallScriptTest` (reads the repo files): the Dockerfile creates
  the link before `USER www-data`, and the entrypoint guards `storage:link` and doesn't discard its output /
  `|| true` it.
- Manual: a small throwaway container that mimics the layout (root-owned `/app/public`, `USER www-data`) runs
  the new entrypoint block: with the build-time link it does nothing; without it, it prints the warning and
  continues. A full image build was not run locally (WSL memory limits; the CI Docker publish builds it).

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
- KNOWLEDGE: Docker gotcha (the runtime user can't write `/app/public`; anything in the web root is created
  at build time).

## Open questions
- None.
