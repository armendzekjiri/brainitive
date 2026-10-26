<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Quick captures (BRAI-70): private to their owner — the only person who ever finds them. */
class CaptureSource extends Source
{
    public function type(): string
    {
        return SearchTypes::CAPTURE;
    }

    protected function idColumn(): string
    {
        return 'captures.id';
    }

    protected function base(): Builder
    {
        return DB::table('captures')
            ->select(['captures.id', 'captures.user_id', 'captures.text', 'captures.url', 'captures.source', 'captures.archived_at', 'captures.processed_at', 'captures.updated_at']);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $text = SearchText::plain($row->text);
            $documents[$id] = new SearchDocument(
                type: SearchTypes::CAPTURE,
                sourceId: $id,
                title: mb_substr(strtok((string) $row->text, "\n") ?: $text, 0, 160),
                ownerId: (int) $row->user_id,
                authorId: (int) $row->user_id,
                keywords: self::join([$row->url, $row->source]),
                body: $text,
                status: $row->archived_at !== null || $row->processed_at !== null ? 'done' : 'open',
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
