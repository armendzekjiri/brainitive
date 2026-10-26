<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** File names of issue attachments; live while the issue and its board are. */
class AttachmentSource extends Source
{
    public function type(): string
    {
        return SearchTypes::ATTACHMENT;
    }

    protected function idColumn(): string
    {
        return 'attachments.id';
    }

    protected function base(): Builder
    {
        return DB::table('attachments')
            ->join('items', 'items.id', '=', 'attachments.item_id')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->join('projects', 'projects.id', '=', 'boards.project_id')
            ->whereNull('items.deleted_at')
            ->whereNull('boards.deleted_at')
            ->select([
                'attachments.id', 'attachments.original_filename', 'attachments.filename', 'attachments.mime_type',
                'attachments.user_id', 'attachments.updated_at', 'attachments.item_id', 'boards.project_id', 'projects.workspace_id',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::ATTACHMENT,
                sourceId: $id,
                title: (string) ($row->original_filename ?: $row->filename),
                projectId: (int) $row->project_id,
                workspaceId: (int) $row->workspace_id,
                containerId: (int) $row->item_id,
                authorId: self::int($row->user_id),
                keywords: $row->mime_type,
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
