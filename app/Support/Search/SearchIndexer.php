<?php

namespace App\Support\Search;

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
 * after the issue was saved. flush() runs after the response / queued job / command (provider),
 * and before every search, so a search always sees the writes of its own request.
 */
class SearchIndexer
{
    /** Records read per sync batch. */
    public const CHUNK = 500;

    /** @var array<string, array<int, true>> */
    private array $pending = [];

    private bool $flushing = false;

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
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /** Write what is pending. Never throws: a broken index must not break the request. */
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
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    $this->sync($type, $chunk);
                }
            }
        } catch (\Throwable $e) {
            $this->pending = [];
            report($e);
        } finally {
            $this->flushing = false;
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
