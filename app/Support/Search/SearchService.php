<?php

namespace App\Support\Search;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Find anything" (BRAI-55) for one person: request input → SearchQuery, pending index writes
 * flushed, the engine searched with the person's SearchViewer, hits turned into results with
 * links (SearchPresenter). Used by the search page, the command palette and the MCP `search` tool.
 */
class SearchService
{
    public const UPDATED_PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365];

    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchIndexer $indexer,
    ) {}

    /**
     * @return array{results: list<array<string, mixed>>, total: int, counts: array<string, int>, fuzzy: bool, page: int, per_page: int, has_more: bool}
     */
    public function search(User $user, SearchQuery $query): array
    {
        $this->indexer->flush();

        $results = $query->isSearchable() ? $this->engine->search($query, SearchViewer::for($user)) : SearchResults::empty();
        $presented = SearchPresenter::present($results->hits);

        return [
            'results' => $presented,
            'total' => $results->total,
            'counts' => self::groupCounts($results->counts),
            'fuzzy' => $results->fuzzy,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $query->withCounts ? $query->offset() + count($results->hits) < $results->total : count($results->hits) === $query->perPage,
        ];
    }

    /**
     * The query from request-like input: q, type (a SearchTypes::GROUPS key), project (ids),
     * status (open | done), assignee (me | user id), updated (7d | 30d | 90d | 365d), from / to
     * (Y-m-d), page. Unknown values are ignored, never an error.
     *
     * @param  array<string, mixed>  $input
     */
    public static function queryFrom(array $input, User $user, int $perPage = 20, bool $withCounts = true): SearchQuery
    {
        $parsed = QueryParser::parse(is_string($input['q'] ?? null) ? $input['q'] : '');

        $group = is_string($input['type'] ?? null) && isset(SearchTypes::GROUPS[$input['type']]) ? $input['type'] : null;
        $projects = array_values(array_unique(array_filter(array_map('intval', (array) ($input['project'] ?? [])), fn (int $id) => $id > 0)));
        $status = in_array($input['status'] ?? null, SearchQuery::STATUSES, true) ? $input['status'] : null;
        $assignee = match (true) {
            ($input['assignee'] ?? null) === 'me' => (int) $user->id,
            is_numeric($input['assignee'] ?? null) && (int) $input['assignee'] > 0 => (int) $input['assignee'],
            default => null,
        };

        $from = self::date($input['from'] ?? null);
        $to = self::date($input['to'] ?? null)?->endOfDay();
        if ($from === null && isset(self::UPDATED_PRESETS[$input['updated'] ?? ''])) {
            $from = CarbonImmutable::now()->subDays(self::UPDATED_PRESETS[$input['updated']])->startOfDay();
        }

        return new SearchQuery(
            text: $parsed['text'],
            terms: $parsed['terms'],
            keys: $parsed['keys'],
            types: $group ? SearchTypes::typesOf([$group]) : [],
            projectIds: array_slice($projects, 0, 50),
            status: $status,
            assigneeId: $assignee,
            updatedFrom: $from,
            updatedTo: $to,
            page: max(1, min(100, (int) ($input['page'] ?? 1))),
            perPage: max(1, min(SearchQuery::MAX_PER_PAGE, $perPage)),
            contextProjectId: is_numeric($input['context_project'] ?? null) ? (int) $input['context_project'] : null,
            withCounts: $withCounts,
        );
    }

    /**
     * Counts per filter group (SearchTypes::GROUPS), every group present.
     *
     * @param  array<string, int>  $byType
     * @return array<string, int>
     */
    public static function groupCounts(array $byType): array
    {
        $counts = [];
        foreach (SearchTypes::GROUPS as $group => [, $types]) {
            $counts[$group] = array_sum(array_intersect_key($byType, array_flip($types)));
        }

        return $counts;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
