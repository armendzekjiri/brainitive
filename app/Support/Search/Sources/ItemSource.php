<?php

namespace App\Support\Search\Sources;

use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Issues: key + title, description, labels and custom field text; live on a live board. */
class ItemSource extends Source
{
    public function type(): string
    {
        return SearchTypes::ITEM;
    }

    public function idColumn(): string
    {
        return 'items.id';
    }

    protected function base(): Builder
    {
        return DB::table('items')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->join('projects', 'projects.id', '=', 'boards.project_id')
            ->leftJoin('board_columns', 'board_columns.id', '=', 'items.column_id')
            ->whereNull('items.deleted_at')
            ->whereNull('boards.deleted_at')
            ->select([
                'items.id', 'items.key', 'items.title', 'items.description', 'items.created_by', 'items.assigned_to',
                'items.item_type_id', 'items.updated_at', 'items.board_id', 'boards.project_id', 'projects.workspace_id',
                'board_columns.is_done_column',
            ]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $labels = DB::table('item_label')
            ->join('labels', 'labels.id', '=', 'item_label.label_id')
            ->whereIn('item_label.item_id', $ids)
            ->get(['item_label.item_id', 'labels.name'])
            ->groupBy('item_id');

        // custom field text: text values and chosen options of active fields
        $fields = DB::table('item_field_values')
            ->join('item_fields', 'item_fields.id', '=', 'item_field_values.item_field_id')
            ->leftJoin('item_field_options', 'item_field_options.id', '=', 'item_field_values.option_id')
            ->whereIn('item_field_values.item_id', $ids)
            ->whereNull('item_fields.archived_at')
            ->get(['item_field_values.item_id', 'item_field_values.value_text', 'item_field_options.label'])
            ->groupBy('item_id');

        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::ITEM,
                sourceId: $id,
                title: (string) ($row->title ?: $row->key),
                projectId: (int) $row->project_id,
                containerId: (int) $row->board_id,
                authorId: self::int($row->created_by),
                key: $row->key,
                keywords: self::join([
                    $row->key,
                    ...($labels[$id] ?? collect())->pluck('name')->all(),
                    ...($fields[$id] ?? collect())->flatMap(fn ($f) => [$f->value_text, $f->label])->all(),
                ]),
                body: SearchText::fromHtml($row->description),
                status: $row->is_done_column ? 'done' : 'open',
                assigneeId: self::int($row->assigned_to),
                itemTypeId: self::int($row->item_type_id),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }
}
