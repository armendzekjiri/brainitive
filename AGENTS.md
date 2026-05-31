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

## Cursor Cloud specific instructions

### Overview

Brainitive is a Laravel 12 + Inertia.js + React 18 + TypeScript monolith. It uses SQLite by default (zero-config), with all services (cache, queue, session) backed by the same SQLite database. No external services (Redis, MySQL, etc.) are needed for local development.

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
- **PHPUnit tests**: `php artisan test` (uses SQLite in-memory)
- **Frontend build**: `npm run build` (runs `tsc && vite build`)

### Known issues

- **Migration `2026_02_03_145210_add_member_role_to_workspace_user_table`** uses `ALTER TABLE ... DROP CONSTRAINT` syntax that is incompatible with SQLite. This affects both `php artisan migrate` on a fresh DB and PHPUnit tests (which use `:memory:` SQLite). Workaround for fresh setup: recreate the `workspace_user` table with the `'member'` role in the CHECK constraint, then fake the migration. The same migration also causes all Feature tests to fail with `RefreshDatabase`.
- **Migration `2026_02_23_153119_extend_bookmarks_icon_for_url`** uses MySQL `MODIFY` syntax without a SQLite branch. Since SQLite doesn't enforce varchar length, this can be safely faked for local development.
- **Email verification**: Registration requires email verification. With the default `MAIL_MAILER=log` configuration, verification emails are written to `storage/logs/`. To bypass during development, manually verify the user: `php artisan tinker --execute="\App\Models\User::where('email','...')->update(['email_verified_at'=>now()]);"`.
