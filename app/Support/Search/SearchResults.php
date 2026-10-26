<?php

namespace App\Support\Search;

/**
 * One page of hits (BRAI-55). `hits` are raw index rows: type, source_id, project_id,
 * workspace_id, container_id, subtype, key, status, source_updated_at, score, plus
 * `title_html` / `snippet_html` — escaped text with <mark> around matches, nothing else.
 */
final class SearchResults
{
    /**
     * @param  list<array<string, mixed>>  $hits
     * @param  array<string, int>  $counts  per type, with every filter but the type
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly array $counts,
        public readonly bool $fuzzy = false,
    ) {}

    public static function empty(): self
    {
        return new self([], 0, []);
    }
}
