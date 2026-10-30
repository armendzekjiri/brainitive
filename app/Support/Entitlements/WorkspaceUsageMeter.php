<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Measures what one workspace uses (usage:measure, Cloud plans v1.46.0). One aggregate query per
 * table, never per row. Rows of trashed projects / items still take space, so they count.
 *
 * - content: the stored size of the rows (`pg_column_size`, compressed / TOASTed) in the tables
 *   below — an approximation, the tables are shared. Not measured on other databases (SQLite
 *   self-hosted installs are unlimited anyway): stays 0 there.
 * - files: the `size` columns of the upload tables. Editor images (`uploads/`), bookmark files,
 *   icons and logos have no size column and don't count; whiteboard images live in the page content.
 */
final class WorkspaceUsageMeter
{
    /**
     * Tables with user content and the path to their workspace: [table, [foreign key => parent
     * table, …], the column of the last table that names the owner (project_id or workspace_id)].
     * Collab states (a CRDT copy of the same doc / issue) and the search index are derived: not counted.
     *
     * @var list<array{0: string, 1: array<string, string>, 2: string}>
     */
    private const CONTENT = [
        ['pages', [], 'project_id'], // docs, whiteboards, meeting notes
        ['page_versions', ['page_id' => 'pages'], 'project_id'],
        ['page_comments', ['page_id' => 'pages'], 'project_id'],
        ['page_reviews', ['page_id' => 'pages'], 'project_id'],
        ['items', [], 'project_id'],
        ['comments', ['item_id' => 'items'], 'project_id'],
        ['plans', [], 'project_id'],
        ['plan_versions', ['plan_id' => 'plans'], 'project_id'],
        ['task_lists', [], 'project_id'],
        ['task_list_items', ['task_list_id' => 'task_lists'], 'project_id'],
        ['tickets', ['helpdesk_id' => 'helpdesks'], 'project_id'],
        ['ticket_replies', ['ticket_id' => 'tickets', 'helpdesk_id' => 'helpdesks'], 'project_id'],
        ['releases', [], 'project_id'],
        ['meetings', [], 'project_id'],
        ['project_health_reports', [], 'project_id'],
        ['feedback_posts', ['feedback_portal_id' => 'feedback_portals'], 'project_id'],
        ['leads', ['lead_form_id' => 'lead_forms'], 'project_id'],
        ['client_deliverables', ['client_portal_id' => 'client_portals'], 'project_id'],
        ['snippets', ['collection_id' => 'snippet_collections'], 'workspace_id'],
        ['bookmark_items', ['bookmark_id' => 'bookmarks'], 'workspace_id'],
    ];

    /** Upload tables with a `size` column (bytes). */
    private const FILES = [
        ['attachments', ['item_id' => 'items'], 'project_id'],
        ['ticket_attachments', ['ticket_id' => 'tickets', 'helpdesk_id' => 'helpdesks'], 'project_id'],
    ];

    public function measure(Workspace $workspace): WorkspaceUsage
    {
        $usage = WorkspaceUsage::updateOrCreate(['workspace_id' => $workspace->id], [
            'members' => LimitGate::memberCount($workspace),
            'projects' => $workspace->projects()->count(),
            'content_bytes' => $this->contentBytes($workspace),
            'file_bytes' => $this->fileBytes($workspace),
            'measured_at' => now(),
        ]);
        $workspace->setRelation('usage', $usage);

        return $usage;
    }

    public function contentBytes(Workspace $workspace): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 0;
        }

        return $this->sum(self::CONTENT, $workspace, 'pg_column_size(t0.*)');
    }

    public function fileBytes(Workspace $workspace): int
    {
        return $this->sum(self::FILES, $workspace, 't0.size');
    }

    /** @param  list<array{0: string, 1: array<string, string>, 2: string}>  $tables */
    private function sum(array $tables, Workspace $workspace, string $expression): int
    {
        $total = 0;
        foreach ($tables as [$table, $path, $owner]) {
            $total += (int) $this->scoped($table, $path, $owner, $workspace->id)
                ->selectRaw("coalesce(sum({$expression}), 0) as bytes")
                ->value('bytes');
        }

        return $total;
    }

    /** @param  array<string, string>  $path */
    private function scoped(string $table, array $path, string $owner, int $workspaceId): Builder
    {
        $query = DB::table("{$table} as t0");
        $alias = 't0';
        foreach ($path as $foreignKey => $parentTable) {
            $parent = 't'.(count($query->joins ?? []) + 1);
            $query->join("{$parentTable} as {$parent}", "{$parent}.id", '=', "{$alias}.{$foreignKey}");
            $alias = $parent;
        }

        return $owner === 'workspace_id'
            ? $query->where("{$alias}.workspace_id", $workspaceId)
            : $query->join('projects as p', 'p.id', '=', "{$alias}.project_id")->where('p.workspace_id', $workspaceId);
    }
}
