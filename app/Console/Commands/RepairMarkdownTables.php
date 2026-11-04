<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Page;
use App\Support\Collab\ItemCollab;
use App\Support\Live\Live;
use App\Support\Pages\MarkdownTableRepair;
use App\Support\Pages\PageWrites;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * v1.51.0: Markdown tables an AI wrote through the MCP (create_page / update_page, issue descriptions) or the
 * App API's `markdown` reached the editor as text (`| a | b |`) — the converter had no tables. This rebuilds them
 * as real tables in docs (published content and draft) and, with --items, issue descriptions
 * (MarkdownTableRepair; nothing else in the document changes).
 *
 * Dry run by default (lists what would change); `--force` writes. Idempotent. Docs go through
 * PageWrites::repairHtml (locked row, new edit_revision, open editors reload; no version, no notifications);
 * issues are saved quietly (no history entry, webhook or watcher mail) and their live view is reset like any
 * outside change of the description.
 */
class RepairMarkdownTables extends Command
{
    protected $signature = 'pages:repair-markdown-tables
        {--force : write the repaired HTML (without it: only report)}
        {--items : also repair issue (board item) descriptions}';

    protected $description = 'Report (or with --force rebuild) Markdown tables stored as "| a | b |" text in docs and issue descriptions';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $totals = ['pages' => 0, 'items' => 0, 'tables' => 0, 'skipped' => 0];

        $this->candidates(Page::query()->where('type', 'page'), ['content', 'draft_content'])
            ->select(['id', 'uuid', 'name', 'content', 'draft_content'])
            ->chunkById(100, function ($pages) use ($force, &$totals) {
                foreach ($pages as $page) {
                    $found = $this->scan([$page->content['html'] ?? null, $page->draft_content['html'] ?? null]);
                    if ($found === null) {
                        continue;
                    }
                    $totals['tables'] += $found['tables'];
                    $totals['skipped'] += $found['skipped'];
                    if ($found['tables'] === 0) {
                        $this->line("  skipped page {$page->id} {$page->uuid} \"{$page->name}\": {$found['skipped']} table(s) not safe to rebuild");

                        continue;
                    }
                    $totals['pages']++;
                    $this->line("  page {$page->id} {$page->uuid} \"{$page->name}\": {$found['tables']} table(s)");
                    if ($force) {
                        PageWrites::repairHtml($page, fn (string $html) => MarkdownTableRepair::repair($html)['html']);
                    }
                }
            });

        if ($this->option('items')) {
            $this->candidates(Item::query(), ['description'])
                ->select(['id', 'uuid', 'key', 'title', 'description'])
                ->chunkById(100, function ($items) use ($force, &$totals) {
                    foreach ($items as $item) {
                        $found = $this->scan([$item->description]);
                        if ($found === null) {
                            continue;
                        }
                        $totals['tables'] += $found['tables'];
                        $totals['skipped'] += $found['skipped'];
                        if ($found['tables'] === 0) {
                            $this->line("  skipped issue {$item->id} {$item->key} \"{$item->title}\": {$found['skipped']} table(s) not safe to rebuild");

                            continue;
                        }
                        $totals['items']++;
                        $this->line("  issue {$item->id} {$item->key} \"{$item->title}\": {$found['tables']} table(s)");
                        if ($force) {
                            $this->repairItem($item);
                        }
                    }
                });
        }

        $summary = "{$totals['pages']} doc(s)".($this->option('items') ? " and {$totals['items']} issue(s)" : '')
            ." with {$totals['tables']} Markdown table(s) stored as text"
            .($totals['skipped'] > 0 ? ", {$totals['skipped']} table-like text(s) left alone (fix by hand)" : '');

        if (! $force) {
            $this->warn("{$summary}. Dry run: nothing changed. Run again with --force to rebuild them.");

            return self::SUCCESS;
        }

        $this->info("Repaired {$summary}.");
        Log::info('pages:repair-markdown-tables', $totals);

        return self::SUCCESS;
    }

    /**
     * Rows whose JSON / HTML holds something like a delimiter row (cheap filter; MarkdownTableRepair decides).
     *
     * @param  list<string>  $columns
     */
    private function candidates(Builder $query, array $columns): Builder
    {
        return $query->where(function (Builder $any) use ($columns) {
            foreach ($columns as $column) {
                foreach (['%-|%', '%- |%', '%:|%', '%: |%'] as $pattern) {
                    $any->orWhereRaw("CAST({$column} AS TEXT) LIKE ?", [$pattern]);
                }
            }
        });
    }

    /**
     * @param  list<string|null>  $htmls
     * @return array{tables: int, skipped: int}|null null when none holds table-like text
     */
    private function scan(array $htmls): ?array
    {
        $tables = 0;
        $skipped = 0;
        foreach ($htmls as $html) {
            if (! is_string($html) || ! MarkdownTableRepair::mayContainTable($html)) {
                continue;
            }
            $result = MarkdownTableRepair::repair($html);
            $tables += $result['tables'];
            $skipped += $result['skipped'];
        }

        return $tables + $skipped === 0 ? null : ['tables' => $tables, 'skipped' => $skipped];
    }

    private function repairItem(Item $item): void
    {
        $fresh = Item::query()->find($item->id);
        if (! $fresh instanceof Item || ! is_string($fresh->description)) {
            return;
        }
        $html = MarkdownTableRepair::repair($fresh->description)['html'];
        if ($html === $fresh->description) {
            return;
        }

        $fresh->description = $html;
        $fresh->timestamps = false;
        $fresh->saveQuietly();
        // what Item::updated does for an outside change of the description (BRAI-151), without history / webhooks
        ItemCollab::reset($fresh);
        Live::publish("item.{$fresh->id}", 'item.updated', ['id' => $fresh->id, 'fields' => ['description']]);
    }
}
