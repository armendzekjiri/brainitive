<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Helpdesk tickets: key + subject, the requester, description and every reply (internal notes
 * included — the agents who can open the project see those too). Live on a live helpdesk.
 */
class TicketSource extends Source
{
    /** Replies are read in full only up to this much text per ticket. */
    private const REPLIES_LIMIT = 40000;

    private const MAX_REPLIES = 200;

    private const REPLY_LIMIT = 4000;

    public function type(): string
    {
        return SearchTypes::TICKET;
    }

    public function idColumn(): string
    {
        return 'tickets.id';
    }

    protected function base(): Builder
    {
        return DB::table('tickets')
            ->join('helpdesks', 'helpdesks.id', '=', 'tickets.helpdesk_id')
            ->join('projects', 'projects.id', '=', 'helpdesks.project_id')
            ->leftJoin('helpdesk_columns', 'helpdesk_columns.id', '=', 'tickets.column_id')
            ->leftJoin('ticket_contacts', 'ticket_contacts.id', '=', 'tickets.ticket_contact_id')
            ->whereNull('tickets.deleted_at')
            ->whereNull('helpdesks.deleted_at')
            ->select([
                'tickets.id', 'tickets.key', 'tickets.subject', 'tickets.description', 'tickets.assigned_to', 'tickets.updated_at',
                'tickets.helpdesk_id', 'helpdesks.project_id', 'projects.workspace_id', 'helpdesk_columns.is_done_column',
                'ticket_contacts.name as contact_name', 'ticket_contacts.email as contact_email',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        // the newest MAX_REPLIES replies per ticket, each cut to REPLY_LIMIT characters: a long
        // thread (inbound mail, the portal) never makes one re-index read everything
        $replies = DB::query()
            ->fromSub(DB::table('ticket_replies')
                ->whereIn('ticket_id', $ids)
                ->select('ticket_id', 'id')
                ->selectRaw('substr(body, 1, ?) as body', [self::REPLY_LIMIT])
                ->selectRaw('row_number() over (partition by ticket_id order by id desc) as n'), 'r')
            ->where('n', '<=', self::MAX_REPLIES)
            ->orderBy('id')
            ->get(['ticket_id', 'body'])
            ->groupBy('ticket_id');

        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $replyText = '';
            foreach ($replies[$id] ?? [] as $reply) {
                if (mb_strlen($replyText) > self::REPLIES_LIMIT) {
                    break;
                }
                $replyText .= ' '.SearchText::fromHtml($reply->body);
            }
            $documents[$id] = new SearchDocument(
                type: SearchTypes::TICKET,
                sourceId: $id,
                title: (string) ($row->subject ?: $row->key),
                projectId: (int) $row->project_id,
                containerId: (int) $row->helpdesk_id,
                key: $row->key,
                keywords: self::join([$row->key, $row->contact_name, $row->contact_email]),
                body: self::join([SearchText::fromHtml($row->description), $replyText]),
                status: $row->is_done_column ? 'done' : 'open',
                assigneeId: self::int($row->assigned_to),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
