<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Code snippets: title, language, description and code. Every member of the workspace can open
 * every collection (SnippetController), so they are visible by workspace membership.
 */
class SnippetSource extends Source
{
    public function type(): string
    {
        return SearchTypes::SNIPPET;
    }

    protected function idColumn(): string
    {
        return 'snippets.id';
    }

    protected function base(): Builder
    {
        return DB::table('snippets')
            ->join('snippet_collections', 'snippet_collections.id', '=', 'snippets.collection_id')
            ->select([
                'snippets.id', 'snippets.title', 'snippets.description', 'snippets.language', 'snippets.content',
                'snippets.created_by', 'snippets.updated_at', 'snippets.collection_id', 'snippet_collections.workspace_id',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::SNIPPET,
                sourceId: $id,
                title: (string) $row->title,
                workspaceId: (int) $row->workspace_id,
                containerId: (int) $row->collection_id,
                authorId: self::int($row->created_by),
                subtype: $row->language ? mb_substr((string) $row->language, 0, 24) : null,
                keywords: $row->language,
                body: self::join([SearchText::plain($row->description), SearchText::plain($row->content)]),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
