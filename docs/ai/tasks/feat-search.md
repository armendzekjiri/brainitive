# Find anything: real search on PostgreSQL (BRAI-55)

**Branch:** feat/search (release v1.43.0)
**Status:** done
**Started:** 2026-10-26
**Board card:** BRAI-55 (item 157) — "opensearch e real search engine"

## Problem / goal
The old global search (`SearchController`) ran `ILIKE '%…%'` over titles of a few types, with no
ranking, no typo tolerance, no doc text and no filters. Build real search — full text, ranked, typo
tolerant, filterable, highlighted — over everything a person can open, **without a new server**:
PostgreSQL full text (tsvector + GIN, `ts_rank_cd`, `ts_headline`) plus `pg_trgm`, behind a small
interface so OpenSearch / Meilisearch can be plugged in later.

Non-goals (v1.43.0): semantic / vector search, AI calls, attachment *contents*, saved searches.

## How it works (for the user)
- **`/search`** (`Pages/Search/Index.tsx`): a big search field (`/` focuses it), filter chips
  (Project — several, Status open/done, Assignee me/person, Updated past week/month/3 months/year,
  Clear filters), a tab per type with counts (All, Issues, Docs, Comments, Tickets, Tasks, Plans,
  Snippets, Bookmarks, Captures, Files), ranked results with the matches highlighted, ↑/↓ + ↵,
  Previous / Next pages, tips when empty, suggestions + "Search everything" when nothing matched,
  "No exact matches — showing similar titles" for the typo fallback. URL keeps the state.
- **⌘K palette**: "Top results" (8, from the index, typo tolerant) + **See all N results** →
  `/search?q=…`, then places (boards, plans, projects, workspaces, snippet / bookmark
  collections) as before. Endpoint `search.quick` (`GET /search/quick`, ReadOnlySession,
  `throttle:240,1`).
- **Query syntax**: words AND-ed, last word is a prefix (`road` → roadmap), `"exact phrase"`,
  `-word` / `-"phrase"` excludes, an issue / ticket key (`WEB-12`) is matched exactly and ranks
  first. Accents, case and punctuation don't matter (`grosse` = Größe, `invoice` finds
  `invoice-2024.pdf`).
- **MCP `search`** (same service, same visibility) with optional `type`, `status`,
  `assigned_to_me`, `limit`; returns `snippet` too. Handles stay fetchable: `item`, `page`,
  `ticket`, `plan`, `task`, `capture`; comments / issue files → `item:<issue>`, ticket files →
  `ticket:<ticket>`, snippets / bookmarks → their collection. `fetch` learned ticket / plan /
  task / capture (policies: TicketPolicy, PlanPolicy, TaskListPolicy, owner only).

## Index design
- **One table `search_entries`** (migration `2026_11_01_100000`): one row per searchable record,
  stable key `(type, source_id)` unique. Columns: `project_id` / `workspace_id` (FK cascade —
  hard-deleting a project / workspace drops its entries), `container_id` (board, helpdesk, task
  list, issue for comments & files, ticket for ticket files, snippet / bookmark collection),
  `owner_id` (captures, FK cascade), `author_id`, `is_draft`, `subtype` (doc / whiteboard,
  snippet language, bookmark kind), `key`, `title`, `keywords`, `body` (plain text, ≤ 60 000
  chars), `status` open|done, `assignee_id`, `item_type_id`, `source_updated_at`, `indexed_at`.
- **`tsv`** = generated column: `setweight(title,'A') || setweight(keywords,'B') ||
  setweight(body,'C')`, config `'simple'` (no stemming — German / English / Albanian mixed), over
  `regexp_replace(search_unaccent(x), '[[:punct:]]+', ' ')`. Indexes: GIN(tsv), btree
  `upper(key)` (partial), GIN trigram on `search_unaccent(lower(title))`, plus (project_id, type),
  (workspace_id, type), owner_id, (type, container_id), source_updated_at.
- **`search_unaccent(text)`**: IMMUTABLE SQL wrapper (unaccent is only STABLE), schema-qualified;
  returns the input unchanged when `unaccent` is missing. `App\Support\Search\PostgresSearchSetup`
  creates extensions (in a savepoint — a privilege error never aborts the migration), the function
  and the trigram index; reused by `search:reindex --setup`.
- **`search_index_state`**: one row (version, status building|ready, started/finished). Drives the
  automatic first build.
- **Sources** (`app/Support/Search/Sources/*`): one class per type, query-builder only, fixed
  number of queries per batch. `documents($ids)` is the single source of truth — an id it doesn't
  return (gone, trashed, inside a trashed board / helpdesk / list / bookmark collection, a folder)
  is removed. Content: issues (key, title, description, labels, custom field text values / options
  of active fields), comments (text only; the result names the issue live), docs (published
  `name` + `content`; whiteboards title only), tickets (key, subject, requester name + email,
  description + all replies incl. internal notes), plans (name, description, row names), tasks,
  snippets (title, language, description, code), bookmark entries (title, URL, notes), captures,
  issue + ticket attachment file names.
- **Index maintenance**: `SearchIndexObserver` (registered in `SearchServiceProvider` for the
  indexed models and the containers that decide visibility; no model file edited) only *touches*
  `(type, id)` keys; `SearchIndexer::flush()` re-reads them and upserts / deletes. Flush runs after
  the response (`terminating`), after each queued job (`Queue::after`), at the end of a command,
  and **before every search** (a request always finds its own writes). Cascades: an issue's move /
  trash / restore / hard delete touches its subtree + comments + files; board / helpdesk / task
  list / bookmark collection trash / restore / move touches their content; done-column flag,
  label rename / delete, custom field archive / option rename touch the issues; a doc's deletion
  touches its sub-pages (the database cascades them). Label changes are caught through the
  `labels_changed` ActivityLog entry every label path writes; labels synced right after an issue
  is created are picked up because flushing happens later in the request. Errors are reported,
  never thrown.

## Query (PostgresSearchEngine)
- `QueryParser` turns input into terms of letters / digits only (nothing reaches tsquery syntax),
  `toTsQuery()` builds `'a' & ('web' <-> '12':*) & !'x'`.
- **Visibility in SQL** (`TableSearchEngine::visibleEntries`, `SearchViewer` loaded in ~6 queries):
  project content → `project_id ∈ accessibleProjectIds()` and (not draft, or author, or
  `adminProjectIds()`); snippets → workspace member; bookmarks → `Bookmark::visibleTo` collection
  ids; captures → owner. Counts and pages use the same builder — no post-filtering.
- **Match**: `tsv @@ to_tsquery('simple', search_unaccent(?))` OR exact key.
- **Typo fallback**: when the exact total (selected type) < 3 and the query has no quoted phrase:
  titles where *every* typed word (≥ 3 letters) has `search_unaccent(lower(title)) %> word` (pg_trgm
  `word_similarity` ≥ 0.35, set with `set_config(…, true)` inside a transaction) also match —
  still without excluded words. `fuzzy: true` only when that added results.
- **Rank (one expression, `PostgresSearchEngine::page`)**: `ts_rank_cd(tsv, q, 32)` + 10 for an
  exact key + recency `0.15 / (1 + age/30 days)` + 0.05 for the project being viewed (+ 0.5 ×
  mean word_similarity in the fallback). Ties: newest, id.
- **Highlight**: `ts_headline` on the page's rows only, markers U+E000 / U+E001 (stripped from all
  indexed text), then `SearchHighlight::toHtml` escapes everything and turns markers into
  `<mark>`; the frontend `onlyMarks()` drops any other tag as a second guard.
- **Presenting** (`SearchPresenter`): URLs / names read live in one query per kind of record on
  the page (projects, issues, tickets, …), never per result; vanished records are dropped.
- **Measured** (6 000 entries, local PG 18): 12–30 ms per search (2–4 index queries), typo
  fallback ~50 ms; EXPLAIN shows Bitmap Index Scan on `search_entries_tsv_index`, the key index,
  and the trigram index when selective.
- **`BasicSearchEngine`** (SQLite self-hosted installs): LIKE per word, same visibility / filters,
  PHP highlighting. Bound by driver in `SearchServiceProvider`.

## Deploy / backfill
- **Nobody has to run anything.** `search:ensure` (scheduled every 5 minutes, and run by
  `docker/entrypoint.sh` right after `migrate`) sees that `SearchReindex::VERSION` was never built
  and queues the full rebuild (`Bus::chain` of `ReindexSearchChunk` jobs per id range of 500 +
  `FinishSearchReindex`, which prunes what the pass didn't see and marks the version ready). A
  build that died is retried after 6 h. Production (`deploy.yml` runs migrate, the scheduler and
  queue workers already run) is covered by the schedule — no workflow change; first build within
  ~5 minutes of the deploy.
- By hand: `php artisan search:reindex` (inline, progress per type), `--queue`, `--type=item`
  (repeatable), `--chunk=500`, `--setup` (create the extensions if the DB user may, refresh
  `search_unaccent`, add the trigram index, recompute `tsv`).
- **Production needs**: PostgreSQL ≥ 13 and a DB user that owns the database (then `pg_trgm` and
  `unaccent`, both trusted extensions, are created by the migration). Without them search works
  without typo tolerance / accent folding; a superuser can run `create extension pg_trgm;
  create extension unaccent;` and then `php artisan search:reindex --setup`.
- Bump `SearchReindex::VERSION` whenever a source writes something new → automatic rebuild.

## Next release: semantic / hybrid search (not built)
- One row per entity with stable `(type, source_id)` and plain-text `body` is what an embedding
  job reads. Plug-in points: a nullable `embedding vector(n)` column (+ `embedded_at`, model id)
  on `search_entries` or a 1:1 side table keyed by the entry id; a queued `EmbedSearchEntries` job
  dispatched from `SearchIndexer::sync()` for changed rows of opted-in workspaces (workspace AI
  provider via `OutboundUrl`); hybrid merge in `PostgresSearchEngine::page()` — the score is one
  SQL expression, so a `+ w × (1 - (embedding <=> :q))` term (or RRF of two ranked CTEs) slots in
  next to `ts_rank_cd`; visibility stays in `visibleEntries()`.

## Files
- Migration `database/migrations/2026_11_01_100000_create_search_entries_table.php`
- `app/Support/Search/` — `SearchEngine` (interface), `Engines/{TableSearchEngine,PostgresSearchEngine,BasicSearchEngine}`,
  `Sources/*`, `SearchIndexer`, `SearchReindex`, `SearchService`, `SearchPresenter`, `SearchQuery`,
  `QueryParser`, `SearchViewer`, `SearchResults`, `SearchDocument`, `SearchText`, `SearchHighlight`,
  `SearchTypes`, `PostgresSearchSetup`
- `app/Observers/SearchIndexObserver.php`, `app/Providers/SearchServiceProvider.php` (+ `bootstrap/providers.php`)
- `app/Console/Commands/{SearchReindexCommand,SearchEnsureCommand}.php`, `app/Jobs/{ReindexSearchChunk,FinishSearchReindex}.php`,
  schedule in `routes/console.php`, `docker/entrypoint.sh`
- `app/Http/Controllers/SearchController.php` (`page`, `quick`), routes `search`, `search.quick`
- `app/Mcp/Tools/{SearchTool,FetchTool}.php`
- `resources/js/Pages/Search/Index.tsx`, `resources/js/components/search/SearchBits.tsx`,
  `resources/js/components/CommandPalette.tsx`, types at the end of `types/index.d.ts`
- `docs/self-hosting.md` ("Search")
- Tests `tests/Feature/Search/*` (32), e2e `e2e/tests/search.spec.ts`; adjusted
  `CommandPaletteTest`, `AdminAccessTest`, `SecurityRegressionTest`, `leads-plans-search.spec.ts`
  (`/search` JSON → `/search/quick`)

## For the lead: doc updates (not done here — docs/ai is yours)
- **ARCHITECTURE** → "MyWork, Search …": replace the Search bullet with: `SearchController`
  (`search` page `Pages/Search/Index`, `search.quick` palette JSON) → `App\Support\Search`
  (engine interface + Postgres / Basic engines, sources per type, `SearchIndexer` + observer in
  `SearchServiceProvider`, `search:reindex` / `search:ensure`, table `search_entries`). Command
  palette bullet: top results + "See all results" from `search.quick`. MCP: `fetch` handles now
  include ticket / plan / task / capture.
- **KNOWLEDGE** → new entry "Search index (BRAI-55)": hooks only touch keys, flush re-reads (after
  response / job / command, before every search); write content through models or call
  `app(SearchIndexer::class)->touch()` (query-builder bulk updates bypass it — run
  `search:reindex --type=…` after data fixes); visibility lives in `TableSearchEngine::visibleEntries`
  and must mirror the view policies (like ContentReferences); bump `SearchReindex::VERSION` when a
  source changes; never index `draft_content`; `DB::table()->whereKey()` is a dynamic where on a
  `key` column, not the primary key (found writing these tests).
- **Changelog line**: "Find anything: a real search page (/search) and ⌘K results across issues,
  docs, comments, tickets, tasks, plans, snippets, bookmarks, your captures and file names — ranked,
  typo tolerant, with phrases, -exclusions, issue keys, highlights and filters by type, project,
  status, assignee and date. Runs on PostgreSQL, no extra service; the index builds itself after
  the update."
- Homepage (Welcome.tsx) not touched — per the brief.

## Test plan / results
- PHPUnit `TEST_DB_SUFFIX=search php artisan test tests/Feature/Search` (+ CommandPaletteTest,
  AdminAccessTest, SecurityRegressionTest search test).
- e2e `e2e/tests/search.spec.ts`, `leads-plans-search.spec.ts`, `custom-sidebar.spec.ts`,
  `quick-capture.spec.ts`.
