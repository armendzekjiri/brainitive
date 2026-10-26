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
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\User;
use App\Observers\SearchIndexObserver;
use App\Support\Search\Engines\BasicSearchEngine;
use App\Support\Search\Engines\PostgresSearchEngine;
use App\Support\Search\SearchEngine;
use App\Support\Search\SearchIndexer;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * "Find anything" (BRAI-55): the search engine binding, the index hooks and when pending index
 * writes are flushed (after the response, after each queued job, otherwise after the commit).
 */
class SearchServiceProvider extends ServiceProvider
{
    /** Models whose changes reach the search index (see SearchIndexObserver). */
    public const OBSERVED = [
        Item::class, Comment::class, Page::class, Ticket::class, TicketReply::class, Plan::class, TaskListItem::class,
        Snippet::class, BookmarkItem::class, Capture::class, Attachment::class, TicketAttachment::class,
        Board::class, BoardColumn::class, Helpdesk::class, HelpdeskColumn::class, TaskList::class,
        SnippetCollection::class, Bookmark::class, Label::class, ItemField::class, ItemFieldOption::class,
        ItemFieldValue::class, ActivityLog::class, TicketContact::class, User::class,
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

        // requests and queued jobs write their index changes once, at the end (after the response)
        $indexer = fn () => $this->app->make(SearchIndexer::class);
        Event::listen(RouteMatched::class, fn () => $indexer()->defer());
        $this->app->terminating(function () {
            if ($this->app->resolved(SearchIndexer::class)) {
                $this->app->make(SearchIndexer::class)->release(all: true);
            }
        });
        Queue::before(fn () => $indexer()->defer());
        Queue::after(fn () => $indexer()->release());
        Queue::failing(fn () => $indexer()->release());
    }
}
