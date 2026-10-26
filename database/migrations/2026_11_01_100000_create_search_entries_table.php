<?php

use App\Support\Search\PostgresSearchSetup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Find anything" (BRAI-55): one search index over everything a person can open.
 *
 * Only the structure is created here — no data. The scheduled `search:ensure` (also run by the
 * Docker entrypoint) sees that this index version was never built and queues the backfill;
 * `php artisan search:reindex` does the same by hand. Model hooks keep it current afterwards.
 *
 * PostgreSQL:
 * - `pg_trgm` (typo tolerance) and `unaccent` (Größe = grosse) are created when the database user
 *   may; both are "trusted" extensions since PostgreSQL 13, so a database owner can. Without them
 *   search still works: no fuzzy fallback without pg_trgm, accents count without unaccent.
 * - `search_unaccent(text)` is an IMMUTABLE wrapper (unaccent itself is only STABLE), usable in
 *   the generated `tsv` column and in expression indexes; it returns its input unchanged when the
 *   extension is missing. `php artisan search:reindex --setup` re-checks the extensions later.
 * - `tsv` is generated: title (A), keys / labels / urls (B), body (C), with the 'simple' config
 *   (no stemming: people write German, English and Albanian) over unaccented text whose ASCII
 *   punctuation became spaces, so "BRAI-12" and "invoice-2024.pdf" split into findable words.
 *
 * SQLite (self-hosted zero-config): the same table without `tsv`; search falls back to LIKE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($pgsql) {
            PostgresSearchSetup::tryExtensions();
            PostgresSearchSetup::createUnaccentFunction();
        }

        Schema::create('search_entries', function (Blueprint $table) {
            $table->id();
            // App\Support\Search\SearchTypes — the source table is implied by the type
            $table->string('type', 24);
            $table->unsignedBigInteger('source_id');
            // project content: visible to people who can open the project
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            // the record it lives in: board, helpdesk, task list, issue (comments / files), ticket
            // (its files), snippet collection, bookmark collection
            $table->unsignedBigInteger('container_id')->nullable();
            // private content (captures): only this user
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('author_id')->nullable();
            // docs never published: only the author and project admins
            $table->boolean('is_draft')->default(false);
            $table->string('subtype', 24)->nullable();
            $table->string('key', 64)->nullable();
            $table->text('title');
            $table->text('keywords')->nullable();
            $table->text('body')->nullable();
            // filters: open / done, assignee, issue type
            $table->string('status', 8)->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->unsignedBigInteger('item_type_id')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('indexed_at')->nullable();

            $table->unique(['type', 'source_id']);
            $table->index(['project_id', 'type']);
            $table->index(['workspace_id', 'type']);
            $table->index(['owner_id']);
            $table->index(['type', 'container_id']);
            $table->index(['source_updated_at']);
        });

        // One row: which index version was built and when (search:ensure builds it in the
        // background after an update — nobody has to run a command by hand)
        Schema::create('search_index_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('status', 16); // building | ready
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        if (! $pgsql) {
            return;
        }

        $normalized = fn (string $column) => "regexp_replace(search_unaccent(coalesce({$column}, '')), '[[:punct:]]+', ' ', 'g')";
        DB::statement(<<<SQL
            alter table search_entries add column tsv tsvector generated always as (
                setweight(to_tsvector('simple'::regconfig, {$normalized('title')}), 'A')
                || setweight(to_tsvector('simple'::regconfig, {$normalized('keywords')}), 'B')
                || setweight(to_tsvector('simple'::regconfig, {$normalized('body')}), 'C')
            ) stored
        SQL);
        DB::statement('create index search_entries_tsv_index on search_entries using gin (tsv)');
        DB::statement('create index search_entries_key_index on search_entries (upper(key)) where key is not null');

        PostgresSearchSetup::createTrigramIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index_state');
        Schema::dropIfExists('search_entries');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists search_unaccent(text)');
        }
    }
};
