<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entries of bookmark collections: title, URL and notes. Visible like their collection
 * (Bookmark::visibleTo — own, public, shared with you, or a workspace admin).
 */
class BookmarkSource extends Source
{
    public function type(): string
    {
        return SearchTypes::BOOKMARK;
    }

    protected function idColumn(): string
    {
        return 'bookmark_items.id';
    }

    protected function base(): Builder
    {
        return DB::table('bookmark_items')
            ->join('bookmarks', 'bookmarks.id', '=', 'bookmark_items.bookmark_id')
            ->whereNull('bookmarks.deleted_at')
            ->select([
                'bookmark_items.id', 'bookmark_items.type', 'bookmark_items.title', 'bookmark_items.url', 'bookmark_items.content',
                'bookmark_items.created_by', 'bookmark_items.updated_at', 'bookmark_items.bookmark_id', 'bookmarks.workspace_id',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $url = is_string($row->url) && preg_match('~^https?://~i', $row->url) ? $row->url : null;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::BOOKMARK,
                sourceId: $id,
                title: (string) ($row->title ?: ($url ?? 'Bookmark')),
                workspaceId: self::int($row->workspace_id),
                containerId: (int) $row->bookmark_id,
                authorId: self::int($row->created_by),
                subtype: $row->type ? mb_substr((string) $row->type, 0, 24) : null,
                keywords: $url,
                body: SearchText::fromHtml($row->content),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
