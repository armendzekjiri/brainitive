<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Gantt plans: name, description and the names of their rows. */
class PlanSource extends Source
{
    public function type(): string
    {
        return SearchTypes::PLAN;
    }

    public function idColumn(): string
    {
        return 'plans.id';
    }

    protected function base(): Builder
    {
        return DB::table('plans')
            ->join('projects', 'projects.id', '=', 'plans.project_id')
            ->whereNull('plans.deleted_at')
            ->select(['plans.id', 'plans.name', 'plans.description', 'plans.data', 'plans.created_by', 'plans.updated_at', 'plans.project_id', 'projects.workspace_id']);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $data = is_string($row->data) ? json_decode($row->data, true) : null;
            $rowNames = array_map(
                fn ($r) => is_array($r) && is_string($r['name'] ?? null) ? $r['name'] : null,
                is_array($data) && is_array($data['rows'] ?? null) ? $data['rows'] : [],
            );
            $documents[$id] = new SearchDocument(
                type: SearchTypes::PLAN,
                sourceId: $id,
                title: (string) $row->name,
                projectId: (int) $row->project_id,
                authorId: self::int($row->created_by),
                body: self::join([SearchText::plain($row->description), ...$rowNames]),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
