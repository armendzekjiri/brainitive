<?php

namespace App\Support\Insights;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insights → Usage (BRAI-105): the workspace as it is now (not range-bound).
 * - members by role (`workspace_user`), pending invitations (not accepted, not expired);
 * - storage by project = issue attachments + ticket attachments (bytes as stored; files of
 *   soft-deleted issues and tickets still count, they are still on disk);
 * - webhooks, once the `webhooks` table exists (ticket 3 of v1.39.0).
 *
 * Six queries at most.
 */
final class UsageInsights
{
    /** @return array<string, mixed> */
    public static function compute(InsightsFilters $filters): array
    {
        $workspaceId = $filters->workspace->id;
        $ids = $filters->projectIds();

        $roles = DB::table('workspace_user')->where('workspace_id', $workspaceId)
            ->groupBy('role')->selectRaw('role, count(*) as total')->pluck('total', 'role')
            ->map(fn ($total) => (int) $total)->all();

        $pendingInvites = DB::table('workspace_invitations')->where('workspace_id', $workspaceId)
            ->whereNull('accepted_at')->where('expires_at', '>', $filters->now->format('Y-m-d H:i:s'))->count();

        $issueFiles = DB::table('attachments')
            ->join('items', 'items.id', '=', 'attachments.item_id')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->whereIn('boards.project_id', $ids)
            ->groupBy('boards.project_id')
            ->selectRaw('boards.project_id, count(*) as files, sum(attachments.size) as bytes')
            ->get()->keyBy('project_id');

        $ticketFiles = DB::table('ticket_attachments')
            ->join('tickets', 'tickets.id', '=', 'ticket_attachments.ticket_id')
            ->join('helpdesks', 'helpdesks.id', '=', 'tickets.helpdesk_id')
            ->whereIn('helpdesks.project_id', $ids)
            ->groupBy('helpdesks.project_id')
            ->selectRaw('helpdesks.project_id, count(*) as files, sum(ticket_attachments.size) as bytes')
            ->get()->keyBy('project_id');

        $storage = [];
        foreach ($ids as $id) {
            $issueBytes = (int) ($issueFiles->get($id)->bytes ?? 0);
            $ticketBytes = (int) ($ticketFiles->get($id)->bytes ?? 0);
            $storage[] = [
                'id' => $id,
                'name' => $filters->projects[$id]['name'],
                'color' => $filters->projects[$id]['color'],
                'files' => (int) ($issueFiles->get($id)->files ?? 0) + (int) ($ticketFiles->get($id)->files ?? 0),
                'issue_bytes' => $issueBytes,
                'ticket_bytes' => $ticketBytes,
                'bytes' => $issueBytes + $ticketBytes,
            ];
        }
        usort($storage, fn (array $a, array $b) => [$b['bytes'], $a['name']] <=> [$a['bytes'], $b['name']]);

        $webhooks = Schema::hasTable('webhooks')
            ? DB::table('webhooks')->where('workspace_id', $workspaceId)->count()
            : null;

        return [
            'members' => array_sum($roles),
            'roles' => $roles,
            'pending_invites' => $pendingInvites,
            'projects' => count($filters->projects),
            'storage' => $storage,
            'storage_bytes' => array_sum(array_column($storage, 'bytes')),
            'webhooks' => $webhooks,
        ];
    }
}
