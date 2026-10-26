<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldOption;
use App\Models\ItemFieldValue;
use App\Models\Label;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Observers\SearchIndexObserver;
use App\Support\Search\Engines\BasicSearchEngine;
use App\Support\Search\Engines\PostgresSearchEngine;
use App\Support\Search\SearchEngine;
use App\Support\Search\SearchIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * "Find anything" (BRAI-55): the search engine binding, the index hooks and when pending index
 * writes are flushed (after the response, after each queued job, at the end of a command).
 */
class SearchServiceProvider extends ServiceProvider
{
    /** Models whose changes reach the search index (see SearchIndexObserver). */
    public const OBSERVED = [
        Item::class, Comment::class, Page::class, Ticket::class, TicketReply::class, Plan::class, TaskListItem::class,
        Snippet::class, BookmarkItem::class, Capture::class, Attachment::class, TicketAttachment::class,
        Board::class, BoardColumn::class, Helpdesk::class, HelpdeskColumn::class, TaskList::class,
        SnippetCollection::class, Bookmark::class, Label::class, ItemField::class, ItemFieldOption::class,
        ItemFieldValue::class, ActivityLog::class,
    ];

    public function register(): void
    {
        // An OpenSearch / Meilisearch engine would be bound here instead.
        $this->app->singleton(SearchEngine::class, fn () => DB::connection()->getDriverName() === 'pgsql'
            ? new PostgresSearchEngine
            : new BasicSearchEngine);
        $this->app->singleton(SearchIndexer::class);
    }

    public function boot(): void
    {
        foreach (self::OBSERVED as $model) {
            $model::observe(SearchIndexObserver::class);
        }

        $flush = function () {
            if ($this->app->resolved(SearchIndexer::class)) {
                $this->app->make(SearchIndexer::class)->flush();
            }
        };
        $this->app->terminating($flush);
        Queue::after($flush);
    }
}
