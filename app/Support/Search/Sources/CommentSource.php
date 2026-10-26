<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issue comments: their text only (the result names the issue, read live). Live while the comment,
 * its issue and the issue's board are.
 */
class CommentSource extends Source
{
    public function type(): string
    {
        return SearchTypes::COMMENT;
    }

    protected function idColumn(): string
    {
        return 'comments.id';
    }

    protected function base(): Builder
    {
        return DB::table('comments')
            ->join('items', 'items.id', '=', 'comments.item_id')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->join('projects', 'projects.id', '=', 'boards.project_id')
            ->whereNull('comments.deleted_at')
            ->whereNull('items.deleted_at')
            ->whereNull('boards.deleted_at')
            ->select([
                'comments.id', 'comments.body', 'comments.user_id', 'comments.updated_at', 'comments.item_id',
                'boards.project_id', 'projects.workspace_id',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::COMMENT,
                sourceId: $id,
                title: '',
                projectId: (int) $row->project_id,
                workspaceId: (int) $row->workspace_id,
                containerId: (int) $row->item_id,
                authorId: self::int($row->user_id),
                body: SearchText::fromHtml($row->body),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
