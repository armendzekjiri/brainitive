<?php

namespace App\Support\Templates;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemFieldValue;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Boards\Swimlanes;
use App\Support\Plans\PlanDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new project from a (normalized) template document (BRAI-72). Everything runs in
 * one transaction, so a failure leaves no half-created project. Every object gets fresh ids,
 * uuids, slugs and issue keys; refs in the document are mapped to the new ids as they're
 * created, so internal links never point at the template's source.
 */
class TemplateInstantiator
{
    /** @var array<string, array<string, int>> ref => new id, per kind */
    private array $ids = ['type' => [], 'label' => [], 'board' => [], 'column' => [], 'field' => [], 'option' => [], 'item' => [], 'page' => []];

    /** @var array<string, string> field ref => type */
    private array $fieldTypes = [];

    /** @var array<string, int>|null default type / priority slug => id, loaded once */
    private ?array $typeSlugs = null;

    private ?array $prioritySlugs = null;

    private Project $project;

    private function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
        private readonly array $doc,
        private readonly CarbonImmutable $start,
    ) {}

    /**
     * @param  array{name: string, key: string, description?: string|null, start_date: string}  $attributes
     */
    public static function create(Workspace $workspace, User $user, array $document, array $attributes): Project
    {
        $doc = TemplateDocument::normalize($document);
        $instance = new self($workspace, $user, $doc, CarbonImmutable::parse($attributes['start_date'])->startOfDay());

        return DB::transaction(fn () => $instance->run($attributes));
    }

    private function run(array $attributes): Project
    {
        $disabledTypes = ItemType::whereNull('project_id')->whereIn('slug', $this->doc['project']['disabled_type_slugs'])->pluck('id')->all();

        $this->project = $this->workspace->projects()->create([
            'name' => $attributes['name'],
            'slug' => self::freeSlug($this->workspace, $attributes['name']),
            'key' => strtoupper($attributes['key']),
            'description' => $attributes['description'] ?? $this->doc['project']['description'],
            'created_by' => $this->user->id,
            'settings' => $disabledTypes ? ['disabled_item_types' => $disabledTypes] : null,
            // The counter holds the last number used (getNextItemKey increments first), so 0 → KEY-1.
            'next_item_number' => 0,
        ]);
        $this->project->refresh();
        $this->project->addUser($this->user, 'admin');

        $this->createTypesAndLabels();
        $this->createBoards();
        $this->createFields();
        $this->createSwimlanes();
        $this->createItems();
        $this->createPages();
        $this->createTaskLists();
        $this->createPlans();

        return $this->project;
    }

    /**
     * A free project slug in the workspace (soft-deleted projects keep theirs).
     */
    public static function freeSlug(Workspace $workspace, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        for ($n = 2; Project::withTrashed()->where('workspace_id', $workspace->id)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function date(?int $offset): ?string
    {
        return $offset === null ? null : $this->start->addDays($offset)->toDateString();
    }

    private function createTypesAndLabels(): void
    {
        foreach ($this->doc['item_types'] as $type) {
            $this->ids['type'][$type['ref']] = $this->project->itemTypes()->create([
                'name' => $type['name'],
                'slug' => $type['slug'] ?? Str::slug($type['name']),
                'color' => $type['color'],
                'icon' => $type['icon'],
                'description' => $type['description'],
                'is_active' => true,
            ])->id;
        }

        // Label slugs are unique per project: two refs with the same name share one label.
        $byName = [];
        foreach ($this->doc['labels'] as $label) {
            $key = Str::slug($label['name']) ?: Str::lower($label['name']);
            $byName[$key] ??= $this->project->labels()->create(['name' => $label['name'], 'color' => $label['color']])->id;
            $this->ids['label'][$label['ref']] = $byName[$key];
        }
    }

    private function createBoards(): void
    {
        $slugs = [];
        foreach ($this->doc['boards'] as $position => $template) {
            $slug = $this->unique(Str::slug($template['name']) ?: 'board', $slugs);
            $board = $this->project->boards()->create([
                'name' => $template['name'],
                'slug' => $slug,
                'description' => $template['description'],
                'type' => $template['type'],
                'position' => $position,
                'created_by' => $this->user->id,
                // without a value Board::creating gives the default for new boards
                'settings' => isset($template['done_retention_days']) ? ['done_retention_days' => $template['done_retention_days']] : null,
            ]);
            $this->ids['board'][$template['ref']] = $board->id;

            // Board::created adds To Do / In Progress / Done; the template brings its own.
            if ($template['columns'] !== []) {
                $board->columns()->delete();
                $columnSlugs = [];
                foreach ($template['columns'] as $index => $column) {
                    $this->ids['column'][$column['ref']] = $board->columns()->create([
                        'name' => $column['name'],
                        'slug' => $this->unique(Str::slug($column['name']) ?: 'column', $columnSlugs),
                        'color' => $column['color'] ?? '#6b7280',
                        'position' => $index,
                        'wip_limit' => $column['wip_limit'],
                        'is_done_column' => $column['is_done'],
                    ])->id;
                }
            }
        }
    }

    private function createFields(): void
    {
        foreach ($this->doc['fields'] as $position => $template) {
            $field = $this->project->itemFields()->create([
                'name' => $template['name'],
                'type' => $template['type'],
                'board_ids' => $template['boards'] === null ? null : array_values(array_map(fn ($ref) => $this->ids['board'][$ref], $template['boards'])),
                'position' => $position + 1,
            ]);
            $this->ids['field'][$template['ref']] = $field->id;
            $this->fieldTypes[$template['ref']] = $template['type'];
            foreach ($template['options'] as $index => $option) {
                $this->ids['option'][$option['ref']] = $field->options()->create([
                    'label' => $option['label'],
                    'color' => $option['color'],
                    'position' => $index + 1,
                ])->id;
            }
        }
    }

    private function createSwimlanes(): void
    {
        foreach ($this->doc['boards'] as $template) {
            $lanes = $template['swimlanes'];
            if ($lanes === null) {
                continue;
            }
            $board = Board::with('project.workspace')->find($this->ids['board'][$template['ref']]);
            $input = [
                'mode' => $lanes['mode'],
                'rest_name' => $lanes['rest_name'],
                'lanes' => array_map(fn ($lane) => [
                    'name' => $lane['name'],
                    'color' => $lane['color'] && strlen($lane['color']) === 7 ? $lane['color'] : null,
                    'rules' => [
                        'priority_ids' => array_values(array_filter(array_map(fn ($slug) => $this->priorityId($slug), $lane['rules']['priorities']))),
                        'type_ids' => array_values(array_filter(array_map(fn ($ref) => $this->typeId($ref), $lane['rules']['types']))),
                        'label_ids' => array_values(array_filter(array_map(fn ($ref) => $this->ids['label'][$ref] ?? null, $lane['rules']['labels']))),
                        'assignee_ids' => $lane['rules']['unassigned'] ? [0] : [],
                        'release_ids' => $lane['rules']['no_release'] ? [0] : [],
                        'overdue' => $lane['rules']['overdue'],
                    ],
                ], $lanes['lanes']),
            ];
            $board->update(['settings' => array_merge($board->settings ?? [], ['swimlanes' => Swimlanes::normalize($board, $input)])]);
        }
    }

    private function createItems(): void
    {
        if ($this->doc['items'] === []) {
            return;
        }

        $boards = Board::with(['columns' => fn ($q) => $q->orderBy('position')])->whereIn('id', $this->ids['board'])->get()->keyBy('id');
        $doneColumns = $boards->flatMap(fn (Board $b) => $b->columns)->where('is_done_column', true)->pluck('id')->flip();
        $subtaskTypeIds = ItemType::forProject($this->project)->where('slug', 'subtask')->pluck('id')->flip();
        $defaultType = ItemType::forProject($this->project)->where('is_default', true)->value('id');
        $defaultPriority = ItemPriority::forProject($this->project)->where('is_default', true)->value('id');
        $positions = [];
        $backlog = [];

        foreach ($this->doc['items'] as $template) {
            $boardId = $this->ids['board'][$template['board']];
            $columnId = $this->ids['column'][$template['column']] ?? $boards[$boardId]->columns->first()?->id;
            $parentId = $template['parent'] !== null ? ($this->ids['item'][$template['parent']] ?? null) : null;
            $typeId = $this->typeId($template['type']) ?? $defaultType;
            // A subtask needs a parent (as in the app); without one it becomes a plain issue.
            if ($parentId === null && isset($subtaskTypeIds[$typeId])) {
                $typeId = $defaultType;
            }

            $item = Item::create([
                'board_id' => $boardId,
                'project_id' => $this->project->id,
                'column_id' => $columnId,
                'parent_id' => $parentId,
                'item_type_id' => $typeId,
                'priority_id' => $this->priorityId($template['priority']) ?? $defaultPriority,
                'key' => $this->project->getNextItemKey(),
                'title' => $template['title'],
                'description' => $template['description'],
                'created_by' => $this->user->id,
                'reporter_id' => $this->user->id,
                'position' => $positions[$columnId] = ($positions[$columnId] ?? 0) + 1,
                'backlog_position' => $backlog[$boardId] = ($backlog[$boardId] ?? 0) + 1,
                'story_points' => $template['story_points'],
                'original_estimate' => $template['original_estimate'],
                'start_date' => $this->date($template['start']),
                'start_time' => $template['start_time'],
                'due_date' => $this->date($template['due']),
                'due_time' => $template['due_time'],
                'completed_at' => isset($doneColumns[$columnId]) ? now() : null,
            ]);
            $this->ids['item'][$template['ref']] = $item->id;

            $labels = array_values(array_unique(array_filter(array_map(fn ($ref) => $this->ids['label'][$ref] ?? null, $template['labels']))));
            if ($labels !== []) {
                $item->labels()->sync($labels);
            }
            $this->insertFieldValues($item, $template['fields']);
        }

        foreach ($this->doc['links'] as $link) {
            ItemLink::create([
                'source_item_id' => $this->ids['item'][$link['from']],
                'target_item_id' => $this->ids['item'][$link['to']],
                'link_type' => $link['type'],
                'created_by' => $this->user->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $values  field ref => normalized value
     */
    private function insertFieldValues(Item $item, array $values): void
    {
        $rows = [];
        foreach ($values as $ref => $value) {
            $type = $this->fieldTypes[$ref] ?? null;
            if ($type === null) {
                continue;
            }
            $stored = match ($type) {
                'date' => $this->date($value),
                'select' => $this->ids['option'][$value] ?? null,
                default => $value,
            };
            if ($stored === null) {
                continue;
            }
            $rows[] = [
                ...array_fill_keys(array_values(ItemFieldValue::COLUMNS), null),
                ItemFieldValue::COLUMNS[$type] => $stored,
                'item_id' => $item->id,
                'item_field_id' => $this->ids['field'][$ref],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            ItemFieldValue::insert($rows);
        }
    }

    private function createPages(): void
    {
        foreach ($this->doc['pages'] as $template) {
            $isFolder = $template['type'] === 'folder';
            $isWhiteboard = ($template['content']['type'] ?? null) === 'excalidraw';
            $page = $this->project->allPages()->create([
                'name' => $template['name'],
                'type' => $template['type'],
                'parent_id' => $template['parent'] !== null ? ($this->ids['page'][$template['parent']] ?? null) : null,
                'icon' => $template['icon'] ?? ($isFolder ? '📁' : ($isWhiteboard ? '✏️' : '📄')),
                'content' => $isFolder ? null : $template['content'],
                'created_by' => $this->user->id,
                'last_edited_by' => $this->user->id,
            ]);
            $this->ids['page'][$template['ref']] = $page->id;

            // Published straight away (with a first version) so every project member can read it.
            if (! $isFolder) {
                $page->publish($this->user, 'Created from template');
            }
        }
    }

    private function createTaskLists(): void
    {
        $slugs = [];
        foreach ($this->doc['task_lists'] as $position => $template) {
            $list = TaskList::create([
                'project_id' => $this->project->id,
                'name' => $template['name'],
                'slug' => $this->unique(Str::slug($template['name']) ?: 'list', $slugs),
                'description' => $template['description'],
                'position' => $position,
                'created_by' => $this->user->id,
            ]);
            $list->watchers()->syncWithoutDetaching([$this->user->id]);

            foreach ($template['tasks'] as $index => $task) {
                $list->items()->create([
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'status' => 'todo',
                    'start_date' => $this->date($task['start']),
                    'start_time' => $task['start_time'],
                    'due_date' => $this->date($task['due']),
                    'due_time' => $task['due_time'],
                    'recurrence' => $task['recurrence'],
                    'reminder_minutes' => $task['reminder_minutes'],
                    'position' => $index,
                    'created_by' => $this->user->id,
                ]);
            }
        }
    }

    private function createPlans(): void
    {
        foreach ($this->doc['plans'] as $position => $template) {
            $data = PlanDocument::normalize([
                'rows' => array_map(fn ($row) => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'level' => $row['level'],
                    'status' => in_array($row['status'], PlanDocument::STATUSES, true) ? $row['status'] : null,
                    'notes' => $row['notes'],
                    'collapsed' => $row['collapsed'],
                    'itemId' => $row['item'] !== null ? ($this->ids['item'][$row['item']] ?? null) : null,
                    'bars' => array_map(fn ($bar) => [
                        'id' => $bar['id'],
                        'start' => $this->date($bar['start']),
                        'end' => $this->date($bar['end']),
                        'type' => $bar['type'],
                        'color' => $bar['color'] && strlen($bar['color']) === 7 ? $bar['color'] : '#0E83DC',
                        'label' => $bar['label'],
                        'progress' => $bar['progress'],
                    ], $row['bars']),
                ], $template['rows']),
                'dependencies' => $template['dependencies'],
            ]);

            $plan = Plan::create([
                'project_id' => $this->project->id,
                'name' => $template['name'],
                'slug' => Plan::uniqueSlug($this->project, $template['name']),
                'description' => $template['description'],
                'data' => $data,
                'settings' => $template['settings'] ?: null,
                'position' => $position + 1,
                'created_by' => $this->user->id,
                'last_edited_by' => $this->user->id,
            ]);
            $plan->recordVersion($this->user, 'Created from template');
        }
    }

    private function typeId(?string $ref): ?int
    {
        if ($ref === null) {
            return null;
        }
        if (str_starts_with($ref, 'slug:')) {
            $this->typeSlugs ??= ItemType::forProject($this->project)->whereNull('project_id')->pluck('id', 'slug')->all();

            return $this->typeSlugs[substr($ref, 5)] ?? null;
        }

        return $this->ids['type'][$ref] ?? null;
    }

    private function priorityId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }
        $this->prioritySlugs ??= ItemPriority::forProject($this->project)->pluck('id', 'slug')->all();

        return $this->prioritySlugs[$slug] ?? null;
    }

    /**
     * @param  array<string, true>  $taken
     */
    private function unique(string $base, array &$taken): string
    {
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = "{$base}-{$n}";
        }
        $taken[$slug] = true;

        return $slug;
    }
}
