<?php

namespace App\Console\Commands;

use App\Support\Search\SearchIndexer;
use App\Support\Search\SearchReindex;
use App\Support\Search\SearchTypes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Self-maintaining search index (BRAI-55): when this version of the index was never built (a
 * fresh install, an update that brought or changed search) — or a build died — queue a full
 * rebuild in the background. Runs from the scheduler every few minutes and from the Docker
 * entrypoint after migrating, so self-hosters and the production deploy never run anything by
 * hand. Cheap when there is nothing to do (one small query).
 */
class SearchEnsureCommand extends Command
{
    protected $signature = 'search:ensure';

    protected $description = 'Queue the search index build when this version of the index has not been built yet';

    public function handle(): int
    {
        if (! SearchReindex::needsBuild()) {
            $this->line('Search index is ready.');

            return self::SUCCESS;
        }

        // one build at a time, even when the entrypoint and the scheduler start together
        $lock = Cache::lock('search:ensure', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            if (! SearchReindex::needsBuild()) {
                return self::SUCCESS;
            }
            SearchReindex::markBuilding();
            $jobs = SearchReindex::dispatch(SearchTypes::all(), SearchIndexer::CHUNK, true);
            $this->info("Search index build queued ({$jobs} jobs).");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
