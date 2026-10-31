<?php

namespace App\Support\Imports\Archive;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\Board;
use App\Models\BoardView;
use App\Models\Comment;
use App\Models\GithubConnection;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\RecurringIssue;
use App\Models\Release;
use App\Models\TaskList;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Boards\ItemFieldValues;
use App\Support\Helpdesks\HelpdeskForm;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Writes one project as a portable archive (BRAI-81, format in ProjectArchive). Only what the
 * exporter may see (Page::visibleTo; everything else follows project access, which `update`
 * implies). Never: tokens, secrets, share links, integrations, memberships, tickets, contacts.
 */
final class ProjectArchiveWriter
{
    /** @var array<string, array<int|string, string>> kind => [id => ref] */
    private array $refs = [];

    /** @var array<string, string> path in the archive => sha256 */
    private array $files = [];

    /** @var array<int, array{name: string, email: string|null}> user id => person */
    private array $people = [];

    /** @var array<string, array{path: string, ref: string}> public-disk path => media file */
    private array $media = [];

    private int $fileCount = 0;

    private int $fileBytes = 0;

    /** @var array<string, int> what => count (content left out of this export) */
    private array $skipped = [];

    private ZipArchive $zip;

    /** @var Collection<int, string> */
    private Collection $globalTypeSlugs;

    /** @var Collection<int, string> */
    private Collection $globalPrioritySlugs;

    private function __construct(private readonly Project $project, private readonly User $exporter) {}

    /**
     * Write the archive to an absolute path. Returns the manifest counts.
     *
     * @return array<string, int>
     */
    public static function write(Project $project, User $exporter, string $zipPath): array
    {
        return (new self($project, $exporter))->run($zipPath);
    }

    private function run(string $zipPath): array
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }
        $this->globalTypeSlugs = ItemType::whereNull('project_id')->pluck('slug', 'id');
        $this->globalPrioritySlugs = ItemPriority::whereNull('project_id')->pluck('slug', 'id');

        try {
            // Refs first where later modules point at them (boards before fields, issues before pages / plans)
            $types = $this->itemTypes();
            $priorities = $this->priorities();
            $labels = $this->labels();
            $releases = $this->releases();
            $boards = Board::where('project_id', $this->project->id)->with(['columns' => fn ($q) => $q->orderBy('position'), 'sprints' => fn ($q) => $q->orderBy('position')->orderBy('id')])->orderBy('position')->orderBy('id')->get();
            $boardDocs = $boards->map(fn (Board $board) => $this->board($board))->values()->all();
            $fields = ItemField::where('project_id', $this->project->id)->with(['options' => fn ($q) => $q->orderBy('position')])->orderBy('position')->orderBy('id')->get()->keyBy('id');
            $fieldDocs = $fields->values()->map(fn (ItemField $field) => $this->field($field))->all();

            $items = $this->items($boards);
            $issueDocs = $items->map(fn (Item $item) => $this->item($item, $fields))->values()->all();
            $links = $this->links($items);
            $pages = $this->pages();
            $lists = $this->taskLists();
            $plans = $this->plans();
            $helpdesks = $this->helpdesks();

            $icon = $this->project->getAttributes()['icon'] ?? null;
            $this->json('project.json', [
                'name' => $this->project->name,
                'key' => $this->project->key,
                'description' => ArchiveContent::exportHtml($this->project->description, $this->mediaRef(...)),
                // emoji only: an uploaded icon is a file of this instance
                'icon' => is_string($icon) && $icon !== '' && ! str_contains($icon, '/') && mb_strlen($icon) <= 30 ? $icon : null,
                'color' => $this->project->color,
                'disabled_type_slugs' => $this->globalTypeSlugs->only($this->project->disabledItemTypeIds())->values()->all(),
                'item_types' => $types,
                'priorities' => $priorities,
                'labels' => $labels,
                'fields' => $fieldDocs,
            ]);
            $this->json('boards.json', ['boards' => $boardDocs, 'releases' => $releases]);
            $this->json('issues.json', ['issues' => $issueDocs, 'links' => $links]);
            $this->json('pages.json', ['pages' => $pages]);
            $this->json('task_lists.json', ['task_lists' => $lists]);
            $this->json('plans.json', ['plans' => $plans]);
            $this->json('helpdesks.json', ['helpdesks' => $helpdesks]);
            $this->addMediaFiles();
            // last: every module and file above may have added a person
            $this->json('people.json', ['people' => array_values(array_map(fn (array $p, int $id) => ['ref' => $this->refs['person'][$id], ...$p], $this->people, array_keys($this->people)))]);

            $counts = [
                'boards' => count($boardDocs),
                'columns' => array_sum(array_map(fn ($b) => count($b['columns']), $boardDocs)),
                'sprints' => array_sum(array_map(fn ($b) => count($b['sprints']), $boardDocs)),
                'releases' => count($releases),
                'issues' => count($issueDocs),
                'comments' => array_sum(array_map(fn ($i) => count($i['comments']), $issueDocs)),
                'attachments' => array_sum(array_map(fn ($i) => count($i['attachments']), $issueDocs)),
                'links' => count($links),
                'labels' => count($labels),
                'custom_fields' => count($fieldDocs),
                'pages' => count($pages),
                'task_lists' => count($lists),
                'tasks' => array_sum(array_map(fn ($l) => count($l['tasks']), $lists)),
                'plans' => count($plans),
                'helpdesks' => count($helpdesks),
                'media' => count($this->media),
                'people' => count($this->people),
            ];

            $manifest = [
                'format' => ProjectArchive::FORMAT,
                'version' => ProjectArchive::VERSION,
                'app_version' => ProjectArchive::appVersion(),
                'created_at' => now()->toIso8601String(),
                'project' => ['name' => $this->project->name, 'key' => $this->project->key],
                'counts' => $counts,
                'files' => $this->files,
                'omitted' => $this->omitted(),
            ];
            $this->zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $this->zip->close()) {
                throw new RuntimeException('Could not write the export file.');
            }

            return $counts;
        } catch (\Throwable $e) {
            @$this->zip->close();
            @unlink($zipPath);
            throw $e;
        }
    }

    // ── refs, people, files ──────────────────────────────────────────────────

    private function ref(string $kind, int|string $id): string
    {
        $this->refs[$kind] ??= [];

        return $this->refs[$kind][$id] ??= self::PREFIX[$kind].(count($this->refs[$kind]) + 1);
    }

    private const PREFIX = [
        'type' => 't', 'priority' => 'pr', 'label' => 'l', 'field' => 'f', 'option' => 'o', 'board' => 'b',
        'column' => 'c', 'sprint' => 's', 'release' => 'r', 'item' => 'i', 'comment' => 'cm', 'attachment' => 'a',
        'page' => 'p', 'list' => 'tl', 'task' => 'tk', 'plan' => 'pl', 'helpdesk' => 'h', 'hcolumn' => 'hc',
        'hfield' => 'hf', 'person' => 'u', 'media' => 'm',
    ];

    private function known(string $kind, ?int $id): ?string
    {
        return $id === null ? null : ($this->refs[$kind][$id] ?? null);
    }

    /** A person the content points at: name + email as metadata, never an account. */
    private function person(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        if (! isset($this->people[$userId])) {
            $user = User::find($userId, ['id', 'name', 'email']);
            if ($user === null) {
                return null;
            }
            $this->people[$userId] = ['name' => (string) $user->name, 'email' => $user->email];
        }

        return $this->ref('person', $userId);
    }

    private function typeRef(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->known('type', $id) ?? (isset($this->globalTypeSlugs[$id]) ? 'slug:'.$this->globalTypeSlugs[$id] : null);
    }

    private function priorityRef(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->known('priority', $id) ?? (isset($this->globalPrioritySlugs[$id]) ? 'slug:'.$this->globalPrioritySlugs[$id] : null);
    }

    private function json(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($json) > ProjectArchive::MAX_JSON_BYTES) {
            throw new ArchiveTooLarge('This project has too much content for one export ('.$path.' would be over '.intdiv(ProjectArchive::MAX_JSON_BYTES, 1024 * 1024).' MB).');
        }
        $this->files[$path] = hash('sha256', $json);
        $this->zip->addFromString($path, $json);
    }

    /** Add a file of the public disk, within the archive's count / size limits. */
    private function addFile(string $diskPath, string $archivePath, string $what): bool
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($diskPath)) {
            $this->skip($what.' (file missing on the server)');

            return false;
        }
        $size = (int) $disk->size($diskPath);
        if ($this->fileCount >= ProjectArchive::MAX_FILES || $size > ProjectArchive::MAX_FILE_BYTES || $this->fileBytes + $size > ProjectArchive::MAX_TOTAL_BYTES) {
            $this->skip($what.' (over the archive’s size limit)');

            return false;
        }
        $absolute = $disk->path($diskPath);
        $this->fileCount++;
        $this->fileBytes += $size;
        $this->files[$archivePath] = hash_file('sha256', $absolute);
        $this->zip->addFile($absolute, $archivePath);

        return true;
    }

    private function skip(string $what, int $count = 1): void
    {
        $this->skipped[$what] = ($this->skipped[$what] ?? 0) + $count;
    }

    /** media ref for an embedded file of the public disk, or null (left as a link). */
    private function mediaRef(string $diskPath): ?string
    {
        if (isset($this->media[$diskPath])) {
            return $this->media[$diskPath]['ref'];
        }
        if (! Storage::disk('public')->exists($diskPath)) {
            return null;
        }
        $ref = $this->ref('media', $diskPath);
        $this->media[$diskPath] = ['path' => $diskPath, 'ref' => $ref];

        return $ref;
    }

    private function addMediaFiles(): void
    {
        foreach ($this->media as $diskPath => $media) {
            $this->addFile($diskPath, 'media/'.$media['ref'].self::extension($diskPath), 'Embedded images and files');
        }
    }

    private static function extension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? '.'.$ext : '';
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface ? $value->toDateString() : substr((string) $value, 0, 10);
    }

    private static function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : ($value === null ? null : (string) $value);
    }

    // ── modules ──────────────────────────────────────────────────────────────

    private function itemTypes(): array
    {
        return ItemType::where('project_id', $this->project->id)->orderBy('id')->get()
            ->map(fn (ItemType $type) => [
                'ref' => $this->ref('type', $type->id),
                'name' => $type->name,
                'slug' => $type->slug,
                // Lucide icon names only (an uploaded icon is a file of this instance)
                'icon' => is_string($type->icon) && preg_match('/^[a-z0-9-]{1,50}$/i', $type->icon) ? $type->icon : null,
                'color' => $type->color,
                'description' => $type->description,
                'is_default' => (bool) $type->is_default,
                'is_active' => (bool) $type->is_active,
            ])->values()->all();
    }

    private function priorities(): array
    {
        return ItemPriority::where('project_id', $this->project->id)->orderBy('order')->orderBy('id')->get()
            ->map(fn (ItemPriority $p) => [
                'ref' => $this->ref('priority', $p->id),
                'name' => $p->name,
                'slug' => $p->slug,
                'icon' => $p->icon,
                'color' => $p->color,
                'order' => $p->order,
                'is_default' => (bool) $p->is_default,
            ])->values()->all();
    }

    private function labels(): array
    {
        return $this->project->labels()->orderBy('name')->orderBy('id')->get()
            ->map(fn ($label) => ['ref' => $this->ref('label', $label->id), 'name' => $label->name, 'color' => $label->color])->values()->all();
    }

    private function releases(): array
    {
        return Release::where('project_id', $this->project->id)->orderBy('id')->get()
            ->map(fn (Release $r) => [
                'ref' => $this->ref('release', $r->id),
                'name' => $r->name,
                'description' => $r->description,
                'status' => $r->status,
                'start_date' => self::date($r->start_date),
                'release_date' => self::date($r->release_date),
                'released_at' => self::time($r->released_at),
                'notes' => $r->notes, // the public token and published page are never exported
            ])->values()->all();
    }

    private function board(Board $board): array
    {
        $ref = $this->ref('board', $board->id);
        $columns = $board->columns->map(fn ($c) => [
            'ref' => $this->ref('column', $c->id),
            'name' => $c->name,
            'color' => $c->color,
            'position' => $c->position,
            'wip_limit' => $c->wip_limit,
            'is_done' => (bool) $c->is_done_column,
        ])->values()->all();
        $sprints = $board->sprints->map(fn ($s) => [
            'ref' => $this->ref('sprint', $s->id),
            'name' => $s->name,
            'goal' => $s->goal,
            'status' => $s->status,
            'start_date' => self::date($s->start_date),
            'end_date' => self::date($s->end_date),
            'started_at' => self::time($s->started_at),
            'completed_at' => self::time($s->completed_at),
            'position' => $s->position,
            'committed_points' => $s->committed_points,
            'committed_issues' => $s->committed_issues,
            'completed_points' => $s->completed_points,
            'completed_issues' => $s->completed_issues,
            'burndown' => $s->burndown,
        ])->values()->all();

        return [
            'ref' => $ref,
            'name' => $board->name,
            'slug' => $board->slug,
            'description' => $board->description,
            'type' => $board->type,
            'position' => $board->position,
            'done_retention_days' => $board->doneRetentionSetting(),
            'columns' => $columns,
            'swimlanes' => $this->swimlanes($board->settings['swimlanes'] ?? null),
            'sprints' => $sprints,
        ];
    }

    private function swimlanes(mixed $lanes): ?array
    {
        if (! is_array($lanes)) {
            return null;
        }

        return [
            'mode' => $lanes['mode'] ?? 'none',
            'rest_name' => $lanes['rest_name'] ?? null,
            'lanes' => array_values(array_map(function ($lane) {
                $rules = (array) ($lane['rules'] ?? []);
                $map = fn (string $key, callable $fn) => array_values(array_filter(array_map(fn ($id) => (int) $id === 0 ? 0 : $fn((int) $id), (array) ($rules[$key] ?? [])), fn ($v) => $v !== null));

                return [
                    'name' => (string) ($lane['name'] ?? ''),
                    'color' => $lane['color'] ?? null,
                    'rules' => [
                        'priorities' => $map('priority_ids', fn ($id) => $this->priorityRef($id)),
                        'types' => $map('type_ids', fn ($id) => $this->typeRef($id)),
                        'labels' => $map('label_ids', fn ($id) => $this->known('label', $id)),
                        'assignees' => $map('assignee_ids', fn ($id) => $this->person($id)),
                        'releases' => $map('release_ids', fn ($id) => $this->known('release', $id)),
                        'overdue' => ! empty($rules['overdue']),
                    ],
                ];
            }, is_array($lanes['lanes'] ?? null) ? $lanes['lanes'] : [])),
        ];
    }

    private function field(ItemField $field): array
    {
        return [
            'ref' => $this->ref('field', $field->id),
            'name' => $field->name,
            'type' => $field->type,
            'boards' => $field->board_ids === null ? null : array_values(array_filter(array_map(fn ($id) => $this->known('board', (int) $id), $field->board_ids))),
            'position' => $field->position,
            'archived' => $field->archived_at !== null,
            'options' => $field->options->map(fn ($o) => [
                'ref' => $this->ref('option', $o->id),
                'label' => $o->label,
                'color' => $o->color,
                'position' => $o->position,
                'archived' => $o->archived_at !== null,
            ])->values()->all(),
        ];
    }

    /** Issues of the project's boards, parents before their subtasks. */
    private function items(Collection $boards): Collection
    {
        $query = Item::whereIn('board_id', $boards->pluck('id'));
        if ((clone $query)->count() > ProjectArchive::MAX_ISSUES) {
            throw new ArchiveTooLarge('This project has more than '.number_format(ProjectArchive::MAX_ISSUES).' issues, too many for one export.');
        }
        $items = $query->with(['labels:id', 'fieldValues', 'comments' => fn ($q) => $q->orderBy('id'), 'attachments' => fn ($q) => $q->orderBy('id')])
            ->orderBy('board_id')->orderBy('position')->orderBy('id')->get();

        $byId = $items->keyBy('id');
        $depth = function (Item $item) use ($byId): int {
            $d = 0;
            for ($parent = $item->parent_id; $parent !== null && isset($byId[$parent]) && $d < 10; $parent = $byId[$parent]->parent_id) {
                $d++;
            }

            return $d;
        };
        $sorted = $items->sortBy(fn (Item $item) => $depth($item))->values();
        $sorted->each(fn (Item $item) => $this->ref('item', $item->id));

        return $sorted;
    }

    private function item(Item $item, Collection $fields): array
    {
        $values = [];
        foreach ($item->fieldValues as $row) {
            $field = $fields[$row->item_field_id] ?? null;
            $ref = $this->known('field', $row->item_field_id);
            if (! $field || ! $ref) {
                continue;
            }
            $value = ItemFieldValues::valueOf($field, $row);
            $values[$ref] = $field->type === 'select' ? $this->known('option', $value === null ? null : (int) $value) : $value;
        }

        return [
            'ref' => $this->refs['item'][$item->id],
            'key' => $item->key, // the old key, shown nowhere: the restore gets new keys
            'board' => $this->known('board', $item->board_id),
            'column' => $this->known('column', $item->column_id),
            'parent' => $this->known('item', $item->parent_id),
            'sprint' => $this->known('sprint', $item->sprint_id),
            'release' => $this->known('release', $item->release_id),
            'type' => $this->typeRef($item->item_type_id),
            'priority' => $this->priorityRef($item->priority_id),
            'title' => $item->title,
            'description' => ArchiveContent::exportHtml($item->description, $this->mediaRef(...)),
            'labels' => $item->labels->map(fn ($l) => $this->known('label', $l->id))->filter()->values()->all(),
            'assignee' => $this->person($item->assigned_to),
            'reporter' => $this->person($item->reporter_id),
            'creator' => $this->person($item->created_by),
            'position' => $item->position,
            'backlog_position' => $item->backlog_position,
            'story_points' => $item->story_points,
            'original_estimate' => $item->original_estimate,
            'start_date' => self::date($item->start_date),
            'start_time' => $item->start_time,
            'due_date' => self::date($item->due_date),
            'due_time' => $item->due_time,
            'completed_at' => self::time($item->completed_at),
            'created_at' => self::time($item->created_at),
            'fields' => $values,
            'comments' => $item->comments->map(fn (Comment $c) => [
                'ref' => $this->ref('comment', $c->id),
                'parent' => $this->known('comment', $c->parent_id),
                'author' => $this->person($c->user_id),
                'body' => ArchiveContent::exportHtml($c->body, $this->mediaRef(...)),
                'created_at' => self::time($c->created_at),
            ])->values()->all(),
            'attachments' => $item->attachments->map(function ($a) {
                $ref = $this->ref('attachment', $a->id);
                $file = 'attachments/'.$ref.self::extension((string) $a->path);

                return $this->addFile((string) $a->path, $file, 'Attachments') ? [
                    'ref' => $ref,
                    'file' => $file,
                    'name' => $a->original_filename,
                    'mime' => $a->mime_type,
                    'size' => $a->size,
                    'uploader' => $this->person($a->user_id),
                    'created_at' => self::time($a->created_at),
                ] : null;
            })->filter()->values()->all(),
        ];
    }

    private function links(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $ids = $items->pluck('id');

        return ItemLink::whereIn('source_item_id', $ids)->whereIn('target_item_id', $ids)->orderBy('id')->get()
            ->map(fn (ItemLink $link) => [
                'from' => $this->refs['item'][$link->source_item_id],
                'to' => $this->refs['item'][$link->target_item_id],
                'type' => $link->link_type,
            ])->values()->all();
    }

    /** Folders, docs and whiteboards the exporter can see, parents first; live content only. */
    private function pages(): array
    {
        $all = Page::where('project_id', $this->project->id)->visibleTo($this->exporter)
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'parent_id', 'type', 'status', 'name', 'icon', 'position', 'created_at', 'published_at']);
        if ($all->count() > ProjectArchive::MAX_PAGES) {
            throw new ArchiveTooLarge('This project has more than '.number_format(ProjectArchive::MAX_PAGES).' docs, too many for one export.');
        }
        $byId = $all->keyBy('id');
        // A page under one the exporter can't see moves up to the nearest visible ancestor
        $parentOf = function (Page $page) use ($byId): ?int {
            for ($parent = $page->parent_id, $guard = 0; $parent !== null && $guard < 50; $guard++) {
                if (isset($byId[$parent])) {
                    return $parent;
                }
                $parent = Page::whereKey($parent)->value('parent_id');
            }

            return null;
        };
        $children = $all->groupBy(fn (Page $page) => $parentOf($page) ?? 0);

        $result = [];
        $queue = ($children[0] ?? collect())->values()->all();
        while ($queue !== []) {
            /** @var Page $page */
            $page = array_shift($queue);
            $content = $page->type === 'folder' ? null : Page::whereKey($page->id)->value('content');
            $content = is_string($content) ? json_decode($content, true) : $content;
            $result[] = [
                'ref' => $this->ref('page', $page->id),
                'parent' => $this->known('page', $parentOf($page)),
                'type' => $page->type,
                'status' => $page->status,
                'name' => $page->name,
                'icon' => is_string($page->icon) && mb_strlen($page->icon) <= 255 && ! str_contains($page->icon, '/') ? $page->icon : null,
                'position' => $page->position,
                'content' => $page->type === 'folder' ? null : ArchiveContent::exportPage($content, $this->mediaRef(...), $this->refs['item'] ?? []),
                'created_at' => self::time($page->created_at),
                'published_at' => self::time($page->published_at),
            ];
            foreach ($children[$page->id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $result;
    }

    private function taskLists(): array
    {
        $lists = TaskList::where('project_id', $this->project->id)->with(['items' => fn ($q) => $q->orderBy('position')->orderBy('id')])->orderBy('position')->orderBy('id')->get();
        if ($lists->sum(fn ($l) => $l->items->count()) > ProjectArchive::MAX_TASKS) {
            throw new ArchiveTooLarge('This project has more than '.number_format(ProjectArchive::MAX_TASKS).' tasks, too many for one export.');
        }

        return $lists->map(fn (TaskList $list) => [
            'ref' => $this->ref('list', $list->id),
            'name' => $list->name,
            'description' => $list->description,
            'position' => $list->position,
            'tasks' => $list->items->map(fn ($task) => [
                'ref' => $this->ref('task', $task->id),
                'title' => $task->title,
                'description' => ArchiveContent::exportJson($task->description, $this->mediaRef(...)),
                'status' => $task->status,
                'assignee' => $this->person($task->assignee_id),
                'start_date' => self::date($task->start_date),
                'start_time' => $task->start_time,
                'due_date' => self::date($task->due_date),
                'due_time' => $task->due_time,
                'recurrence' => $task->recurrence,
                'reminder_minutes' => $task->reminder_minutes,
                'position' => $task->position,
                'created_at' => self::time($task->created_at),
            ])->values()->all(),
        ])->values()->all();
    }

    private function plans(): array
    {
        return Plan::where('project_id', $this->project->id)->orderBy('position')->orderBy('id')->get()
            ->map(function (Plan $plan) {
                $data = $plan->data ?? [];
                $rows = array_map(function ($row) {
                    $itemId = isset($row['itemId']) ? (int) $row['itemId'] : null;
                    unset($row['itemId']);
                    $row['item'] = $this->known('item', $itemId);

                    return $row;
                }, is_array($data['rows'] ?? null) ? $data['rows'] : []);

                return [
                    'ref' => $this->ref('plan', $plan->id),
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'settings' => $plan->settings ?? [], // the share token is a column, never exported
                    'position' => $plan->position,
                    'data' => ['rows' => $rows, 'dependencies' => $data['dependencies'] ?? []],
                ];
            })->values()->all();
    }

    private function helpdesks(): array
    {
        return $this->project->helpdesks()->with(['columns' => fn ($q) => $q->orderBy('position'), 'fields' => fn ($q) => $q->orderBy('position')])->orderBy('position')->orderBy('id')->get()
            ->map(fn ($helpdesk) => [
                'ref' => $this->ref('helpdesk', $helpdesk->id),
                'name' => $helpdesk->name,
                'description' => $helpdesk->description,
                'position' => $helpdesk->position,
                // form texts and look only; public slug, forwarding address, mailboxes and SLAs stay here
                'settings' => array_intersect_key($helpdesk->settings ?? [], array_flip(HelpdeskForm::SETTING_KEYS)),
                'columns' => $helpdesk->columns->map(fn ($c) => [
                    'ref' => $this->ref('hcolumn', $c->id),
                    'name' => $c->name,
                    'color' => $c->color,
                    'position' => $c->position,
                    'wip_limit' => $c->wip_limit,
                    'is_done' => (bool) $c->is_done_column,
                ])->values()->all(),
                'fields' => $helpdesk->fields->map(fn ($f) => [
                    'ref' => $this->ref('hfield', $f->id),
                    'name' => $f->name,
                    'label' => $f->label,
                    'type' => $f->type,
                    'options' => $f->options,
                    'is_required' => (bool) $f->is_required,
                    'position' => $f->position,
                    'is_visible_on_card' => (bool) $f->is_visible_on_card,
                ])->values()->all(),
            ])->values()->all();
    }

    /** What isn't in the archive, with counts where they are cheap to know. */
    private function omitted(): array
    {
        $projectId = $this->project->id;
        $itemIds = Item::where('project_id', $projectId)->select('id');
        $boardIds = Board::where('project_id', $projectId)->select('id');
        $counts = [
            'Helpdesk tickets and contacts' => Ticket::whereIn('helpdesk_id', $this->project->helpdesks()->select('id'))->count(),
            'Lead forms and leads' => $this->project->leadForms()->count(),
            'Time tracking and billing' => TimeEntry::where('project_id', $projectId)->count(),
            'History, watchers and page versions' => ActivityLog::where('loggable_type', Item::class)->whereIn('loggable_id', $itemIds)->count(),
            'Integrations, webhooks and automations' => AutomationRule::where('project_id', $projectId)->count() + GithubConnection::where('project_id', $projectId)->count(),
            'Saved board views, recurring issues and sticky notes' => BoardView::whereIn('board_id', $boardIds)->count() + RecurringIssue::whereIn('board_id', $boardIds)->count(),
        ];

        $omitted = [];
        foreach (ProjectArchive::NEVER as $what => $why) {
            $omitted[] = ['what' => $what, 'count' => $counts[$what] ?? null, 'why' => $why];
        }
        foreach ($this->skipped as $what => $count) {
            $omitted[] = ['what' => $what, 'count' => $count, 'why' => 'Left out of this export.'];
        }

        return $omitted;
    }
}
