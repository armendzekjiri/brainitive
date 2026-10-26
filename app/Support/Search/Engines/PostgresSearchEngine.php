<?php

namespace App\Support\Search\Engines;

use App\Support\Search\PostgresSearchSetup;
use App\Support\Search\QueryParser;
use App\Support\Search\SearchHighlight;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResults;
use App\Support\Search\SearchViewer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search in PostgreSQL (BRAI-55).
 *
 * - Match: `tsv @@ to_tsquery('simple', search_unaccent(…))` on the GIN index; words AND-ed, the
 *   last one as a prefix, phrases with <->, exclusions with !.
 * - Typo tolerance: when full text finds fewer than FUZZY_BELOW hits, titles with a word similar to
 *   each typed word (pg_trgm word_similarity, trigram GIN index) count too — `fuzzy` tells the UI.
 * - Rank: ts_rank_cd (title A > keys / labels B > body C) + an exact key match ("BRAI-12") far
 *   above everything + a little recency + the project being viewed.
 * - Counts per type and the page come from the same conditions, visibility included.
 * - Highlights: ts_headline with private-use markers, turned into escaped HTML by SearchHighlight.
 */
class PostgresSearchEngine extends TableSearchEngine
{
    public const FUZZY_BELOW = 3;

    /** pg_trgm word_similarity a title needs in the typo fallback. */
    public const FUZZY_THRESHOLD = 0.35;

    private const TS_QUERY = "to_tsquery('simple', search_unaccent(?))";

    private const TITLE_TRGM = 'search_unaccent(lower(e.title))';

    public function search(SearchQuery $query, SearchViewer $viewer): SearchResults
    {
        if (! $query->isSearchable()) {
            return SearchResults::empty();
        }

        $tsQuery = QueryParser::toTsQuery($query->terms);
        $fuzzyWords = self::fuzzyWords($query);

        $counts = $query->withCounts ? $this->countByType($this->matching($query, $viewer, $tsQuery, null)) : [];
        $exactTotal = self::totalOf($counts, $query);
        // few exact matches: titles that look like the words count too (typos)
        $fuzzy = $fuzzyWords !== null && $query->withCounts && $exactTotal < self::FUZZY_BELOW && PostgresSearchSetup::capabilities()['trigram'];

        $run = function () use ($query, $viewer, $tsQuery, $fuzzy, $fuzzyWords, &$counts) {
            if ($fuzzy) {
                // the threshold of the indexed %> operator, for this transaction only
                DB::select("select set_config('pg_trgm.word_similarity_threshold', ?, true)", [(string) self::FUZZY_THRESHOLD]);
                $counts = $this->countByType($this->matching($query, $viewer, $tsQuery, $fuzzyWords));
            }

            return $this->page($query, $viewer, $tsQuery, $fuzzy ? $fuzzyWords : null);
        };
        $rows = $fuzzy ? DB::transaction($run) : $run();

        $hits = array_map(fn (object $row) => [
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
            'score' => round((float) $row->score, 4),
            'title_html' => SearchHighlight::toHtml($row->title_marked),
            'snippet_html' => SearchHighlight::toHtml($row->body_marked),
        ], $rows);

        $total = $query->withCounts ? self::totalOf($counts, $query) : count($hits);

        return new SearchResults($hits, $total, $counts, $fuzzy && $total > $exactTotal);
    }

    /** Visible entries matching the words (and, in the typo fallback, similar titles). */
    private function matching(SearchQuery $query, SearchViewer $viewer, string $tsQuery, ?array $fuzzyWords): Builder
    {
        $excluded = $query->excluded() === [] ? '' : QueryParser::toAnyTsQuery($query->excluded());

        return $this->visibleEntries($query, $viewer)->where(function (Builder $match) use ($query, $tsQuery, $fuzzyWords, $excluded) {
            $query->titlesOnly
                // the title's words only (weight A); the GIN index still narrows first
                ? $match->whereRaw('(e.tsv @@ '.self::TS_QUERY.' and ts_filter(e.tsv, \'{a}\') @@ '.self::TS_QUERY.')', [$tsQuery, $tsQuery])
                : $match->whereRaw('e.tsv @@ '.self::TS_QUERY, [$tsQuery]);
            if ($query->keys !== []) {
                // an exact key always finds its issue / ticket (index on upper(key))
                $match->orWhereRaw('upper(e.key) = any(?::text[])', [self::pgArray($query->keys)]);
            }
            if ($fuzzyWords !== null) {
                // a similar title — still without the excluded words
                $match->orWhere(function (Builder $similar) use ($fuzzyWords, $excluded) {
                    // every word looks like a word of the title (each on the trigram index)
                    foreach ($fuzzyWords as $word) {
                        $similar->whereRaw(self::TITLE_TRGM.' %> search_unaccent(?)', [$word]);
                    }
                    if ($excluded !== '') {
                        $similar->whereRaw('not (e.tsv @@ to_tsquery(\'simple\', search_unaccent(?)))', [$excluded]);
                    }
                });
            }
        });
    }

    /** @return list<object> */
    private function page(SearchQuery $query, SearchViewer $viewer, string $tsQuery, ?array $fuzzyWords): array
    {
        $score = 'ts_rank_cd(e.tsv, '.self::TS_QUERY.', 32)'
            // "BRAI-12" puts that issue first
            .' + (case when e.key is not null and upper(e.key) = any(?::text[]) then 10 else 0 end)'
            // a little recency: up to 0.15, halved after a month
            .' + 0.15 / (1 + extract(epoch from (now() - coalesce(e.source_updated_at, e.indexed_at, now()))) / 2592000.0)'
            .' + (case when e.project_id = ? then 0.05 else 0 end)';
        $bindings = [$tsQuery, self::pgArray($query->keys), $query->contextProjectId ?? 0];
        if ($fuzzyWords !== null) {
            foreach ($fuzzyWords as $word) {
                $score .= ' + '.round(0.5 / count($fuzzyWords), 4).' * word_similarity(search_unaccent(?), '.self::TITLE_TRGM.')';
                $bindings[] = $word;
            }
        }

        $page = $this->matching($query, $viewer, $tsQuery, $fuzzyWords)
            ->when($query->types !== [], fn (Builder $q) => $q->whereIn('e.type', $query->types))
            ->select([...self::HIT_COLUMNS, 'e.title', 'e.body'])
            ->selectRaw("({$score}) as score", $bindings)
            ->orderByDesc('score')
            ->orderByDesc('e.source_updated_at')
            ->orderByDesc('e.id')
            ->offset($query->offset())
            ->limit($query->perPage);

        // highlight only the rows of this page; every word that matched is marked, as typed
        // (accents kept: marks "Größe" in the text) or unaccented
        $highlight = "(to_tsquery('simple', ?) || to_tsquery('simple', search_unaccent(?)))";
        $words = QueryParser::toAnyTsQuery($query->included());
        $markers = 'StartSel="'.SearchHighlight::START.'", StopSel="'.SearchHighlight::STOP.'"';

        return DB::query()
            ->fromSub($page, 'p')
            ->select(['p.id', 'p.type', 'p.source_id', 'p.project_id', 'p.workspace_id', 'p.container_id', 'p.subtype', 'p.key', 'p.status', 'p.is_draft', 'p.source_updated_at', 'p.score'])
            ->selectRaw("ts_headline('simple', p.title, {$highlight}, ?) as title_marked", [$words, $words, $markers.', HighlightAll=true'])
            ->selectRaw("ts_headline('simple', coalesce(p.body, ''), {$highlight}, ?) as body_marked", [$words, $words, $markers.', MaxFragments=2, MaxWords=22, MinWords=8, ShortWord=2, FragmentDelimiter=" … "'])
            ->orderByDesc('p.score')
            ->orderByDesc('p.source_updated_at')
            ->orderByDesc('p.id')
            ->get()
            ->all();
    }

    /**
     * The words a similar title is compared with: the included ones of three letters or more
     * (shorter words have too few trigrams to compare). A "quoted phrase" asks for exactly those
     * words, so it gets no typo fallback.
     *
     * @return list<string>|null
     */
    private static function fuzzyWords(SearchQuery $query): ?array
    {
        foreach ($query->included() as $term) {
            if ($term['quoted']) {
                return null;
            }
        }
        $words = array_values(array_unique(array_filter($query->positiveWords(), fn (string $w) => mb_strlen($w) >= 3)));

        return $words === [] ? null : array_slice($words, 0, 6);
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }
}
