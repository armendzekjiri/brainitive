# Fix: Docker self-hosting never started; workspace timezone list cut off

**Branch:** fix/docker-postgres-timezones
**Status:** done
**Started:** 2026-10-25
**Board card:** —

## Problem / goal
1. Following the README / `docs/self-hosting.md` Docker quick start on a fresh clone, the `app`
   container restart-looped: migration `2026_02_23_140000_move_bookmarks_to_workspace` fails on
   MariaDB (error 1553, the dropped unique index backs a foreign key), and because MySQL DDL isn't
   transactional every restart then fails on "Duplicate column 'workspace_id'". The migrations are
   only ever run on PostgreSQL (tests, CI, production).
2. With the example `.env`, sign-up answered 500: `MAIL_MAILER=smtp` pointed at
   `smtp.example.com`, although the docs say you can get in before mail works.
3. `queue` and `scheduler` showed "unhealthy": they inherit FrankenPHP's healthcheck, which probes
   Caddy, and only `app` runs Caddy.
4. Workspace settings → Timezone showed only `timezones.slice(0, 100)` — alphabetically the
   first 100 zones end in the Americas, so Asia, Europe, Pacific… were missing. The update
   accepted any string.

## Changes
- `compose.yaml`: `db` is `postgres:16-alpine` (same major as CI) with a `pg_isready`
  healthcheck; `healthcheck: disable: true` on queue + scheduler.
- `.env.docker.example`: `DB_CONNECTION=pgsql`, port 5432, no root password;
  `MAIL_MAILER=log` by default.
- `docs/self-hosting.md` / `README.md`: PostgreSQL, `pg_dump` backup + restore, troubleshooting
  for the old MariaDB volume and mail errors.
- `components/TimezonePicker.tsx` (new): searchable Popover + Command over every zone, with the
  current UTC offset; used in `Workspaces/Edit.tsx`.
- `WorkspaceController::update`: `timezone` validated with `timezone:all`.

## Verification
- Fresh clone + quick start in a scratch directory: all five containers up and healthy, migrations
  and seeders run, `/login` 200, registration → `/workspaces`, `make:instance-admin` works, no
  errors in the logs.
- `tests/Feature/WorkspaceSettingsTest.php`; e2e "any timezone in the world can be searched and
  saved" in `workspaces-projects.spec.ts`.
