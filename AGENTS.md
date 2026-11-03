# Brainitive — Agent Instructions

> **Single source of truth:** architecture, conventions, patterns, workflow, and shared
> knowledge live in [`docs/ai/`](docs/ai/README.md). Read that first. This file only covers
> Cursor Cloud / Codex environment specifics.
>
> - [`docs/ai/ARCHITECTURE.md`](docs/ai/ARCHITECTURE.md) — module map + file pointers
> - [`docs/ai/CONVENTIONS.md`](docs/ai/CONVENTIONS.md) — coding rules & patterns
> - [`docs/ai/WORKFLOW.md`](docs/ai/WORKFLOW.md) — how we work, definition of done
> - [`docs/ai/skills/`](docs/ai/skills) — task recipes
> - [`docs/ai/KNOWLEDGE.md`](docs/ai/KNOWLEDGE.md) — decisions & gotchas
> - [`docs/ai/tasks/`](docs/ai/tasks) — one plan file per branch
> - [`docs/ai/API.md`](docs/ai/API.md) — the desktop / mobile App API (`/api/v1`): registry +
>   conventions; new endpoints follow [`docs/ai/skills/add-app-api-endpoint.md`](docs/ai/skills/add-app-api-endpoint.md)

## Cursor Cloud specific instructions

### Overview

Brainitive is a Laravel 12 + Inertia.js + React 18 + TypeScript monolith on **PostgreSQL only** (like production).
Cache, queue and sessions use the same database (`database` drivers); no Redis / MySQL is needed. Set
`DB_CONNECTION=pgsql` and the `DB_*` values in `.env`. SQLite is not supported — not for the app, the tests or e2e.

### Ground rules (read before running anything)

- **Never reset a database you didn't create for the run.** No `migrate:fresh`, `migrate:refresh`, `migrate:reset`,
  `db:wipe`, truncating seeders, or dropping / truncating tables against the database in `.env`: it is the owner's real
  local data. New migrations run only in the test / e2e databases (PHPUnit and Playwright migrate their own).
- **Tests:** PHPUnit always uses `brainitive_test` (forced in `phpunit.xml`); `TEST_DB_SUFFIX=<a-z0-9_>` switches to
  `brainitive_test_<suffix>` (one per worktree / parallel run; create it once:
  `createdb -O <your DB user> brainitive_test_<suffix>`). e2e uses `brainitive_e2e_<port>` (created by its global setup,
  one per `E2E_PORT`).
- **Commits:** identity `armendzekjiri <armendzekjiri@outlook.de>`
  (`git -c user.name=armendzekjiri -c user.email=armendzekjiri@outlook.de commit …`), never another address.
- **No push, no PRs, no merges into `main` / `pre-release`** without the owner: branches stay local until they say so.
- **No broad process kills** (`pkill -f php`, `pkill node`, `killall …`): the owner runs `composer run dev` on :8000.
  Stop only processes you started, by PID.

### Running the app

The `composer.json` `"dev"` script starts all four processes concurrently:

```
composer run dev
```

This runs: `php artisan serve`, `php artisan queue:listen`, `php artisan pail`, and `npm run dev` (Vite). Alternatively, start them individually:

- **Laravel server**: `php artisan serve --host=0.0.0.0 --port=8000`
- **Vite dev server**: `npm run dev -- --host 0.0.0.0`
- **Queue worker**: `php artisan queue:listen --tries=1 --timeout=0`

### Lint / Test / Build

- **PHP lint**: `./vendor/bin/pint --test` (Laravel Pint)
- **TypeScript check**: `npx tsc --noEmit`
- **PHPUnit tests**: `php artisan test` (PostgreSQL, in the forced database `brainitive_test` — create it once with `createdb -O <your DB user> brainitive_test`; `TEST_DB_SUFFIX=<name> php artisan test …` for a separate `brainitive_test_<name>`)
- **E2E tests**: `npm run e2e` (Playwright, PostgreSQL database `brainitive_e2e_<port>`; see `e2e/README.md`)
- **Frontend build**: `npm run build` (runs `tsc && vite build`)

### Known issues

- **Email verification**: Registration requires email verification. With the default `MAIL_MAILER=log` configuration, verification emails are written to `storage/logs/`. To bypass during development, manually verify the user: `php artisan tinker --execute="\App\Models\User::where('email','...')->update(['email_verified_at'=>now()]);"`.
