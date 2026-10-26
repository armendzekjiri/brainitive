<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Shared plumbing of the search sources: rows come from the query builder (no model events, no
 * casts), one query for the records plus at most a couple for their related text.
 */
abstract class Source implements SearchSource
{
    /** Indexable records with everything documents() reads; `id` is the source id. */
    abstract protected function base(): Builder;

    /** @return array<int, SearchDocument> */
    abstract protected function build(Collection $rows, array $ids): array;

    /** The id column of base(), e.g. `items.id`. */
    abstract protected function idColumn(): string;

    public function ids(): Builder
    {
        return $this->base()->select($this->idColumn().' as id');
    }

    public function documents(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->base()->whereIn($this->idColumn(), $ids)->get();

        return $rows->isEmpty() ? [] : $this->build($rows, $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    protected static function time(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    protected static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** @param  iterable<string|null>  $parts */
    protected static function join(iterable $parts): ?string
    {
        $text = trim(implode(' ', array_filter(is_array($parts) ? $parts : iterator_to_array($parts), fn ($p) => is_string($p) && trim($p) !== '')));

        return $text === '' ? null : $text;
    }
}
