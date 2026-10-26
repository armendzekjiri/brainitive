<?php

namespace App\Console\Commands;

use App\Support\Search\PostgresSearchSetup;
use App\Support\Search\SearchReindex;
use App\Support\Search\SearchTypes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds the search index (BRAI-55) from the database: every indexable record of the chosen
 * types, in id ranges, then removes entries of records that are gone or hidden.
 *
 *   php artisan search:reindex                     # everything, now, with progress
 *   php artisan search:reindex --type=item --type=page
 *   php artisan search:reindex --queue             # one queued job per range (queue worker)
 *   php artisan search:reindex --setup             # create pg_trgm / unaccent if allowed first
 *
 * Normally nobody runs it: the scheduled search:ensure queues a full build after an update.
 */
class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex
        {--type=* : Only these types (item, comment, page, ticket, plan, task, snippet, bookmark, capture, attachment, ticket_attachment)}
        {--chunk=500 : Records per batch / job}
        {--queue : Queue the batches instead of running them now}
        {--setup : Create the pg_trgm / unaccent extensions (if the database user may) and refresh the index functions first}';

    protected $description = 'Build the search index from the database';

    public function handle(): int
    {
        $types = $this->option('type') ?: SearchTypes::all();
        $unknown = array_diff($types, SearchTypes::all());
        if ($unknown !== []) {
            $this->error('Unknown type(s): '.implode(', ', $unknown).'. Known: '.implode(', ', SearchTypes::all()));

            return self::INVALID;
        }
        $chunk = max(1, min(5000, (int) $this->option('chunk')));
        $full = ! $this->option('type');

        if ($this->option('setup') && DB::getDriverName() === 'pgsql') {
            $this->setup();
        }

        if ($this->option('queue')) {
            if ($full) {
                SearchReindex::markBuilding();
            }
            $jobs = SearchReindex::dispatch(array_values($types), $chunk, $full);
            $this->info("Queued {$jobs} search index jobs.");

            return self::SUCCESS;
        }

        if ($full) {
            SearchReindex::markBuilding();
        }
        $startedAt = CarbonImmutable::now();
        foreach ($types as $type) {
            $ranges = SearchReindex::ranges($type, $chunk);
            $indexed = 0;
            $bar = $this->output->createProgressBar(count($ranges));
            $bar->setFormat("  {$type}: %current%/%max% batches");
            foreach ($ranges as [$from, $to]) {
                $indexed += SearchReindex::syncRange($type, $from, $to);
                $bar->advance();
            }
            $bar->finish();
            $removed = SearchReindex::prune($type, $startedAt);
            $this->line(" — {$indexed} indexed, {$removed} removed");
        }
        if ($full) {
            SearchReindex::markReady(SearchReindex::VERSION);
        }
        $this->info('Search index is up to date.');

        return self::SUCCESS;
    }

    private function setup(): void
    {
        PostgresSearchSetup::tryExtensions();
        PostgresSearchSetup::createUnaccentFunction();
        PostgresSearchSetup::createTrigramIndex();
        PostgresSearchSetup::forgetCapabilities();
        // the generated tsv column is computed on write: rewrite every entry with the new function
        DB::table('search_entries')->update(['title' => DB::raw('title')]);
        $caps = PostgresSearchSetup::capabilities();
        $this->line('pg_trgm (typo tolerance): '.($caps['trigram'] ? 'on' : 'off — ask your database admin to run "create extension pg_trgm"'));
        $this->line('unaccent (accent-insensitive): '.($caps['unaccent'] ? 'on' : 'off — ask your database admin to run "create extension unaccent"'));
    }
}
