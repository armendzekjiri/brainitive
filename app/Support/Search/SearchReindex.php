<?php

namespace App\Support\Search;

use App\Jobs\FinishSearchReindex;
use App\Jobs\ReindexSearchChunk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Full (re)builds of the search index (BRAI-55), by `search:reindex` and `search:ensure`.
 *
 * Forward pass: indexable ids of each type in id ranges of `$chunk` (one job per range when
 * queued). Then a prune pass: entries the forward pass didn't write (indexed before the run
 * started) are synced again — that removes whatever is gone or hidden. Nothing is ever loaded
 * whole: ranges are computed from ids only.
 */
final class SearchReindex
{
    /**
     * Index state marker. Bump it when what a source writes changes (new type, new fields): the
     * scheduled `search:ensure` then rebuilds the index in the background after the deploy.
     */
    public const VERSION = 1;

    /**
     * Id ranges [from, to] of the indexable records of a type, `$chunk` ids each.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function ranges(string $type, int $chunk): array
    {
        $ranges = [];
        app(SearchIndexer::class)->source($type)->ids()->reorder()->orderBy('id')
            ->chunk(max(1, $chunk) * 10, function ($rows) use (&$ranges, $chunk) {
                foreach (array_chunk($rows->pluck('id')->map(fn ($id) => (int) $id)->all(), max(1, $chunk)) as $ids) {
                    $ranges[] = [$ids[0], $ids[count($ids) - 1]];
                }
            });

        return $ranges;
    }

    /** Sync the indexable records of a type with ids in [from, to]. */
    public static function syncRange(string $type, int $from, int $to): int
    {
        $indexer = app(SearchIndexer::class);
        $source = $indexer->source($type);
        // the source query's own id column (e.g. items.id), as a subquery
        $ids = DB::query()->fromSub($source->ids(), 's')->whereBetween('s.id', [$from, $to])->pluck('s.id')->map(fn ($id) => (int) $id)->all();

        return $indexer->sync($type, $ids);
    }

    /**
     * Re-sync entries written before $startedAt (the forward pass didn't see them): removes what
     * is gone, hidden or orphaned.
     */
    public static function prune(string $type, CarbonImmutable $startedAt): int
    {
        $indexer = app(SearchIndexer::class);
        $removed = 0;
        DB::table('search_entries')
            ->where('type', $type)
            ->where(fn ($q) => $q->whereNull('indexed_at')->orWhere('indexed_at', '<', $startedAt))
            ->orderBy('id')
            ->select(['id', 'source_id'])
            ->chunkById(SearchIndexer::CHUNK, function ($rows) use ($indexer, $type, &$removed) {
                $ids = $rows->pluck('source_id')->map(fn ($id) => (int) $id)->all();
                $removed += count($ids) - $indexer->sync($type, $ids);
            }, 'id');

        return $removed;
    }

    /**
     * Queue a rebuild: one job per id range, then the prune + "ready" job, in one chain.
     *
     * @param  list<string>  $types
     * @return int jobs queued
     */
    public static function dispatch(array $types, int $chunk, bool $markReady): int
    {
        $startedAt = CarbonImmutable::now();
        $jobs = [];
        foreach ($types as $type) {
            foreach (self::ranges($type, $chunk) as [$from, $to]) {
                $jobs[] = new ReindexSearchChunk($type, $from, $to);
            }
        }
        $jobs[] = new FinishSearchReindex($types, $startedAt->toIso8601String(), $markReady ? self::VERSION : null);
        Bus::chain($jobs)->dispatch();

        return count($jobs);
    }

    // ── Index state (search_index_state, one row) ─────────────────────────────

    /** The current version was never built, or a build stopped long ago without finishing. */
    public static function needsBuild(): bool
    {
        $state = DB::table('search_index_state')->first();
        if ($state === null || (int) $state->version !== self::VERSION) {
            return true;
        }
        if ($state->status === 'ready') {
            return false;
        }

        // still building — unless it started so long ago that the chain must have failed
        return $state->started_at === null || CarbonImmutable::parse($state->started_at)->lt(now()->subHours(6));
    }

    public static function markBuilding(): void
    {
        self::writeState(['version' => self::VERSION, 'status' => 'building', 'started_at' => now(), 'finished_at' => null]);
    }

    public static function markReady(int $version): void
    {
        self::writeState(['version' => $version, 'status' => 'ready', 'finished_at' => now()]);
    }

    /** @param  array<string, mixed>  $values */
    private static function writeState(array $values): void
    {
        $id = DB::table('search_index_state')->orderBy('id')->value('id');
        $values['updated_at'] = now();
        if ($id === null) {
            DB::table('search_index_state')->insert($values + ['created_at' => now()]);
        } else {
            DB::table('search_index_state')->where('id', $id)->update($values);
        }
    }
}
