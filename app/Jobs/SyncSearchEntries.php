<?php

namespace App\Jobs;

use App\Support\Search\SearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sync some search index entries on the queue (BRAI-55): large cascades (a trashed board) and
 * batches whose inline sync failed. SearchIndexer::sync() re-reads the records, so a late or
 * repeated run is harmless.
 */
class SyncSearchEntries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 300];

    /** @param  list<int>  $ids */
    public function __construct(public readonly string $type, public readonly array $ids) {}

    public function handle(SearchIndexer $indexer): void
    {
        $indexer->sync($this->type, $this->ids);
    }
}
