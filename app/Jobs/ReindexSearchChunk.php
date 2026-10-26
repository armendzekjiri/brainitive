<?php

namespace App\Jobs;

use App\Support\Search\SearchReindex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One id range of one type of a search index rebuild (BRAI-55, SearchReindex::dispatch). */
class ReindexSearchChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $type, public readonly int $from, public readonly int $to) {}

    public function handle(): void
    {
        SearchReindex::syncRange($this->type, $this->from, $this->to);
    }
}
