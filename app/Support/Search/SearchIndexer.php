<?php

namespace App\Support\Search;

use App\Jobs\SyncSearchEntries;
use App\Support\Search\Sources\AttachmentSource;
use App\Support\Search\Sources\BookmarkSource;
use App\Support\Search\Sources\CaptureSource;
use App\Support\Search\Sources\CommentSource;
use App\Support\Search\Sources\ItemSource;
use App\Support\Search\Sources\PageSource;
use App\Support\Search\Sources\PlanSource;
use App\Support\Search\Sources\SearchSource;
use App\Support\Search\Sources\SnippetSource;
use App\Support\Search\Sources\TaskSource;
use App\Support\Search\Sources\TicketAttachmentSource;
use App\Support\Search\Sources\TicketSource;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the search index in step with the database (BRAI-55).
 *
 * Model hooks only *touch* keys (type + id); flush() later reads those records again and writes
 * what is true then — a document, or a removal when the record is gone, trashed or hidden. So it
 * doesn't matter which write path changed a record, how often, or whether labels were synced
 * after the issue was saved.
 *
 * When: during an HTTP request or a queued job (defer() … release(), SearchServiceProvider) the
 * keys collect and are written once at the end — after the response is sent. Anywhere else
 * (commands, seeders, tests) they are written right after the surrounding transaction commits.
 * Every search flushes first, so it always sees the writes of its own request.
 */
class SearchIndexer
{
    /** Records read per sync batch. */
    public const CHUNK = 500;

    /** More keys of one type than this are synced on the queue, not at the end of the request. */
    public const INLINE_LIMIT = 2000;

    /** @var array<string, array<int, true>> */
    private array $pending = [];

    private bool $flushing = false;

    /** Open requests / jobs that write at their end. */
    private int $deferring = 0;

    /** Inside hold(): touches wait for a later flush (a row about to be deleted). */
    private bool $holding = false;

    /** @var array<string, SearchSource>|null */
    private ?array $sources = null;

    public function __construct(private readonly SearchEngine $engine) {}

    /** @return array<string, SearchSource> by type */
    public function sources(): array
    {
        return $this->sources ??= collect([
            new ItemSource, new CommentSource, new PageSource, new TicketSource, new PlanSource, new TaskSource,
            new SnippetSource, new BookmarkSource, new CaptureSource, new AttachmentSource, new TicketAttachmentSource,
        ])->keyBy(fn (SearchSource $source) => $source->type())->all();
    }

    public function source(string $type): SearchSource
    {
        return $this->sources()[$type] ?? throw new \InvalidArgumentException("Unknown search type {$type}");
    }

    /** @param  int|iterable<int|string>  $ids */
    public function touch(string $type, int|iterable $ids): void
    {
        foreach (is_int($ids) ? [$ids] : $ids as $id) {
            $this->pending[$type][(int) $id] = true;
        }
        $this->schedule();
    }

    /** Write pending keys after the current transaction commits — unless a request / job is running. */
    public function schedule(): void
    {
        if ($this->deferring > 0 || $this->holding || $this->pending === []) {
            return;
        }
        // runs at once without a transaction; a rolled back transaction drops the callback, and
        // the keys wait for the next flush
        DB::afterCommit(fn () => $this->flush());
    }

    /** Touches inside $callback are written later (on `deleting`: the rows still exist now). */
    public function hold(\Closure $callback): void
    {
        $previous = $this->holding;
        $this->holding = true;
        try {
            $callback();
        } finally {
            $this->holding = $previous;
        }
    }

    /** A request or job starts: collect, write at its end. */
    public function defer(): void
    {
        $this->deferring++;
    }

    /** A request or job ended; the last one out writes. */
    public function release(bool $all = false): void
    {
        $this->deferring = $all ? 0 : max(0, $this->deferring - 1);
        if ($this->deferring === 0) {
            $this->flush();
        }
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Write what is pending. Never throws: a broken index must not break the request. A batch
     * that fails is retried on the queue; very large sets (a trashed board with thousands of
     * issues) go to the queue right away instead of holding up the request / job.
     */
    public function flush(): void
    {
        if ($this->flushing || $this->pending === []) {
            return;
        }
        $this->flushing = true;
        try {
            while ($this->pending !== []) {
                $type = array_key_first($this->pending);
                $ids = array_keys($this->pending[$type]);
                unset($this->pending[$type]);
                $inline = count($ids) <= self::INLINE_LIMIT;
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    if (! $inline) {
                        $this->later($type, $chunk);

                        continue;
                    }
                    try {
                        $this->sync($type, $chunk);
                    } catch (\Throwable $e) {
                        report($e);
                        $this->later($type, $chunk);
                    }
                }
            }
        } finally {
            $this->flushing = false;
        }
    }

    /** @param  list<int>  $ids */
    private function later(string $type, array $ids): void
    {
        try {
            SyncSearchEntries::dispatch($type, $ids);
        } catch (\Throwable $e) {
            report($e); // the next full rebuild repairs it
        }
    }

    /**
     * Make the index match the database for these records now.
     *
     * @param  list<int>  $ids
     * @return int documents written
     */
    public function sync(string $type, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $documents = $this->source($type)->documents($ids);
        $gone = array_values(array_diff(array_map('intval', $ids), array_keys($documents)));

        if ($gone !== []) {
            $this->engine->delete($type, $gone);
        }
        if ($documents !== []) {
            $this->engine->upsert(array_values($documents));
        }

        return count($documents);
    }

    // ── What else a change touches ────────────────────────────────────────────

    /**
     * Issues with their comments and files (moves, trash / restore, hard deletes that cascade).
     *
     * @param  list<int>  $itemIds
     */
    public function touchItems(array $itemIds): void
    {
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $this->touch(SearchTypes::ITEM, $chunk);
            $this->touch(SearchTypes::COMMENT, DB::table('comments')->whereIn('item_id', $chunk)->pluck('id'));
            $this->touch(SearchTypes::ATTACHMENT, DB::table('attachments')->whereIn('item_id', $chunk)->pluck('id'));
        }
    }

    /** @param  list<int>  $ticketIds */
    public function touchTickets(array $ticketIds): void
    {
        foreach (array_chunk($ticketIds, 1000) as $chunk) {
            $this->touch(SearchTypes::TICKET, $chunk);
            $this->touch(SearchTypes::TICKET_ATTACHMENT, DB::table('ticket_attachments')->whereIn('ticket_id', $chunk)->pluck('id'));
        }
    }

    public function touchBoard(int $boardId): void
    {
        $this->touchItems(DB::table('items')->where('board_id', $boardId)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function touchHelpdesk(int $helpdeskId): void
    {
        $this->touchTickets(DB::table('tickets')->where('helpdesk_id', $helpdeskId)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function touchWhere(string $type, string $table, string $column, int $value): void
    {
        $this->touch($type, DB::table($table)->where($column, $value)->pluck('id'));
    }
}
