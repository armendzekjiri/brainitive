<?php

namespace App\Support\Search\Engines;

use App\Support\Search\SearchHighlight;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResults;
use App\Support\Search\SearchViewer;
use Illuminate\Database\Query\Builder;

/**
 * The fallback engine for databases without PostgreSQL full text (SQLite self-hosted installs):
 * every word as a case-insensitive substring of title, keys or body; exact keys first, then
 * title matches, then the newest. Same visibility and filters as PostgresSearchEngine.
 */
class BasicSearchEngine extends TableSearchEngine
{
    public function search(SearchQuery $query, SearchViewer $viewer): SearchResults
    {
        if (! $query->isSearchable()) {
            return SearchResults::empty();
        }

        $matching = $this->visibleEntries($query, $viewer);
        foreach ($query->terms as $term) {
            $like = '%'.implode('%', $term['words']).'%';
            // NULL keywords / body must count as "doesn't contain" (NOT (false OR NULL) is NULL)
            $any = $query->titlesOnly
                ? fn (Builder $q) => $q->whereLike('e.title', $like)->orWhere(fn (Builder $k) => $k->whereNotNull('e.key')->whereLike('e.key', $like))
                : fn (Builder $q) => $q->whereLike('e.title', $like)
                    ->orWhere(fn (Builder $k) => $k->whereNotNull('e.keywords')->whereLike('e.keywords', $like))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('e.body')->whereLike('e.body', $like));
            $term['negated'] ? $matching->whereNot($any) : $matching->where($any);
        }

        $counts = $query->withCounts ? $this->countByType($matching) : [];
        $first = '%'.($query->positiveWords()[0] ?? '').'%';

        $rows = (clone $matching)
            ->when($query->types !== [], fn (Builder $q) => $q->whereIn('e.type', $query->types))
            ->select([...self::HIT_COLUMNS, 'e.title', 'e.body'])
            ->when($query->keys !== [], fn (Builder $q) => $q->orderByRaw('case when upper(e.key) in ('.implode(',', array_fill(0, count($query->keys), '?')).') then 0 else 1 end', $query->keys))
            ->orderByRaw('case when lower(e.title) like ? then 0 else 1 end', [mb_strtolower($first)])
            ->orderByDesc('e.source_updated_at')
            ->orderByDesc('e.id')
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        $words = $query->positiveWords();
        $hits = $rows->map(fn (object $row) => [
            'type' => $row->type,
            'source_id' => (int) $row->source_id,
            'project_id' => $row->project_id === null ? null : (int) $row->project_id,
            'workspace_id' => $row->workspace_id === null ? null : (int) $row->workspace_id,
            'container_id' => $row->container_id === null ? null : (int) $row->container_id,
            'subtype' => $row->subtype,
            'key' => $row->key,
            'status' => $row->status,
            'is_draft' => (bool) $row->is_draft,
            'updated_at' => $row->source_updated_at,
            'score' => 0.0,
            'title_html' => SearchHighlight::toHtml(SearchHighlight::mark((string) $row->title, $words)),
            'snippet_html' => SearchHighlight::toHtml(SearchHighlight::mark((string) $row->body, $words, 180)),
        ])->all();

        return new SearchResults($hits, $query->withCounts ? self::totalOf($counts, $query) : count($hits), $counts);
    }
}
