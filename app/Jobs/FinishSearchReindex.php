<?php

namespace App\Jobs;

use App\Support\Search\SearchReindex;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Last link of a search index rebuild (BRAI-55): drop what the rebuild didn't see, then mark the
 * index version ready (only for full rebuilds, so search:ensure stops queueing them).
 */
class FinishSearchReindex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @param  list<string>  $types */
    public function __construct(public readonly array $types, public readonly string $startedAt, public readonly ?int $version) {}

    public function handle(): void
    {
        $startedAt = CarbonImmutable::parse($this->startedAt);
        foreach ($this->types as $type) {
            SearchReindex::prune($type, $startedAt);
        }
        if ($this->version !== null) {
            SearchReindex::markReady($this->version);
        }
    }
}
