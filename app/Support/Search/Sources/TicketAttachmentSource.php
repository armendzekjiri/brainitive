<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** File names of ticket attachments (agents only, like the ticket); live with the ticket. */
class TicketAttachmentSource extends Source
{
    public function type(): string
    {
        return SearchTypes::TICKET_ATTACHMENT;
    }

    public function idColumn(): string
    {
        return 'ticket_attachments.id';
    }

    protected function base(): Builder
    {
        return DB::table('ticket_attachments')
            ->join('tickets', 'tickets.id', '=', 'ticket_attachments.ticket_id')
            ->join('helpdesks', 'helpdesks.id', '=', 'tickets.helpdesk_id')
            ->join('projects', 'projects.id', '=', 'helpdesks.project_id')
            ->whereNull('tickets.deleted_at')
            ->whereNull('helpdesks.deleted_at')
            ->select([
                'ticket_attachments.id', 'ticket_attachments.original_filename', 'ticket_attachments.filename', 'ticket_attachments.mime_type',
                'ticket_attachments.updated_at', 'ticket_attachments.ticket_id', 'helpdesks.project_id', 'projects.workspace_id',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::TICKET_ATTACHMENT,
                sourceId: $id,
                title: (string) ($row->original_filename ?: $row->filename),
                projectId: (int) $row->project_id,
                containerId: (int) $row->ticket_id,
                keywords: $row->mime_type,
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
