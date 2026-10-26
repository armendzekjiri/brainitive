<?php

namespace App\Support\Search;

use Carbon\CarbonImmutable;

/**
 * A parsed search (BRAI-55): what to match (QueryParser) and the filters. Engine-neutral — an
 * OpenSearch engine would build its own query from the same object.
 */
final class SearchQuery
{
    public const STATUSES = ['open', 'done'];

    public const MAX_PER_PAGE = 50;

    /**
     * @param  list<array{words: list<string>, phrase: bool, quoted: bool, negated: bool, prefix: bool}>  $terms  every word is letters / digits only
     * @param  list<string>  $keys  issue / ticket keys typed exactly (upper case), ranked first
     * @param  list<string>  $types  SearchTypes; empty = every type
     * @param  list<int>  $projectIds  empty = every project
     */
    public function __construct(
        public readonly string $text,
        public readonly array $terms,
        public readonly array $keys = [],
        public readonly array $types = [],
        public readonly array $projectIds = [],
        public readonly ?string $status = null,
        public readonly ?int $assigneeId = null,
        public readonly ?CarbonImmutable $updatedFrom = null,
        public readonly ?CarbonImmutable $updatedTo = null,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?int $contextProjectId = null,
        public readonly bool $withCounts = true,
    ) {}

    /** Something to find: at least one word that isn't excluded. */
    public function isSearchable(): bool
    {
        foreach ($this->terms as $term) {
            if (! $term['negated'] && $term['words'] !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{words: list<string>, phrase: bool, quoted: bool, negated: bool, prefix: bool}> */
    public function included(): array
    {
        return array_values(array_filter($this->terms, fn (array $term) => ! $term['negated']));
    }

    /** @return list<array{words: list<string>, phrase: bool, quoted: bool, negated: bool, prefix: bool}> */
    public function excluded(): array
    {
        return array_values(array_filter($this->terms, fn (array $term) => $term['negated']));
    }

    /** @return list<string> the words that must match (for highlighting and the typo fallback) */
    public function positiveWords(): array
    {
        $words = [];
        foreach ($this->terms as $term) {
            if (! $term['negated']) {
                array_push($words, ...$term['words']);
            }
        }

        return $words;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
