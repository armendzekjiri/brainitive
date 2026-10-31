<?php

namespace App\Support\Templates;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemLink;
use App\Models\TaskListItem;

/**
 * The project template format (BRAI-72). A document never contains database ids: every
 * object has a local `ref` and references point at refs; dates are calendar-day offsets
 * from the template's day 0 (the new project's start date).
 *
 * format 1:
 *   project:     { description, disabled_type_slugs[] }
 *   item_types:  [{ ref, name, slug, color, icon, description }]           project-owned types
 *   labels:      [{ ref, name, color }]
 *   fields:      [{ ref, name, type, boards: [boardRef]|null, options: [{ ref, label, color }] }]
 *   boards:      [{ ref, name, description, type, done_retention_days (0|7|14|30|60|90, absent = new-board default),
 *                  columns: [{ ref, name, color, wip_limit, is_done }],
 *                  swimlanes: { mode, rest_name, lanes: [{ name, color, rules: { priorities[slug],
 *                  types[typeRef|"slug:x"], labels[labelRef], unassigned, no_release, overdue } }] } }]
 *   items:       [{ ref, board, column, parent, title, description, type, priority, labels[],
 *                  story_points, original_estimate, start, due, start_time, due_time, fields{} }]
 *   links:       [{ from, to, type }]
 *   pages:       [{ ref, parent, type, name, icon, content }]
 *   task_lists:  [{ name, description, tasks: [{ title, description, start, due, start_time,
 *                  due_time, recurrence, reminder_minutes }] }]
 *   plans:       [{ name, description, settings, rows: [{ id, name, level, status, notes,
 *                  collapsed, item, bars: [{ id, start, end, type, color, label, progress }] }],
 *                  dependencies: [{ id, from, to }] }]
 *
 * Type references: a ref of `item_types`, or "slug:<slug>" for a default type of the instance.
 */
class TemplateDocument
{
    public const FORMAT = 1;

    /** Sections a person can leave out when saving or using a template. */
    public const SECTIONS = ['issues', 'pages', 'task_lists', 'plans'];

    public const MAX_ITEMS = 500;

    public const MAX_PAGES = 200;

    public const MAX_TASK_LISTS = 50;

    public const MAX_TASKS = 500;

    public const MAX_PLANS = 20;

    public const MAX_BOARDS = 20;

    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Largest day offset (about 27 years) — keeps dates sane after shifting. */
    public const MAX_OFFSET = 10000;

    private const RECURRENCES = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Validate and repair a document (any supported format) into the current format.
     * Unknown keys are dropped, dangling refs removed, limits enforced.
     *
     * @throws TemplateException
     */
    public static function normalize(mixed $document): array
    {
        if (! is_array($document)) {
            throw new TemplateException('The template is not readable.');
        }

        $format = (int) ($document['format'] ?? 0);
        if ($format < 1) {
            throw new TemplateException('The template has no format version.');
        }
        if ($format > self::FORMAT) {
            throw new TemplateException('This template was made by a newer version of Brainitive.');
        }
        // Future formats upgrade here, step by step (format 1 → 2 → …).

        $doc = [
            'format' => self::FORMAT,
            'project' => [
                'description' => self::text($document['project']['description'] ?? null, 50000),
                'disabled_type_slugs' => self::strings($document['project']['disabled_type_slugs'] ?? [], 100),
            ],
            'item_types' => [],
            'labels' => [],
            'fields' => [],
            'boards' => [],
            'items' => [],
            'links' => [],
            'pages' => [],
            'task_lists' => [],
            'plans' => [],
        ];

        $typeRefs = [];
        foreach (self::list($document['item_types'] ?? [], 100) as $type) {
            $ref = self::ref($type['ref'] ?? null);
            $name = self::text($type['name'] ?? null, 100);
            if (! $ref || ! $name || isset($typeRefs[$ref])) {
                continue;
            }
            $typeRefs[$ref] = true;
            $doc['item_types'][] = [
                'ref' => $ref,
                'name' => $name,
                'slug' => self::text($type['slug'] ?? null, 100),
                'color' => self::color($type['color'] ?? null),
                // Lucide icon names only — uploaded icon files aren't part of a template.
                'icon' => self::iconName($type['icon'] ?? null),
                'description' => self::text($type['description'] ?? null, 500),
            ];
        }

        $labelRefs = [];
        foreach (self::list($document['labels'] ?? [], 200) as $label) {
            $ref = self::ref($label['ref'] ?? null);
            $name = self::text($label['name'] ?? null, 50);
            if (! $ref || ! $name || isset($labelRefs[$ref])) {
                continue;
            }
            $labelRefs[$ref] = true;
            $doc['labels'][] = ['ref' => $ref, 'name' => $name, 'color' => self::color($label['color'] ?? null) ?? '#64748b'];
        }

        // Boards first: fields and items point at them.
        $boardRefs = [];
        $columnBoard = [];
        foreach (self::list($document['boards'] ?? [], self::MAX_BOARDS, 'boards') as $board) {
            $ref = self::ref($board['ref'] ?? null);
            $name = self::text($board['name'] ?? null, 255);
            if (! $ref || ! $name || isset($boardRefs[$ref])) {
                continue;
            }
            $boardRefs[$ref] = true;
            $columns = [];
            foreach (self::list($board['columns'] ?? [], 30) as $column) {
                $columnRef = self::ref($column['ref'] ?? null);
                $columnName = self::text($column['name'] ?? null, 255);
                if (! $columnRef || ! $columnName || isset($columnBoard[$columnRef])) {
                    continue;
                }
                $columnBoard[$columnRef] = $ref;
                $columns[] = [
                    'ref' => $columnRef,
                    'name' => $columnName,
                    'color' => self::color($column['color'] ?? null),
                    'wip_limit' => self::int($column['wip_limit'] ?? null, 1, 999),
                    'is_done' => (bool) ($column['is_done'] ?? false),
                ];
            }
            $doc['boards'][] = [
                'ref' => $ref,
                'name' => $name,
                'description' => self::text($board['description'] ?? null, 5000),
                'type' => ($board['type'] ?? null) === 'scrum' ? 'scrum' : 'kanban',
                // BRAI-346; null = not in the template (the board gets the default for new boards)
                'done_retention_days' => in_array($board['done_retention_days'] ?? null, Board::DONE_RETENTION_OPTIONS, true) ? $board['done_retention_days'] : null,
                'columns' => $columns,
                'swimlanes' => self::swimlanes($board['swimlanes'] ?? null, $typeRefs, $labelRefs),
            ];
        }

        $fieldTypes = [];
        $optionRefs = [];
        foreach (self::list($document['fields'] ?? [], ItemField::MAX_FIELDS) as $field) {
            $ref = self::ref($field['ref'] ?? null);
            $name = self::text($field['name'] ?? null, 100);
            $type = $field['type'] ?? null;
            if (! $ref || ! $name || ! in_array($type, ItemField::TYPES, true) || isset($fieldTypes[$ref])) {
                continue;
            }
            $fieldTypes[$ref] = $type;
            $options = [];
            if ($type === 'select') {
                foreach (self::list($field['options'] ?? [], ItemField::MAX_OPTIONS) as $option) {
                    $optionRef = self::ref($option['ref'] ?? null);
                    $label = self::text($option['label'] ?? null, 100);
                    if (! $optionRef || ! $label || isset($optionRefs[$optionRef])) {
                        continue;
                    }
                    $optionRefs[$optionRef] = $ref;
                    $options[] = ['ref' => $optionRef, 'label' => $label, 'color' => self::color($option['color'] ?? null)];
                }
            }
            $boards = isset($field['boards']) && is_array($field['boards'])
                ? array_values(array_filter(self::strings($field['boards'], self::MAX_BOARDS), fn ($b) => isset($boardRefs[$b])))
                : null;
            $doc['fields'][] = [
                'ref' => $ref,
                'name' => $name,
                'type' => $type,
                // A field limited to boards that aren't in the template applies nowhere: keep it on all.
                'boards' => $boards === [] ? null : $boards,
                'options' => $options,
            ];
        }

        // Items: parents must come first and live on the same board.
        $itemBoard = [];
        foreach (self::list($document['items'] ?? [], self::MAX_ITEMS, 'issues') as $item) {
            $ref = self::ref($item['ref'] ?? null);
            $title = self::text($item['title'] ?? null, 255);
            $board = $item['board'] ?? null;
            $column = $item['column'] ?? null;
            if (! $ref || ! $title || isset($itemBoard[$ref]) || ! is_string($board) || ! isset($boardRefs[$board])) {
                continue;
            }
            $parent = $item['parent'] ?? null;
            $parent = is_string($parent) && ($itemBoard[$parent] ?? null) === $board ? $parent : null;

            $fields = [];
            foreach ((array) ($item['fields'] ?? []) as $fieldRef => $value) {
                $value = self::fieldValue($fieldTypes[$fieldRef] ?? null, $value, $optionRefs, (string) $fieldRef);
                if ($value !== null) {
                    $fields[$fieldRef] = $value;
                }
            }

            $itemBoard[$ref] = $board;
            $doc['items'][] = [
                'ref' => $ref,
                'board' => $board,
                'column' => is_string($column) && ($columnBoard[$column] ?? null) === $board ? $column : null,
                'parent' => $parent,
                'title' => $title,
                'description' => self::text($item['description'] ?? null, 200000),
                'type' => self::typeRef($item['type'] ?? null, $typeRefs),
                'priority' => self::text($item['priority'] ?? null, 100),
                'labels' => array_values(array_filter(self::strings($item['labels'] ?? [], 50), fn ($l) => isset($labelRefs[$l]))),
                'story_points' => self::int($item['story_points'] ?? null, 0, 1000),
                'original_estimate' => self::int($item['original_estimate'] ?? null, 1, 100000),
                'start' => self::offset($item['start'] ?? null),
                'due' => self::offset($item['due'] ?? null),
                'start_time' => self::time($item['start_time'] ?? null),
                'due_time' => self::time($item['due_time'] ?? null),
                'fields' => $fields,
            ];
        }
        self::limitNesting($doc['items']);

        $seenLinks = [];
        foreach (self::list($document['links'] ?? [], self::MAX_ITEMS * 4) as $link) {
            $from = $link['from'] ?? null;
            $to = $link['to'] ?? null;
            $type = $link['type'] ?? null;
            $key = "{$from}|{$to}|{$type}";
            if (is_string($from) && is_string($to) && is_string($type) && isset($itemBoard[$from], $itemBoard[$to])
                && $from !== $to && array_key_exists($type, ItemLink::LINK_TYPES) && ! isset($seenLinks[$key])) {
                $seenLinks[$key] = true;
                $doc['links'][] = ['from' => $from, 'to' => $to, 'type' => $type];
            }
        }

        $pageRefs = [];
        foreach (self::list($document['pages'] ?? [], self::MAX_PAGES, 'docs') as $page) {
            $ref = self::ref($page['ref'] ?? null);
            $name = self::text($page['name'] ?? null, 255);
            if (! $ref || ! $name || isset($pageRefs[$ref])) {
                continue;
            }
            $type = ($page['type'] ?? null) === 'folder' ? 'folder' : 'page';
            $parent = $page['parent'] ?? null;
            $pageRefs[$ref] = $type;
            $doc['pages'][] = [
                'ref' => $ref,
                // Parents come first: a parent that isn't listed yet makes the page top-level
                'parent' => is_string($parent) && isset($pageRefs[$parent]) && $parent !== $ref ? $parent : null,
                'type' => $type,
                'name' => $name,
                'icon' => self::text($page['icon'] ?? null, 255),
                'content' => $type === 'folder' ? null : (is_array($page['content'] ?? null) ? $page['content'] : ['type' => 'lexical', 'html' => '']),
            ];
        }

        foreach (self::list($document['task_lists'] ?? [], self::MAX_TASK_LISTS, 'task lists') as $list) {
            $name = self::text($list['name'] ?? null, 255);
            if (! $name) {
                continue;
            }
            $tasks = [];
            foreach (self::list($list['tasks'] ?? [], self::MAX_TASKS, 'tasks in a list') as $task) {
                $title = self::text($task['title'] ?? null, 255);
                if (! $title) {
                    continue;
                }
                $tasks[] = [
                    'title' => $title,
                    'description' => is_array($task['description'] ?? null) ? $task['description'] : null,
                    'start' => self::offset($task['start'] ?? null),
                    'due' => self::offset($task['due'] ?? null),
                    'start_time' => self::time($task['start_time'] ?? null),
                    'due_time' => self::time($task['due_time'] ?? null),
                    'recurrence' => in_array($task['recurrence'] ?? null, self::RECURRENCES, true) ? $task['recurrence'] : null,
                    'reminder_minutes' => in_array($task['reminder_minutes'] ?? null, TaskListItem::REMINDER_OPTIONS, true) ? $task['reminder_minutes'] : null,
                ];
            }
            $doc['task_lists'][] = ['name' => $name, 'description' => self::text($list['description'] ?? null, 5000), 'tasks' => $tasks];
        }

        foreach (self::list($document['plans'] ?? [], self::MAX_PLANS, 'plans') as $plan) {
            $name = self::text($plan['name'] ?? null, 255);
            if (! $name) {
                continue;
            }
            $rows = [];
            $linkedItems = [];
            $barIds = [];
            foreach (self::list($plan['rows'] ?? [], 2000) as $row) {
                $bars = [];
                foreach (self::list($row['bars'] ?? [], 50) as $bar) {
                    $start = self::offset($bar['start'] ?? null);
                    $id = self::ref($bar['id'] ?? null) ?? 'b-'.count($barIds);
                    if ($start === null || isset($barIds[$id])) {
                        continue;
                    }
                    $barIds[$id] = true;
                    $bars[] = [
                        'id' => $id,
                        'start' => $start,
                        'end' => max($start, self::offset($bar['end'] ?? null) ?? $start),
                        'type' => ($bar['type'] ?? null) === 'milestone' ? 'milestone' : 'bar',
                        'color' => self::color($bar['color'] ?? null),
                        'label' => self::text($bar['label'] ?? null, 255),
                        'progress' => self::int($bar['progress'] ?? null, 0, 100),
                    ];
                }
                $item = $row['item'] ?? null;
                $item = is_string($item) && isset($itemBoard[$item]) && ! isset($linkedItems[$item]) ? $item : null;
                if ($item) {
                    $linkedItems[$item] = true;
                }
                $rows[] = [
                    'id' => self::ref($row['id'] ?? null) ?? 'r-'.count($rows),
                    'name' => self::text($row['name'] ?? null, 255) ?? '',
                    'level' => self::int($row['level'] ?? null, 0, 8) ?? 0,
                    'status' => self::text($row['status'] ?? null, 40),
                    'notes' => self::text($row['notes'] ?? null, 5000),
                    'collapsed' => (bool) ($row['collapsed'] ?? false),
                    'item' => $item,
                    'bars' => $bars,
                ];
            }
            $dependencies = [];
            foreach (self::list($plan['dependencies'] ?? [], 2000) as $dependency) {
                $from = $dependency['from'] ?? null;
                $to = $dependency['to'] ?? null;
                if (is_string($from) && is_string($to) && isset($barIds[$from], $barIds[$to]) && $from !== $to) {
                    $dependencies[] = ['id' => self::ref($dependency['id'] ?? null) ?? 'd-'.count($dependencies), 'from' => $from, 'to' => $to];
                }
            }
            $doc['plans'][] = [
                'name' => $name,
                'description' => self::text($plan['description'] ?? null, 5000),
                'settings' => is_array($plan['settings'] ?? null) ? array_intersect_key($plan['settings'], array_flip(['zoom', 'columns', 'gridWidth', 'showWeekends'])) : [],
                'rows' => $rows,
                'dependencies' => $dependencies,
            ];
        }

        if (strlen((string) json_encode($doc)) > self::MAX_BYTES) {
            throw new TemplateException('This template is too large (over 8 MB). Leave out docs with big whiteboards or images.');
        }

        return $doc;
    }

    /**
     * Drop the sections a person chose to leave out. Leaving out issues also removes links
     * and the plan rows' issue links (the rows stay).
     *
     * @param  list<string>  $include
     */
    public static function only(array $doc, array $include): array
    {
        if (! in_array('issues', $include, true)) {
            $doc['items'] = [];
            $doc['links'] = [];
            foreach ($doc['plans'] as $p => $plan) {
                foreach ($plan['rows'] as $r => $row) {
                    $doc['plans'][$p]['rows'][$r]['item'] = null;
                }
            }
        }
        foreach (['pages', 'task_lists', 'plans'] as $section) {
            if (! in_array($section, $include, true)) {
                $doc[$section] = [];
            }
        }

        return $doc;
    }

    /**
     * What a template contains, for the picker and the preview.
     *
     * @return array<string, mixed>
     */
    public static function summary(array $doc): array
    {
        $itemsPerBoard = array_count_values(array_column($doc['items'], 'board'));
        $typeNames = array_column($doc['item_types'], 'name', 'ref');
        $typeName = fn (?string $ref) => $ref === null ? 'Task'
            : ($typeNames[$ref] ?? (str_starts_with($ref, 'slug:') ? ucfirst(str_replace(['-', '_'], ' ', substr($ref, 5))) : 'Task'));

        return [
            'boards' => array_map(fn ($board) => [
                'name' => $board['name'],
                'type' => $board['type'],
                'columns' => array_column($board['columns'], 'name'),
                'issues' => $itemsPerBoard[$board['ref']] ?? 0,
            ], $doc['boards']),
            'issues' => count($doc['items']),
            'docs' => count(array_filter($doc['pages'], fn ($p) => $p['type'] === 'page')),
            'folders' => count(array_filter($doc['pages'], fn ($p) => $p['type'] === 'folder')),
            'task_lists' => array_map(fn ($list) => ['name' => $list['name'], 'tasks' => count($list['tasks'])], $doc['task_lists']),
            'plans' => array_column($doc['plans'], 'name'),
            'labels' => count($doc['labels']),
            'fields' => array_column($doc['fields'], 'name'),
            'item_types' => array_column($doc['item_types'], 'name'),
            // Detail view of the template gallery
            'issue_types' => array_values(array_unique(array_map(fn ($item) => $typeName($item['type']), $doc['items']))),
            'sample_issues' => array_map(fn ($item) => ['title' => $item['title'], 'type' => $typeName($item['type']), 'subtask' => $item['parent'] !== null], array_slice($doc['items'], 0, 8)),
            'doc_names' => array_slice(array_column(array_filter($doc['pages'], fn ($p) => $p['type'] === 'page'), 'name'), 0, 10),
            'label_names' => array_slice(array_column($doc['labels'], 'name'), 0, 12),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value, int $max, ?string $label = null): array
    {
        if (! is_array($value)) {
            return [];
        }
        $rows = array_values(array_filter($value, 'is_array'));
        if (count($rows) > $max) {
            if ($label !== null) {
                throw new TemplateException("A template can hold at most {$max} {$label}.");
            }
            $rows = array_slice($rows, 0, $max);
        }

        return $rows;
    }

    private static function ref(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_:-]{1,40}$/', $value) ? $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, int $max): array
    {
        return is_array($value)
            ? array_slice(array_values(array_unique(array_filter($value, fn ($v) => is_string($v) && $v !== ''))), 0, $max)
            : [];
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }

    private static function offset(mixed $value): ?int
    {
        return is_numeric($value) && ! is_bool($value) ? max(-self::MAX_OFFSET, min(self::MAX_OFFSET, (int) $value)) : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $value) ? substr($value, 0, 5) : null;
    }

    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? strtolower($value) : null;
    }

    private static function iconName(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9-]{1,60}$/', $value) ? $value : null;
    }

    /**
     * @param  array<string, true>  $typeRefs
     */
    private static function typeRef(mixed $value, array $typeRefs): ?string
    {
        if (is_string($value) && isset($typeRefs[$value])) {
            return $value;
        }

        return is_string($value) && preg_match('/^slug:[a-z0-9_-]{1,100}$/', $value) ? $value : null;
    }

    /**
     * @param  array<string, string>  $optionRefs  option ref => field ref
     */
    private static function fieldValue(?string $type, mixed $value, array $optionRefs, string $fieldRef): mixed
    {
        return match ($type) {
            'text' => self::text($value, 1000),
            'number' => is_numeric($value) && ! is_bool($value) && abs((float) $value) < 1e14 ? round((float) $value, 6) : null,
            'date' => self::offset($value),
            'checkbox' => is_bool($value) ? $value : null,
            'select' => is_string($value) && ($optionRefs[$value] ?? null) === $fieldRef ? $value : null,
            default => null,
        };
    }

    /**
     * @param  array<string, true>  $typeRefs
     * @param  array<string, true>  $labelRefs
     */
    private static function swimlanes(mixed $value, array $typeRefs, array $labelRefs): ?array
    {
        if (! is_array($value) || ! in_array($value['mode'] ?? null, ['none', 'custom', 'assignee', 'priority', 'type', 'release'], true)) {
            return null;
        }
        $lanes = [];
        foreach (self::list($value['lanes'] ?? [], 20) as $lane) {
            $name = self::text($lane['name'] ?? null, 60);
            if (! $name) {
                continue;
            }
            $rules = is_array($lane['rules'] ?? null) ? $lane['rules'] : [];
            $lanes[] = [
                'name' => $name,
                'color' => self::color($lane['color'] ?? null),
                'rules' => [
                    'priorities' => self::strings($rules['priorities'] ?? [], 50),
                    'types' => array_values(array_filter(array_map(fn ($t) => self::typeRef($t, $typeRefs), self::strings($rules['types'] ?? [], 50)))),
                    'labels' => array_values(array_filter(self::strings($rules['labels'] ?? [], 100), fn ($l) => isset($labelRefs[$l]))),
                    'unassigned' => (bool) ($rules['unassigned'] ?? false),
                    'no_release' => (bool) ($rules['no_release'] ?? false),
                    'overdue' => (bool) ($rules['overdue'] ?? false),
                ],
            ];
        }

        return ['mode' => $value['mode'], 'rest_name' => self::text($value['rest_name'] ?? null, 60), 'lanes' => $lanes];
    }

    /**
     * Issues nest at most 3 levels (Item::MAX_DEPTH): deeper ones move up to the deepest allowed parent.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function limitNesting(array &$items): void
    {
        $parentOf = array_column($items, 'parent', 'ref');
        $depth = function (string $ref) use (&$parentOf): int {
            $d = 0;
            while (($parentOf[$ref] ?? null) !== null && $d < 10) {
                $ref = $parentOf[$ref];
                $d++;
            }

            return $d;
        };
        foreach ($items as $i => $item) {
            while ($items[$i]['parent'] !== null && $depth($item['ref']) > Item::MAX_DEPTH) {
                $items[$i]['parent'] = $parentOf[$items[$i]['parent']] ?? null;
                $parentOf[$item['ref']] = $items[$i]['parent'];
            }
        }
    }
}
