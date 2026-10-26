<?php

namespace App\Support\Search;

/**
 * The search backend (BRAI-55). PostgresSearchEngine (full text + pg_trgm) is the default and
 * BasicSearchEngine (LIKE) serves SQLite installs; an OpenSearch / Meilisearch engine would
 * implement the same three calls and be bound in SearchServiceProvider. Everything above it —
 * sources, indexer, visibility (SearchViewer), presenting results — stays the same.
 */
interface SearchEngine
{
    /** @param  list<SearchDocument>  $documents  insert or replace, by type + source id */
    public function upsert(array $documents): void;

    /** @param  list<int>  $sourceIds */
    public function delete(string $type, array $sourceIds): void;

    /** Matching entries the viewer may see: one page, counts per type, highlighted. */
    public function search(SearchQuery $query, SearchViewer $viewer): SearchResults;
}
