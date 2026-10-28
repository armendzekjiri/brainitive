---
title: Search
description: Brainitive's search runs on PostgreSQL full-text search with no extra service. How the index is built and when to run search:reindex.
order: 70
---

# Search

Search in Brainitive ("Find anything": the search page, ⌘K and the MCP `search` tool) is built in. It runs on the PostgreSQL database you already have. There is no extra search service to install.

## How it works

- **PostgreSQL full-text search** finds words in issues, comments, pages, tickets, plans, tasks, snippets, bookmarks, quick captures and attachments.
- **`pg_trgm`** adds typo tolerance and **`unaccent`** ignores accents (so "cafe" finds "café"). Both are PostgreSQL extensions.
- Search only shows what the person searching may see. Drafts are visible only to their author and project admins.
- New and changed content is indexed as it is saved.

## Nothing to do after an update

When a version with a new or changed search index starts for the first time, Brainitive notices that the index was never built and queues the build:

- the Docker `app` container does this on start,
- the scheduler checks every five minutes (`search:ensure`).

The queue worker then fills the index in the background. Search shows what is already indexed meanwhile. On large installations this takes a few minutes. **The queue worker and the scheduler must be running** for this. See [Queue worker and scheduler](./queue-and-scheduler).

## The PostgreSQL extensions

The search migration creates `pg_trgm` and `unaccent` itself, if the database user is allowed to. The bundled Docker database allows it. On your own PostgreSQL, the user needs to own the database (PostgreSQL 13 or newer). On Ubuntu both extensions come with the `postgresql-contrib` package.

If the user isn't allowed, search still works, just without typo tolerance and accent folding. To add them later, let a superuser create them and refresh the index:

```bash
sudo -u postgres psql -d brainitive -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS unaccent;'
php artisan search:reindex --setup
```

## Rebuild the index by hand

You never have to, but you can, for example after restoring a backup or fixing data directly in the database:

```bash
# Docker
docker compose exec app php artisan search:reindex

# Manual install
cd /opt/brainitive && sudo -u www-data php artisan search:reindex
```

Options:

| Option | What it does |
|---|---|
| `--queue` | Queue the work for the queue worker instead of running it now |
| `--type=item` | Only one kind of content. Repeat for several. Types: `item`, `comment`, `page`, `ticket`, `plan`, `task`, `snippet`, `bookmark`, `capture`, `attachment`, `ticket_attachment` |
| `--chunk=500` | Records per batch (or per queued job) |
| `--setup` | First create the `pg_trgm` and `unaccent` extensions (if the user may) and refresh the index functions |

`php artisan search:ensure` queues a full build only when the current index version was never built. It does nothing otherwise.

## Search finds nothing?

1. Check that the queue worker runs. The index is built by queued jobs.
2. Check **Instance admin → Overview** for failed jobs.
3. Run `php artisan search:reindex` and watch the output.
