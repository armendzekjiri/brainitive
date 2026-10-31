<?php

namespace App\Support\Templates;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Support\Boards\ItemFieldValues;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Turns a project into a template document (BRAI-72): its structure and — by choice — sample
 * issues, published docs, task lists and plans. Nothing personal or secret is captured:
 * no comments, attachments, history, watchers, members or assignees, no sprints, releases,
 * helpdesks or integrations, no tokens, no drafts or unpublished docs. Mentions become plain
 * text and links to issues inside whiteboards are removed.
 *
 * Dates become day offsets from the earliest captured date (day 0 = the new start date).
 */
class TemplateCapture
{
    /** @var array<string, array<int, string>> kind => [id => ref] */
    private array $refs = ['type' => [], 'label' => [], 'board' => [], 'column' => [], 'field' => [], 'option' => [], 'item' => [], 'page' => []];

    private CarbonImmutable $anchor;

    /** @var Collection<int, ItemField> active fields by id */
    private $fieldDefinitions;

    /** @var Collection<int, string> priority id => slug */
    private $prioritySlugs;

    /** @var Collection<int, string>|null default type id => slug */
    private $globalTypeSlugs = null;

    private function __construct(private readonly Project $project, private readonly array $include) {}

    /**
     * @param  list<string>  $include  sections of TemplateDocument::SECTIONS to capture
     */
    public static function capture(Project $project, array $include): array
    {
        return (new self($project, array_values(array_intersect($include, TemplateDocument::SECTIONS))))->run();
    }

    private function includes(string $section): bool
    {
        return in_array($section, $this->include, true);
    }

    private function run(): array
    {
        $boards = $this->project->boards()->with(['columns' => fn ($q) => $q->orderBy('position')])->orderBy('position')->orderBy('id')->get();
        $items = $this->includes('issues') ? $this->items($boards) : collect();
        $pages = $this->includes('pages') ? $this->pages() : [];
        $lists = $this->includes('task_lists') ? TaskList::where('project_id', $this->project->id)->with(['items' => fn ($q) => $q->orderBy('position')])->orderBy('position')->orderBy('id')->get() : collect();
        $plans = $this->includes('plans') ? Plan::where('project_id', $this->project->id)->orderBy('position')->orderBy('id')->get() : collect();
        $this->anchor = $this->anchorDate($items, $lists, $plans);
        $this->prioritySlugs = ItemPriority::pluck('slug', 'id');

        $doc = [
            'format' => TemplateDocument::FORMAT,
            'project' => [
                'description' => $this->project->description,
                'disabled_type_slugs' => ItemType::whereIn('id', $this->project->disabledItemTypeIds())->pluck('slug')->all(),
            ],
            'item_types' => $this->itemTypes(),
            'labels' => $this->labels(),
            'boards' => $boards->map(fn (Board $board) => $this->board($board))->all(),
        ];
        $doc['fields'] = $this->fields();
        $doc['items'] = $items->map(fn (Item $item) => $this->item($item))->values()->all();
        $doc['links'] = $this->links($items);
        $doc['pages'] = $pages;
        $doc['task_lists'] = $lists->map(fn (TaskList $list) => [
            'name' => $list->name,
            'description' => $list->description,
            'tasks' => $list->items->map(fn ($task) => [
                'title' => $task->title,
                'description' => is_array($task->description) ? ContentSanitizer::json($task->description) : null,
                'start' => $this->offset($task->start_date),
                'due' => $this->offset($task->due_date),
                'start_time' => $task->start_time,
                'due_time' => $task->due_time,
                'recurrence' => $task->recurrence,
                'reminder_minutes' => $task->reminder_minutes,
            ])->values()->all(),
        ])->values()->all();
        $doc['plans'] = $plans->map(fn (Plan $plan) => $this->plan($plan))->values()->all();

        return TemplateDocument::normalize($doc);
    }

    private function ref(string $kind, int $id): string
    {
        return $this->refs[$kind][$id] ??= substr($kind, 0, 1).(count($this->refs[$kind]) + 1);
    }

    private function offset(mixed $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }
        $date = $date instanceof CarbonInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse((string) $date);

        return (int) $this->anchor->startOfDay()->diffInDays($date->startOfDay(), false);
    }

    private function anchorDate($items, $lists, $plans): CarbonImmutable
    {
        $dates = [];
        foreach ($items as $item) {
            array_push($dates, $item->start_date?->toDateString(), $item->due_date?->toDateString());
            // Custom date fields shift with the rest
            foreach ($item->fieldValues as $value) {
                $dates[] = $value->value_date?->toDateString();
            }
        }
        foreach ($lists as $list) {
            foreach ($list->items as $task) {
                array_push($dates, $task->start_date ? (string) $task->start_date->toDateString() : null, $task->due_date ? (string) $task->due_date->toDateString() : null);
            }
        }
        foreach ($plans as $plan) {
            foreach ($plan->data['rows'] ?? [] as $row) {
                foreach ($row['bars'] ?? [] as $bar) {
                    $dates[] = $bar['start'] ?? null;
                }
            }
        }
        $dates = array_filter($dates, fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d));

        return $dates === [] ? CarbonImmutable::today() : CarbonImmutable::parse(min($dates));
    }

    private function itemTypes(): array
    {
        return ItemType::where('project_id', $this->project->id)->where('is_active', true)->orderBy('id')->get()
            ->map(fn (ItemType $type) => [
                'ref' => $this->ref('type', $type->id),
                'name' => $type->name,
                'slug' => $type->slug,
                'color' => $type->color,
                'icon' => $type->icon,
                'description' => $type->description,
            ])->all();
    }

    private function typeRef(?int $typeId): ?string
    {
        if ($typeId === null) {
            return null;
        }
        if (isset($this->refs['type'][$typeId])) {
            return $this->refs['type'][$typeId];
        }
        $this->globalTypeSlugs ??= ItemType::whereNull('project_id')->pluck('slug', 'id');
        $slug = $this->globalTypeSlugs[$typeId] ?? null;

        return $slug ? "slug:{$slug}" : null;
    }

    private function labels(): array
    {
        return $this->project->labels()->orderBy('name')->get()
            ->map(fn ($label) => ['ref' => $this->ref('label', $label->id), 'name' => $label->name, 'color' => $label->color])->all();
    }

    private function board(Board $board): array
    {
        $lanes = $board->settings['swimlanes'] ?? null;
        $prioritySlugs = $this->prioritySlugs ??= ItemPriority::pluck('slug', 'id');

        return [
            'ref' => $this->ref('board', $board->id),
            'name' => $board->name,
            'description' => $board->description,
            'type' => $board->type,
            'done_retention_days' => $board->doneRetentionSetting(),
            'columns' => $board->columns->map(fn ($column) => [
                'ref' => $this->ref('column', $column->id),
                'name' => $column->name,
                'color' => $column->color,
                'wip_limit' => $column->wip_limit,
                'is_done' => (bool) $column->is_done_column,
            ])->all(),
            'swimlanes' => is_array($lanes) ? [
                'mode' => $lanes['mode'] ?? 'none',
                'rest_name' => $lanes['rest_name'] ?? null,
                'lanes' => array_map(function ($lane) use ($prioritySlugs) {
                    $rules = (array) ($lane['rules'] ?? []);

                    return [
                        'name' => $lane['name'] ?? '',
                        'color' => $lane['color'] ?? null,
                        'rules' => [
                            'priorities' => array_values(array_filter(array_map(fn ($id) => $prioritySlugs[$id] ?? null, $rules['priority_ids'] ?? []))),
                            'types' => array_values(array_filter(array_map(fn ($id) => $this->typeRef((int) $id), $rules['type_ids'] ?? []))),
                            'labels' => array_values(array_filter(array_map(fn ($id) => $this->refs['label'][$id] ?? null, $rules['label_ids'] ?? []))),
                            // People and releases are never part of a template
                            'unassigned' => in_array(0, $rules['assignee_ids'] ?? [], true),
                            'no_release' => in_array(0, $rules['release_ids'] ?? [], true),
                            'overdue' => ! empty($rules['overdue']),
                        ],
                    ];
                }, $lanes['lanes'] ?? []),
            ] : null,
        ];
    }

    private function fields(): array
    {
        $this->fieldDefinitions = ItemField::where('project_id', $this->project->id)->active()->with('options')->orderBy('position')->orderBy('id')->get()->keyBy('id');

        return $this->fieldDefinitions->values()
            ->map(fn (ItemField $field) => [
                'ref' => $this->ref('field', $field->id),
                'name' => $field->name,
                'type' => $field->type,
                'boards' => $field->board_ids === null ? null : array_values(array_filter(array_map(fn ($id) => $this->refs['board'][(int) $id] ?? null, $field->board_ids))),
                'options' => $field->options->whereNull('archived_at')->map(fn ($option) => [
                    'ref' => $this->ref('option', $option->id),
                    'label' => $option->label,
                    'color' => $option->color,
                ])->values()->all(),
            ])->all();
    }

    /**
     * Issues of the captured boards, parents before their subtasks.
     */
    private function items($boards)
    {
        if (Item::whereIn('board_id', $boards->pluck('id'))->count() > TemplateDocument::MAX_ITEMS) {
            throw new TemplateException('This project has more than '.TemplateDocument::MAX_ITEMS.' issues. Save it without sample issues.');
        }
        $items = Item::whereIn('board_id', $boards->pluck('id'))
            ->with(['labels:id', 'fieldValues'])
            ->orderBy('board_id')->orderBy('position')->orderBy('id')
            ->get();

        $byId = $items->keyBy('id');
        $depth = function (Item $item) use ($byId): int {
            $d = 0;
            for ($parent = $item->parent_id; $parent !== null && isset($byId[$parent]) && $d < 5; $parent = $byId[$parent]->parent_id) {
                $d++;
            }

            return $d;
        };

        $sorted = $items->sortBy(fn (Item $item) => $depth($item))->values();
        $sorted->each(fn (Item $item) => $this->ref('item', $item->id));

        return $sorted;
    }

    private function item(Item $item): array
    {
        $fields = [];
        foreach ($item->fieldValues as $row) {
            $field = $this->fieldDefinitions[$row->item_field_id] ?? null;
            $ref = $this->refs['field'][$row->item_field_id] ?? null;
            if (! $field || ! $ref) {
                continue;
            }
            $value = ItemFieldValues::valueOf($field, $row);
            $fields[$ref] = match ($field->type) {
                'date' => $this->offset($value),
                'select' => $this->refs['option'][$value] ?? null,
                default => $value,
            };
        }

        return [
            'ref' => $this->refs['item'][$item->id],
            'board' => $this->refs['board'][$item->board_id] ?? null,
            'column' => $this->refs['column'][$item->column_id] ?? null,
            'parent' => $item->parent_id !== null ? ($this->refs['item'][$item->parent_id] ?? null) : null,
            'title' => $item->title,
            'description' => ContentSanitizer::html($item->description),
            'type' => $this->typeRef($item->item_type_id),
            'priority' => $item->priority_id ? ($this->prioritySlugs[$item->priority_id] ?? null) : null,
            'labels' => $item->labels->map(fn ($label) => $this->refs['label'][$label->id] ?? null)->filter()->values()->all(),
            'story_points' => $item->story_points,
            'original_estimate' => $item->original_estimate,
            'start' => $this->offset($item->start_date),
            'due' => $this->offset($item->due_date),
            'start_time' => $item->start_time,
            'due_time' => $item->due_time,
            'fields' => $fields,
        ];
    }

    private function links($items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        return ItemLink::whereIn('source_item_id', $items->pluck('id'))->whereIn('target_item_id', $items->pluck('id'))->get()
            ->map(fn (ItemLink $link) => [
                'from' => $this->refs['item'][$link->source_item_id],
                'to' => $this->refs['item'][$link->target_item_id],
                'type' => $link->link_type,
            ])->all();
    }

    /**
     * Folders and published docs (their live content, never a draft), parents first. A doc
     * under an unpublished one moves up to the nearest captured ancestor.
     */
    private function pages(): array
    {
        // Structure first (no content), content only for the pages that are captured
        $all = Page::where('project_id', $this->project->id)->orderBy('position')->orderBy('id')
            ->get(['id', 'parent_id', 'type', 'status', 'name', 'icon', 'position']);
        $byId = $all->keyBy('id');
        $included = $all->filter(fn (Page $page) => $page->type === 'folder' ? $page->status !== 'archived' : $page->status === 'published');
        if ($included->count() > TemplateDocument::MAX_PAGES) {
            throw new TemplateException('This project has more than '.TemplateDocument::MAX_PAGES.' docs. Save it without docs.');
        }
        $includedIds = $included->pluck('id')->flip();
        $contents = Page::whereIn('id', $included->where('type', '!=', 'folder')->pluck('id'))->get(['id', 'content'])->pluck('content', 'id');

        $parentOf = function (Page $page) use ($byId, $includedIds): ?int {
            for ($parent = $page->parent_id, $guard = 0; $parent !== null && $guard < 50; $parent = $byId[$parent]->parent_id ?? null, $guard++) {
                if (isset($includedIds[$parent])) {
                    return $parent;
                }
            }

            return null;
        };

        // Breadth-first so every parent is listed before its children
        $result = [];
        $queue = $included->filter(fn (Page $page) => $parentOf($page) === null)->values()->all();
        $children = $included->groupBy(fn (Page $page) => $parentOf($page) ?? 0);
        while ($queue !== []) {
            $page = array_shift($queue);
            $parent = $parentOf($page);
            $result[] = [
                'ref' => $this->ref('page', $page->id),
                'parent' => $parent !== null ? $this->refs['page'][$parent] : null,
                'type' => $page->type,
                'name' => $page->name,
                'icon' => is_string($page->icon) && strlen($page->icon) <= 255 ? $page->icon : null,
                'content' => $page->type === 'folder' ? null : ContentSanitizer::pageContent($contents[$page->id] ?? null),
            ];
            foreach ($children[$page->id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $result;
    }

    private function plan(Plan $plan): array
    {
        $data = $plan->data ?? [];

        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'settings' => $plan->settings ?? [],
            'rows' => array_map(fn ($row) => [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? '',
                'level' => $row['level'] ?? 0,
                'status' => $row['status'] ?? null,
                'notes' => $row['notes'] ?? null,
                'collapsed' => $row['collapsed'] ?? false,
                // "Who" is free text naming people: never part of a template
                'item' => isset($row['itemId']) ? ($this->refs['item'][(int) $row['itemId']] ?? null) : null,
                'bars' => array_map(fn ($bar) => [
                    'id' => $bar['id'] ?? null,
                    'start' => $this->offset($bar['start'] ?? null),
                    'end' => $this->offset($bar['end'] ?? null),
                    'type' => $bar['type'] ?? 'bar',
                    'color' => $bar['color'] ?? null,
                    'label' => $bar['label'] ?? null,
                    'progress' => $bar['progress'] ?? null,
                ], $row['bars'] ?? []),
            ], $data['rows'] ?? []),
            'dependencies' => $data['dependencies'] ?? [],
        ];
    }
}
