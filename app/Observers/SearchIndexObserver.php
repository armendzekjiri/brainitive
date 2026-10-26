<?php

namespace App\Observers;

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
use App\Support\Search\SearchIndexer;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Search index hooks (BRAI-55), registered for every indexed model and the containers whose state
 * decides visibility (SearchServiceProvider). They only mark records as changed; SearchIndexer
 * reads them again after the request. `deleting` runs before the row (and what the database
 * cascades with it) is gone, so those ids are still known.
 */
class SearchIndexObserver
{
    /** Indexed models whose own row is the document. */
    private const SELF = [
        Item::class => SearchTypes::ITEM,
        Comment::class => SearchTypes::COMMENT,
        Page::class => SearchTypes::PAGE,
        Ticket::class => SearchTypes::TICKET,
        Plan::class => SearchTypes::PLAN,
        TaskListItem::class => SearchTypes::TASK,
        Snippet::class => SearchTypes::SNIPPET,
        BookmarkItem::class => SearchTypes::BOOKMARK,
        Capture::class => SearchTypes::CAPTURE,
        Attachment::class => SearchTypes::ATTACHMENT,
        TicketAttachment::class => SearchTypes::TICKET_ATTACHMENT,
    ];

    public function __construct(private readonly SearchIndexer $indexer) {}

    public function saved(Model $model): void
    {
        $this->changed($model, false);
    }

    public function deleting(Model $model): void
    {
        $this->changed($model, true);
    }

    public function deleted(Model $model): void
    {
        $this->changed($model, false);
    }

    public function restored(Model $model): void
    {
        $this->changed($model, true);
    }

    public function created(Model $model): void
    {
        // labels added or removed anywhere (board, bulk edit, automations) are logged like this
        if ($model instanceof ActivityLog && $model->action === 'labels_changed' && $model->loggable_type === (new Item)->getMorphClass()) {
            $this->indexer->touch(SearchTypes::ITEM, (int) $model->loggable_id);
        }
    }

    /** @param  bool  $everything  also what depends on it (trash, restore, hard delete) */
    private function changed(Model $model, bool $everything): void
    {
        $id = (int) $model->getKey();
        $moved = fn (string ...$columns) => $everything || $model->wasChanged($columns);

        match (true) {
            $model instanceof Item => $moved('board_id', 'project_id', 'deleted_at')
                ? $this->indexer->touchItems([$id, ...$model->descendantIds()])
                : $this->indexer->touch(SearchTypes::ITEM, $id),
            $model instanceof Ticket => $moved('helpdesk_id', 'deleted_at')
                ? $this->indexer->touchTickets([$id])
                : $this->indexer->touch(SearchTypes::TICKET, $id),
            $model instanceof Page => $this->page($model, $moved('project_id')),
            isset(self::SELF[$model::class]) => $this->indexer->touch(self::SELF[$model::class], $id),
            $model instanceof TicketReply => $this->indexer->touch(SearchTypes::TICKET, (int) $model->ticket_id),
            $model instanceof ItemFieldValue => $this->indexer->touch(SearchTypes::ITEM, (int) $model->item_id),
            $model instanceof Board => $moved('project_id', 'deleted_at') ? $this->indexer->touchBoard($id) : null,
            $model instanceof Helpdesk => $moved('project_id', 'deleted_at') ? $this->indexer->touchHelpdesk($id) : null,
            $model instanceof TaskList => $moved('project_id', 'deleted_at') ? $this->indexer->touchWhere(SearchTypes::TASK, 'task_list_items', 'task_list_id', $id) : null,
            $model instanceof SnippetCollection => $moved('workspace_id') ? $this->indexer->touchWhere(SearchTypes::SNIPPET, 'snippets', 'collection_id', $id) : null,
            $model instanceof Bookmark => $moved('workspace_id', 'deleted_at') ? $this->indexer->touchWhere(SearchTypes::BOOKMARK, 'bookmark_items', 'bookmark_id', $id) : null,
            $model instanceof BoardColumn => $moved('is_done_column') ? $this->indexer->touchWhere(SearchTypes::ITEM, 'items', 'column_id', $id) : null,
            $model instanceof HelpdeskColumn => $moved('is_done_column') ? $this->indexer->touchWhere(SearchTypes::TICKET, 'tickets', 'column_id', $id) : null,
            $model instanceof Label => $moved('name') ? $this->indexer->touch(SearchTypes::ITEM, DB::table('item_label')->where('label_id', $id)->pluck('item_id')) : null,
            $model instanceof ItemField => $moved('archived_at') ? $this->indexer->touch(SearchTypes::ITEM, DB::table('item_field_values')->where('item_field_id', $id)->pluck('item_id')) : null,
            $model instanceof ItemFieldOption => $moved('label') ? $this->indexer->touch(SearchTypes::ITEM, DB::table('item_field_values')->where('option_id', $id)->pluck('item_id')) : null,
            default => null,
        };
    }

    /** A doc, plus its sub-pages when the database deletes them with it or it changes project. */
    private function page(Page $page, bool $withChildren): void
    {
        $ids = [(int) $page->id];
        if ($withChildren) {
            $level = $ids;
            while ($level !== []) {
                $level = DB::table('pages')->whereIn('parent_id', $level)->whereNotIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $ids = array_merge($ids, $level);
            }
        }
        $this->indexer->touch(SearchTypes::PAGE, $ids);
    }
}
