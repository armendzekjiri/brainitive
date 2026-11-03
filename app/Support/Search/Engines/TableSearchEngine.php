<?php

namespace App\Support\Search\Engines;

use App\Support\Search\SearchEngine;
use App\Support\Search\SearchHighlight;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResults;
use App\Support\Search\SearchTypes;
use App\Support\Search\SearchViewer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Engines that keep the index in the `search_entries` table: writing the index, and the
 * visibility + filter conditions every query starts from.
 */
abstract class TableSearchEngine implements SearchEngine
{
    /** Rows per upsert statement (bodies can be up to 60 000 characters). */
    private const UPSERT_CHUNK = 50;

    private const UPDATE_COLUMNS = [
        'project_id', 'workspace_id', 'container_id', 'owner_id', 'author_id', 'is_draft', 'subtype', 'key', 'title',
        'keywords', 'body', 'status', 'assignee_id', 'item_type_id', 'source_updated_at', 'indexed_at',
    ];

    public function upsert(array $documents): void
    {
        $now = now();
        foreach (array_chunk($documents, self::UPSERT_CHUNK) as $chunk) {
            DB::table('search_entries')->upsert(
                array_map(fn ($document) => $document->toRow($now), $chunk),
                ['type', 'source_id'],
                self::UPDATE_COLUMNS,
            );
        }
    }

    public function delete(string $type, array $sourceIds): void
    {
        foreach (array_chunk($sourceIds, 1000) as $chunk) {
            DB::table('search_entries')->where('type', $type)->whereIn('source_id', $chunk)->delete();
        }
    }

    /**
     * Entries the viewer may see, with every filter but the type (`e` is search_entries).
     * See SearchViewer for the rules; keep them in step with the view policies.
     */
    protected function visibleEntries(SearchQuery $query, SearchViewer $viewer): Builder
    {
        return DB::table('search_entries as e')
            ->where(fn (Builder $visible) => $visible
                ->where(fn (Builder $project) => $project
                    ->whereIn('e.project_id', $viewer->projectIds)
                    ->where(fn (Builder $draft) => $draft
                        ->where('e.is_draft', false)
                        ->orWhere('e.author_id', $viewer->userId)
                        ->orWhereIn('e.project_id', $viewer->adminProjectIds)))
                ->orWhere(fn (Builder $snippet) => $snippet
                    ->where('e.type', SearchTypes::SNIPPET)
                    ->whereNull('e.project_id')
                    ->whereIn('e.workspace_id', $viewer->workspaceIds))
                ->orWhere(fn (Builder $bookmark) => $bookmark
                    ->where('e.type', SearchTypes::BOOKMARK)
                    ->whereNull('e.project_id')
                    ->whereIn('e.workspace_id', $viewer->workspaceIds)
                    ->whereIn('e.container_id', $viewer->bookmarkIds))
                ->orWhere(fn (Builder $capture) => $capture
                    ->where('e.type', SearchTypes::CAPTURE)
                    ->whereNull('e.project_id')
                    ->where('e.owner_id', $viewer->userId)))
            ->when($query->projectIds !== [], fn (Builder $q) => $q->whereIn('e.project_id', $query->projectIds))
            // project content by the project's current workspace (an entry's own workspace_id isn't
            // rewritten when a project moves); workspace-level content (snippets, bookmarks) by its own
            ->when($query->workspaceIds !== [], fn (Builder $q) => $q->where(fn (Builder $in) => $in
                ->whereIn('e.project_id', DB::table('projects')->whereIn('workspace_id', $query->workspaceIds)->select('id'))
                ->orWhere(fn (Builder $own) => $own->whereNull('e.project_id')->whereIn('e.workspace_id', $query->workspaceIds))))
            ->when($query->status !== null, fn (Builder $q) => $q->where('e.status', $query->status))
            ->when($query->assigneeId !== null, fn (Builder $q) => $q->where('e.assignee_id', $query->assigneeId))
            ->when($query->updatedFrom !== null, fn (Builder $q) => $q->where('e.source_updated_at', '>=', $query->updatedFrom))
            ->when($query->updatedTo !== null, fn (Builder $q) => $q->where('e.source_updated_at', '<=', $query->updatedTo));
    }

    /**
     * The "recent" list (App API `GET /search` without words): the newest content the viewer may see,
     * from the same visible entries as a search (so the same visibility). Comments, files and captures
     * are left out; no counts, one page.
     */
    public function recent(SearchQuery $query, SearchViewer $viewer): SearchResults
    {
        $types = $query->types !== [] ? $query->types : self::RECENT_TYPES;
        $rows = $this->visibleEntries($query, $viewer)
            ->whereIn('e.type', $types)
            ->select([...self::HIT_COLUMNS, 'e.title'])
            ->orderByDesc('e.source_updated_at')
            ->orderByDesc('e.id')
            ->limit($query->perPage)
            ->get();

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
            'title_html' => SearchHighlight::toHtml((string) $row->title),
            'snippet_html' => '',
        ])->all();

        return new SearchResults($hits, count($hits), []);
    }

    /** What "recent" lists: things people open, not comments, files or captures. */
    public const RECENT_TYPES = [SearchTypes::ITEM, SearchTypes::PAGE, SearchTypes::TICKET, SearchTypes::PLAN, SearchTypes::TASK, SearchTypes::SNIPPET, SearchTypes::BOOKMARK];

    /**
     * Matches per type.
     *
     * @return array<string, int>
     */
    protected function countByType(Builder $matching): array
    {
        return (clone $matching)
            ->select('e.type')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('e.type')
            ->pluck('aggregate', 'type')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @param  array<string, int>  $counts */
    protected static function totalOf(array $counts, SearchQuery $query): int
    {
        return array_sum($query->types === [] ? $counts : array_intersect_key($counts, array_flip($query->types)));
    }

    protected const HIT_COLUMNS = [
        'e.id', 'e.type', 'e.source_id', 'e.project_id', 'e.workspace_id', 'e.container_id', 'e.subtype', 'e.key',
        'e.status', 'e.is_draft', 'e.source_updated_at',
    ];
}
