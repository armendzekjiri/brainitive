<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Task-list tasks: title and description; live in a live list. */
class TaskSource extends Source
{
    public function type(): string
    {
        return SearchTypes::TASK;
    }

    protected function idColumn(): string
    {
        return 'task_list_items.id';
    }

    protected function base(): Builder
    {
        return DB::table('task_list_items')
            ->join('task_lists', 'task_lists.id', '=', 'task_list_items.task_list_id')
            ->join('projects', 'projects.id', '=', 'task_lists.project_id')
            ->whereNull('task_list_items.deleted_at')
            ->whereNull('task_lists.deleted_at')
            ->select([
                'task_list_items.id', 'task_list_items.title', 'task_list_items.description', 'task_list_items.status',
                'task_list_items.assignee_id', 'task_list_items.created_by', 'task_list_items.updated_at',
                'task_list_items.task_list_id', 'task_lists.project_id', 'projects.workspace_id',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::TASK,
                sourceId: $id,
                title: (string) $row->title,
                projectId: (int) $row->project_id,
                workspaceId: (int) $row->workspace_id,
                containerId: (int) $row->task_list_id,
                authorId: self::int($row->created_by),
                body: SearchText::fromJson($row->description),
                status: $row->status === 'done' ? 'done' : 'open',
                assigneeId: self::int($row->assignee_id),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
