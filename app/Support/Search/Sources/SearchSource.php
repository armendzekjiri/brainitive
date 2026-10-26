<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use Illuminate\Database\Query\Builder;

/**
 * One content type of the search index (BRAI-55): which records are indexable and what their
 * documents contain. documents() is the single source of truth — an id it doesn't return (gone,
 * trashed, inside a trashed board, a folder …) is removed from the index.
 */
interface SearchSource
{
    public function type(): string;

    /** The id column of the source query, e.g. `items.id`. */
    public function idColumn(): string;

    /** Query over the ids of indexable records (one `id` column), for a full reindex. */
    public function ids(): Builder;

    /**
     * Documents of the indexable records among $ids, in a fixed number of queries.
     *
     * @param  list<int>  $ids
     * @return array<int, SearchDocument> keyed by source id
     */
    public function documents(array $ids): array;
}
