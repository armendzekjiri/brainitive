<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Console\Command;

/**
 * Codex web review M (v1.49.0): versions recorded while a page was a private draft are marked `is_draft` since this
 * release; this marks the existing ones. Only whiteboards record versions outside a publish (checkpoints, restores),
 * so docs are left alone. A whiteboard that is private now (draft, archived draft): all its versions. A published
 * one: the versions older than its last publish (`published_at`) — conservative: a whiteboard that was unpublished
 * and published again keeps its earlier history for its author and admins only.
 *
 * Dry run by default (reports the counts); `--force` marks them. Run once after deploying v1.49.0.
 */
class MarkDraftPageVersions extends Command
{
    protected $signature = 'pages:mark-draft-versions {--force : mark them (without it: only report)}';

    protected $description = 'Report (or with --force mark) whiteboard versions recorded before the board was published as private';

    public function handle(): int
    {
        $ids = [];
        $pages = 0;
        Page::query()->where('type', 'page')->whereHas('versions', fn ($q) => $q->where('is_draft', false))
            ->select(['id', 'status', 'archived_private', 'published_at', 'content'])
            ->chunkById(200, function ($chunk) use (&$ids, &$pages) {
                foreach ($chunk as $page) {
                    if (! $page->is_whiteboard) {
                        continue;
                    }
                    $query = PageVersion::query()->where('page_id', $page->id)->where('is_draft', false);
                    if (! $page->isPrivate()) {
                        if ($page->published_at === null) {
                            continue;
                        }
                        $query->where('created_at', '<', $page->published_at);
                    }
                    $found = $query->pluck('id')->all();
                    if ($found !== []) {
                        $pages++;
                        array_push($ids, ...$found);
                    }
                }
            });

        if ($ids === []) {
            $this->info('No whiteboard versions to mark.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn(count($ids)." version(s) of {$pages} whiteboard(s) were recorded before publishing. Dry run: nothing changed. Run again with --force to mark them private.");

            return self::SUCCESS;
        }

        foreach (array_chunk($ids, 1000) as $chunk) {
            PageVersion::query()->whereIn('id', $chunk)->update(['is_draft' => true]);
        }
        $this->info('Marked '.count($ids)." version(s) of {$pages} whiteboard(s) private.");

        return self::SUCCESS;
    }
}
