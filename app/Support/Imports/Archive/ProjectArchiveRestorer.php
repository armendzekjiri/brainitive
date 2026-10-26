<?php

namespace App\Support\Imports\Archive;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\HelpdeskField;
use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldOption;
use App\Models\ItemFieldValue;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Boards\Swimlanes;
use App\Support\Helpdesks\HelpdeskForm;
use App\Support\Imports\ImportMappings;
use App\Support\Imports\ImportProgress;
use App\Support\Plans\PlanDocument;
use App\Support\Templates\TemplateInstantiator;
use App\Support\Uploads;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates the new project from a verified archive (BRAI-81). Every record goes through
 * ImportMappings::once with its archive ref as the source id, so a retried run finds what it
 * already made. References (columns, parents, sprints, links, page tree, whiteboard issue cards,
 * plan rows, embedded media) are rewritten to the new ids as records are created.
 *
 * People: a person maps to a member of the target workspace (the preview's choice, else the same
 * email) or to nobody. Mapped people are added to the new project as editors; nobody is invited and
 * no account is created. Comments are written by the restoring user, with "Originally by …".
 */
final class ProjectArchiveRestorer
{
    private Project $project;

    /** @var array<string, int|null> person ref => user id */
    private array $people = [];

    /** @var array<string, string> person ref => name */
    private array $names = [];

    /** @var array<string, string> media ref => URL */
    private array $mediaUrls = [];

    /** @var array<string, int>|null */
    private ?array $globalTypes = null;

    /** @var array<string, int>|null */
    private ?array $globalPriorities = null;

    public function __construct(
        private readonly ImportRun $run,
        private readonly ProjectArchiveReader $archive,
        private readonly ImportProgress $progress,
        private readonly User $user,
        private readonly Workspace $workspace,
        private readonly array $options,
    ) {}

    public function run(): array
    {
        $counts = $this->archive->counts();
        $this->progress->total(1 + $counts['boards'] + $counts['issues'] + $counts['comments'] + $counts['attachments'] + $counts['pages'] + $counts['tasks'] + $counts['plans'] + $counts['helpdesks']);

        $this->mapPeople();
        $this->createProject();
        $this->restoreMedia();
        $this->createTypesPrioritiesLabels();
        $this->createBoards();
        $this->createFields();
        $this->createReleasesAndSprints();
        $this->createSwimlanes();
        $this->createIssues();
        $this->createPages();
        $this->createTaskLists();
        $this->createPlans();
        $this->createHelpdesks();

        // Counted from the mappings, so a run that crashed and resumed still reports everything once
        $labels = ['project' => 'project', 'board' => 'boards', 'column' => 'columns', 'sprint' => 'sprints', 'release' => 'releases', 'item' => 'issues', 'comment' => 'comments', 'attachment' => 'attachments', 'link' => 'issue links', 'page' => 'pages', 'list' => 'task lists', 'task' => 'tasks', 'plan' => 'plans', 'helpdesk' => 'helpdesks'];
        $counts = ImportMapping::query()->where('import_run_id', $this->run->id)->whereIn('source_type', array_keys($labels))
            ->selectRaw('source_type, count(*) as total')->groupBy('source_type')->pluck('total', 'source_type');
        foreach ($labels as $type => $label) {
            if (($counts[$type] ?? 0) > 0) {
                $this->progress->created($label, (int) $counts[$type]);
            }
        }

        foreach ($this->archive->manifest['omitted'] ?? [] as $omitted) {
            if (is_array($omitted) && is_int($omitted['count'] ?? null) && $omitted['count'] > 0 && is_string($omitted['what'] ?? null)) {
                $this->progress->unsupported(Str::limit($omitted['what'], 120), $omitted['count']);
            }
        }

        return [
            'project_id' => $this->project->id,
            'url' => route('projects.show', [$this->workspace->slug, $this->project->slug]),
        ];
    }

    // ── people ───────────────────────────────────────────────────────────────

    private function mapPeople(): void
    {
        $members = $this->workspace->users()->get(['users.id', 'users.email']);
        $memberIds = $members->pluck('id')->flip();
        $byEmail = $members->filter(fn ($u) => $u->email)->keyBy(fn ($u) => Str::lower($u->email));
        $chosen = is_array($this->options['people'] ?? null) ? $this->options['people'] : [];

        foreach ($this->archive->list('people.json', 'people') as $person) {
            $ref = (string) ($person['ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $this->names[$ref] = Str::limit(trim((string) ($person['name'] ?? '')) ?: 'someone', 120);
            if (array_key_exists($ref, $chosen)) {
                $id = $chosen[$ref] === null ? null : (int) $chosen[$ref];
            } else {
                $email = is_string($person['email'] ?? null) ? Str::lower($person['email']) : null;
                $id = $email ? $byEmail->get($email)?->id : null;
            }
            $this->people[$ref] = $id !== null && isset($memberIds[$id]) ? $id : null;
        }
    }

    private function userFor(mixed $ref): ?int
    {
        return is_string($ref) ? ($this->people[$ref] ?? null) : null;
    }

    // ── project ──────────────────────────────────────────────────────────────

    private function createProject(): void
    {
        $doc = $this->archive->module('project.json');
        $name = Str::limit(trim((string) ($this->options['name'] ?? '')) ?: (string) ($doc['name'] ?? 'Restored project'), 255, '');
        $key = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($this->options['key'] ?? $doc['key'] ?? '')) ?: 'PRJ', 0, 10));

        $this->project = ImportMappings::once($this->run, 'project', 'project', Project::class, function () use ($doc, $name, $key) {
            $disabled = ItemType::whereNull('project_id')->whereIn('slug', self::strings($doc['disabled_type_slugs'] ?? [], 100))->pluck('id')->all();
            $project = $this->workspace->projects()->create([
                'name' => $name,
                'slug' => TemplateInstantiator::freeSlug($this->workspace, $name),
                'key' => $key,
                'description' => ArchiveContent::restoreHtml($doc['description'] ?? null, []),
                'icon' => self::text($doc['icon'] ?? null, 30),
                'color' => self::color($doc['color'] ?? null),
                'created_by' => $this->user->id,
                'settings' => $disabled ? ['disabled_item_types' => $disabled] : null,
                'next_item_number' => 0, // the counter holds the last number used → KEY-1 first
            ]);
            $project->addUser($this->user, 'admin');

            return $project;
        });
        if ($this->project->wasRecentlyCreated) {
            $this->progress->advance();
        }

        // Mapped people need access to be assignees (never an invitation: they are workspace members)
        foreach (array_unique(array_filter($this->people)) as $userId) {
            if ($userId !== $this->user->id && ! $this->project->users()->where('users.id', $userId)->exists()) {
                $this->project->users()->attach($userId, ['role' => 'editor', 'assigned_by' => $this->user->id]);
            }
        }
    }

    /** Embedded images and files: copied to the public disk under names derived from the run (idempotent). */
    private function restoreMedia(): void
    {
        $disk = Storage::disk('public');
        foreach ($this->archive->mediaFiles() as $ref => $archivePath) {
            $source = $this->archive->file($archivePath);
            if ($source === null) {
                continue;
            }
            $file = new UploadedFile($source, basename($archivePath), null, null, true);
            $image = Uploads::imageExtension($file);
            $target = $image !== 'bin'
                ? 'uploads/images/'.$this->run->uuid.'-'.$ref.'.'.$image
                : 'uploads/documents/'.$this->run->uuid.'-'.$ref.'.'.Uploads::extension($file);
            if (! $disk->exists($target)) {
                $disk->putFileAs(dirname($target), $file, basename($target));
            }
            $this->mediaUrls[$ref] = '/storage/'.$target;
        }
    }

    private function createTypesPrioritiesLabels(): void
    {
        $doc = $this->archive->module('project.json');
        foreach (self::items($doc['item_types'] ?? []) as $type) {
            ImportMappings::once($this->run, 'type', self::ref($type), ItemType::class, fn () => $this->project->itemTypes()->create([
                'name' => self::text($type['name'] ?? null, 100) ?? 'Type',
                'slug' => Str::slug((string) ($type['slug'] ?? $type['name'] ?? 'type')) ?: 'type',
                'icon' => is_string($type['icon'] ?? null) && preg_match('/^[a-z0-9-]{1,50}$/i', $type['icon']) ? $type['icon'] : null,
                'color' => self::color($type['color'] ?? null),
                'description' => self::text($type['description'] ?? null, 1000),
                'is_default' => (bool) ($type['is_default'] ?? false),
                'is_active' => (bool) ($type['is_active'] ?? true),
            ]));
        }
        foreach (self::items($doc['priorities'] ?? []) as $priority) {
            ImportMappings::once($this->run, 'priority', self::ref($priority), ItemPriority::class, fn () => ItemPriority::create([
                'project_id' => $this->project->id,
                'name' => self::text($priority['name'] ?? null, 100) ?? 'Priority',
                'slug' => Str::slug((string) ($priority['slug'] ?? $priority['name'] ?? '')) ?: null,
                'icon' => self::text($priority['icon'] ?? null, 50),
                'color' => self::color($priority['color'] ?? null),
                'order' => self::int($priority['order'] ?? 0) ?? 0,
                'is_default' => (bool) ($priority['is_default'] ?? false),
            ]));
        }
        foreach (self::items($doc['labels'] ?? []) as $label) {
            $name = self::text($label['name'] ?? null, 50) ?? 'Label';
            ImportMappings::once($this->run, 'label', self::ref($label), Label::class, fn () => $this->project->labels()->where('slug', Str::slug($name))->first()
                ?? $this->project->labels()->create(['name' => $name, 'color' => self::color($label['color'] ?? null) ?? '#64748b']));
        }
    }

    private function createBoards(): void
    {
        foreach ($this->archive->list('boards.json', 'boards') as $position => $doc) {
            $columns = self::items($doc['columns'] ?? []);
            $board = ImportMappings::once($this->run, 'board', self::ref($doc), Board::class, function () use ($doc, $position, $columns) {
                $name = self::text($doc['name'] ?? null, 255) ?? 'Board';
                $board = $this->project->boards()->create([
                    'name' => $name,
                    'slug' => self::freeSlug(Board::withTrashed()->where('project_id', $this->project->id), (string) ($doc['slug'] ?? $name), 'board'),
                    'description' => self::text($doc['description'] ?? null, 5000),
                    'type' => in_array($doc['type'] ?? null, ['kanban', 'scrum'], true) ? $doc['type'] : 'kanban',
                    'position' => self::int($doc['position'] ?? null) ?? $position,
                    'created_by' => $this->user->id,
                ]);
                if ($columns !== []) {
                    $board->columns()->delete(); // Board::created added the defaults; the archive brings its own
                }

                return $board;
            });
            if ($board->wasRecentlyCreated) {
                $this->progress->advance();
            }
            foreach ($columns as $index => $column) {
                ImportMappings::once($this->run, 'column', self::ref($column), BoardColumn::class, function () use ($board, $column, $index) {
                    $name = self::text($column['name'] ?? null, 255) ?? 'Column';

                    return $board->columns()->create([
                        'name' => $name,
                        'slug' => self::freeSlug(BoardColumn::where('board_id', $board->id), $name, 'column'),
                        'color' => self::color($column['color'] ?? null) ?? '#6b7280',
                        'position' => self::int($column['position'] ?? null) ?? $index,
                        'wip_limit' => self::int($column['wip_limit'] ?? null),
                        'is_done_column' => (bool) ($column['is_done'] ?? false),
                    ]);
                });
            }
        }
    }

    private function createFields(): void
    {
        $boards = ImportMappings::map($this->run, 'board');
        foreach (self::items($this->archive->module('project.json')['fields'] ?? []) as $index => $doc) {
            if (! in_array($doc['type'] ?? null, ItemField::TYPES, true)) {
                $this->progress->warn('A custom field with an unknown type was skipped.');

                continue;
            }
            $field = ImportMappings::once($this->run, 'field', self::ref($doc), ItemField::class, fn () => $this->project->itemFields()->create([
                'name' => self::text($doc['name'] ?? null, 100) ?? 'Field',
                'type' => $doc['type'],
                'board_ids' => is_array($doc['boards'] ?? null) ? array_values(array_filter(array_map(fn ($ref) => $boards[(string) $ref] ?? null, $doc['boards']))) : null,
                'position' => self::int($doc['position'] ?? null) ?? $index + 1,
                'archived_at' => ! empty($doc['archived']) ? now() : null,
            ]));
            foreach (self::items($doc['options'] ?? []) as $o => $option) {
                ImportMappings::once($this->run, 'option', self::ref($option), ItemFieldOption::class, fn () => $field->options()->create([
                    'label' => self::text($option['label'] ?? null, 100) ?? 'Option',
                    'color' => self::color($option['color'] ?? null),
                    'position' => self::int($option['position'] ?? null) ?? $o + 1,
                    'archived_at' => ! empty($option['archived']) ? now() : null,
                ]));
            }
        }
    }

    private function createReleasesAndSprints(): void
    {
        foreach ($this->archive->list('boards.json', 'releases') as $doc) {
            ImportMappings::once($this->run, 'release', self::ref($doc), Release::class, function () use ($doc) {
                $name = self::text($doc['name'] ?? null, 255) ?? 'Release';
                for ($n = 2, $unique = $name; Release::where('project_id', $this->project->id)->where('name', $unique)->exists(); $n++) {
                    $unique = $name.' ('.$n.')';
                }

                return Release::create([
                    'project_id' => $this->project->id,
                    'name' => $unique,
                    'description' => self::text($doc['description'] ?? null, 5000),
                    'status' => in_array($doc['status'] ?? null, ['unreleased', 'released', 'archived'], true) ? $doc['status'] : 'unreleased',
                    'start_date' => self::date($doc['start_date'] ?? null),
                    'release_date' => self::date($doc['release_date'] ?? null),
                    'released_at' => self::datetime($doc['released_at'] ?? null),
                    'notes' => self::text($doc['notes'] ?? null, 100000),
                    'created_by' => $this->user->id,
                ]);
            });
        }

        $boards = ImportMappings::map($this->run, 'board');
        foreach ($this->archive->list('boards.json', 'boards') as $board) {
            $boardId = $boards[self::ref($board)] ?? null;
            foreach ($boardId ? self::items($board['sprints'] ?? []) : [] as $index => $doc) {
                ImportMappings::once($this->run, 'sprint', self::ref($doc), Sprint::class, fn () => Sprint::create([
                    'board_id' => $boardId,
                    'name' => self::text($doc['name'] ?? null, 255) ?? 'Sprint',
                    'goal' => self::text($doc['goal'] ?? null, 5000),
                    'status' => in_array($doc['status'] ?? null, [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE, Sprint::STATUS_COMPLETED], true) ? $doc['status'] : Sprint::STATUS_PLANNED,
                    'start_date' => self::date($doc['start_date'] ?? null),
                    'end_date' => self::date($doc['end_date'] ?? null),
                    'started_at' => self::datetime($doc['started_at'] ?? null),
                    'completed_at' => self::datetime($doc['completed_at'] ?? null),
                    'position' => self::int($doc['position'] ?? null) ?? $index,
                    'committed_points' => self::int($doc['committed_points'] ?? null),
                    'committed_issues' => self::int($doc['committed_issues'] ?? null),
                    'completed_points' => self::int($doc['completed_points'] ?? null),
                    'completed_issues' => self::int($doc['completed_issues'] ?? null),
                    'burndown' => is_array($doc['burndown'] ?? null) ? $doc['burndown'] : null,
                    'created_by' => $this->user->id,
                ]));
            }
        }
    }

    private function createSwimlanes(): void
    {
        $boards = ImportMappings::map($this->run, 'board');
        $labels = ImportMappings::map($this->run, 'label');
        $releases = ImportMappings::map($this->run, 'release');
        foreach ($this->archive->list('boards.json', 'boards') as $doc) {
            $lanes = $doc['swimlanes'] ?? null;
            $board = isset($boards[self::ref($doc)]) ? Board::with('project.workspace')->find($boards[self::ref($doc)]) : null;
            if (! is_array($lanes) || $board === null || ! in_array($lanes['mode'] ?? null, Swimlanes::MODES, true)) {
                continue;
            }
            $refs = fn (mixed $list, callable $fn) => array_values(array_filter(array_map(fn ($v) => $v === 0 ? 0 : $fn($v), is_array($list) ? $list : []), fn ($v) => $v !== null));
            $input = [
                'mode' => $lanes['mode'],
                'rest_name' => self::text($lanes['rest_name'] ?? null, 60),
                'lanes' => array_map(fn ($lane) => [
                    'name' => self::text($lane['name'] ?? null, 60) ?? 'Lane',
                    'color' => self::color($lane['color'] ?? null),
                    'rules' => [
                        'priority_ids' => $refs($lane['rules']['priorities'] ?? [], fn ($r) => $this->priorityId($r)),
                        'type_ids' => $refs($lane['rules']['types'] ?? [], fn ($r) => $this->typeId($r)),
                        'label_ids' => $refs($lane['rules']['labels'] ?? [], fn ($r) => $labels[(string) $r] ?? null),
                        'assignee_ids' => $refs($lane['rules']['assignees'] ?? [], fn ($r) => $this->userFor($r)),
                        'release_ids' => $refs($lane['rules']['releases'] ?? [], fn ($r) => $releases[(string) $r] ?? null),
                        'overdue' => ! empty($lane['rules']['overdue']),
                    ],
                ], self::items($lanes['lanes'] ?? [])),
            ];
            try {
                $board->update(['settings' => array_merge($board->settings ?? [], ['swimlanes' => Swimlanes::normalize($board, $input)])]);
            } catch (Throwable) {
                $this->progress->warn('The swimlanes of board “'.$board->name.'” couldn’t be restored.');
            }
        }
    }

    // ── issues ───────────────────────────────────────────────────────────────

    private function createIssues(): void
    {
        $issues = $this->archive->list('issues.json', 'issues');
        if ($issues === []) {
            return;
        }
        $boards = Board::with(['columns' => fn ($q) => $q->orderBy('position')])->whereIn('id', ImportMappings::map($this->run, 'board'))->get()->keyBy('id');
        $boardIds = ImportMappings::map($this->run, 'board');
        $columns = ImportMappings::map($this->run, 'column');
        $sprints = ImportMappings::map($this->run, 'sprint');
        $releases = ImportMappings::map($this->run, 'release');
        $labels = ImportMappings::map($this->run, 'label');
        $fields = ItemField::whereIn('id', ImportMappings::map($this->run, 'field'))->get()->keyBy('id');
        $fieldIds = ImportMappings::map($this->run, 'field');
        $options = ImportMappings::map($this->run, 'option');
        $doneColumns = $boards->flatMap(fn (Board $b) => $b->columns)->where('is_done_column', true)->pluck('id')->flip();
        $subtaskTypes = ItemType::forProject($this->project)->where('slug', 'subtask')->pluck('id')->flip();
        $defaultType = ItemType::forProject($this->project)->where('is_default', true)->value('id');
        $defaultPriority = ItemPriority::forProject($this->project)->where('is_default', true)->value('id');

        foreach ($issues as $doc) {
            $boardId = $boardIds[(string) ($doc['board'] ?? '')] ?? null;
            if ($boardId === null || ! isset($boards[$boardId])) {
                $this->progress->warn('An issue without a board was skipped.');

                continue;
            }
            $item = ImportMappings::once($this->run, 'item', self::ref($doc), Item::class, function () use ($doc, $boardId, $boards, $columns, $sprints, $releases, $labels, $fields, $fieldIds, $options, $doneColumns, $subtaskTypes, $defaultType, $defaultPriority) {
                $columnId = $columns[(string) ($doc['column'] ?? '')] ?? $boards[$boardId]->columns->first()?->id;
                $parentId = is_string($doc['parent'] ?? null) ? ImportMappings::find($this->run, 'item', $doc['parent']) : null;
                $typeId = $this->typeId($doc['type'] ?? null) ?? $defaultType;
                if ($parentId === null && isset($subtaskTypes[$typeId])) {
                    $typeId = $defaultType; // a subtask needs a parent, as in the app
                }
                $completed = self::datetime($doc['completed_at'] ?? null);

                $item = Item::create([
                    'board_id' => $boardId,
                    'project_id' => $this->project->id,
                    'column_id' => $columnId,
                    'parent_id' => $parentId,
                    'sprint_id' => $sprints[(string) ($doc['sprint'] ?? '')] ?? null,
                    'release_id' => $releases[(string) ($doc['release'] ?? '')] ?? null,
                    'item_type_id' => $typeId,
                    'priority_id' => $this->priorityId($doc['priority'] ?? null) ?? $defaultPriority,
                    'key' => $this->project->getNextItemKey(),
                    'title' => self::text($doc['title'] ?? null, 500) ?? 'Untitled',
                    'description' => ArchiveContent::restoreHtml($doc['description'] ?? null, $this->mediaUrls),
                    'created_by' => $this->user->id,
                    'reporter_id' => $this->userFor($doc['reporter'] ?? null) ?? $this->user->id,
                    'assigned_to' => $this->userFor($doc['assignee'] ?? null),
                    'position' => self::int($doc['position'] ?? null) ?? 0,
                    'backlog_position' => self::int($doc['backlog_position'] ?? null) ?? 0,
                    'story_points' => self::int($doc['story_points'] ?? null),
                    'original_estimate' => self::int($doc['original_estimate'] ?? null),
                    'start_date' => self::date($doc['start_date'] ?? null),
                    'start_time' => self::clock($doc['start_time'] ?? null),
                    'due_date' => self::date($doc['due_date'] ?? null),
                    'due_time' => self::clock($doc['due_time'] ?? null),
                    'completed_at' => $completed ?? (isset($doneColumns[$columnId]) ? now() : null),
                ]);

                $labelIds = array_values(array_unique(array_filter(array_map(fn ($r) => $labels[(string) $r] ?? null, is_array($doc['labels'] ?? null) ? $doc['labels'] : []))));
                if ($labelIds !== []) {
                    $item->labels()->sync($labelIds);
                }
                $this->insertFieldValues($item, is_array($doc['fields'] ?? null) ? $doc['fields'] : [], $fields, $fieldIds, $options);

                return $item;
            });
            if ($item->wasRecentlyCreated) {
                $this->progress->advance();
            }
            $this->createComments($item, self::items($doc['comments'] ?? []));
            $this->createAttachments($item, self::items($doc['attachments'] ?? []));
        }

        $items = ImportMappings::map($this->run, 'item');
        foreach ($this->archive->list('issues.json', 'links') as $link) {
            $from = $items[(string) ($link['from'] ?? '')] ?? null;
            $to = $items[(string) ($link['to'] ?? '')] ?? null;
            $type = (string) ($link['type'] ?? '');
            if ($from === null || $to === null || $from === $to || ! isset(ItemLink::LINK_TYPES[$type])) {
                continue;
            }
            ImportMappings::once($this->run, 'link', $link['from'].'>'.$link['to'].'>'.$type, ItemLink::class, fn () => ItemLink::create([
                'source_item_id' => $from,
                'target_item_id' => $to,
                'link_type' => $type,
                'created_by' => $this->user->id,
            ]));
        }
    }

    private function insertFieldValues(Item $item, array $values, $fields, array $fieldIds, array $options): void
    {
        $rows = [];
        foreach ($values as $ref => $value) {
            $field = $fields[$fieldIds[(string) $ref] ?? 0] ?? null;
            if ($field === null || $value === null) {
                continue;
            }
            $stored = match ($field->type) {
                'text' => self::text($value, 5000),
                'number' => is_numeric($value) ? (float) $value : null,
                'date' => self::date($value),
                'checkbox' => (bool) $value,
                'select' => $options[(string) $value] ?? null,
                default => null,
            };
            if ($stored === null) {
                continue;
            }
            $rows[] = [
                ...array_fill_keys(array_values(ItemFieldValue::COLUMNS), null),
                ItemFieldValue::COLUMNS[$field->type] => $stored,
                'item_id' => $item->id,
                'item_field_id' => $field->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            ItemFieldValue::insert($rows);
        }
    }

    private function createComments(Item $item, array $comments): void
    {
        foreach ($comments as $doc) {
            $comment = ImportMappings::once($this->run, 'comment', self::ref($doc), Comment::class, function () use ($item, $doc) {
                $author = $this->names[(string) ($doc['author'] ?? '')] ?? 'someone';
                $when = self::datetime($doc['created_at'] ?? null);
                $origin = '<p><em>Originally by '.e($author).($when ? ' on '.CarbonImmutable::parse($when)->format('j M Y') : '').'</em></p>';

                return Comment::create([
                    'item_id' => $item->id,
                    'user_id' => $this->user->id,
                    'parent_id' => is_string($doc['parent'] ?? null) ? ImportMappings::find($this->run, 'comment', $doc['parent']) : null,
                    'body' => $origin.(ArchiveContent::restoreHtml($doc['body'] ?? null, $this->mediaUrls) ?? ''),
                ]);
            });
            if ($comment->wasRecentlyCreated) {
                $this->progress->advance();
            }
        }
    }

    private function createAttachments(Item $item, array $attachments): void
    {
        foreach ($attachments as $doc) {
            $source = $this->archive->file($doc['file'] ?? null);
            if ($source === null) {
                $this->progress->warn('An attachment listed in the export has no file and was skipped.');

                continue;
            }
            $attachment = ImportMappings::once($this->run, 'attachment', self::ref($doc), Attachment::class, function () use ($item, $doc, $source) {
                $display = self::text(basename(str_replace('\\', '/', (string) ($doc['name'] ?? ''))), 250) ?? 'file';
                $file = new UploadedFile($source, preg_replace('/[\x00-\x1F\x7F]/u', '', $display), null, null, true);
                $filename = Uploads::name($file);
                $path = Storage::disk('public')->putFileAs('attachments/'.$item->id, $file, $filename);

                return $item->attachments()->create([
                    'user_id' => $this->user->id,
                    'filename' => $filename,
                    'original_filename' => Uploads::displayName($file),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
            });
            if ($attachment->wasRecentlyCreated) {
                $this->progress->advance();
            }
        }
    }

    // ── docs, lists, plans, helpdesks ───────────────────────────────────────

    private function createPages(): void
    {
        $items = null;
        foreach ($this->archive->list('pages.json', 'pages') as $doc) {
            $isFolder = ($doc['type'] ?? null) === 'folder';
            $items ??= ImportMappings::map($this->run, 'item');
            $page = ImportMappings::once($this->run, 'page', self::ref($doc), Page::class, function () use ($doc, $isFolder, $items) {
                $content = $isFolder ? null : ArchiveContent::restorePage($doc['content'] ?? null, $this->mediaUrls, $items);
                $isWhiteboard = is_array($content) && ArchiveContent::isWhiteboard($content);
                $page = $this->project->allPages()->create([
                    'name' => self::text($doc['name'] ?? null, 255) ?? 'Untitled',
                    'type' => $isFolder ? 'folder' : 'page',
                    'parent_id' => is_string($doc['parent'] ?? null) ? ImportMappings::find($this->run, 'page', $doc['parent']) : null,
                    'icon' => self::text($doc['icon'] ?? null, 255) ?? ($isFolder ? '📁' : ($isWhiteboard ? '✏️' : '📄')),
                    'content' => $isFolder ? null : ($content ?? ['type' => 'lexical', 'html' => '']),
                    'position' => self::int($doc['position'] ?? null) ?? 0,
                    'created_by' => $this->user->id,
                    'last_edited_by' => $this->user->id,
                ]);
                $status = $doc['status'] ?? 'draft';
                if (! $isFolder && in_array($status, ['published', 'archived'], true)) {
                    $page->publish($this->user, 'Restored from a project export');
                    if ($status === 'archived') {
                        $page->update(['status' => 'archived']);
                    }
                }

                return $page;
            });
            if ($page->wasRecentlyCreated) {
                $this->progress->advance();
            }
        }
    }

    private function createTaskLists(): void
    {
        foreach ($this->archive->list('task_lists.json', 'task_lists') as $position => $doc) {
            $list = ImportMappings::once($this->run, 'list', self::ref($doc), TaskList::class, function () use ($doc, $position) {
                $name = self::text($doc['name'] ?? null, 255) ?? 'Tasks';
                $list = TaskList::create([
                    'project_id' => $this->project->id,
                    'name' => $name,
                    'slug' => self::freeSlug(TaskList::where('project_id', $this->project->id), $name, 'list'),
                    'description' => self::text($doc['description'] ?? null, 5000),
                    'position' => self::int($doc['position'] ?? null) ?? $position,
                    'created_by' => $this->user->id,
                ]);
                $list->watchers()->syncWithoutDetaching([$this->user->id]);

                return $list;
            });
            foreach (self::items($doc['tasks'] ?? []) as $index => $task) {
                $created = ImportMappings::once($this->run, 'task', self::ref($task), TaskListItem::class, fn () => $list->items()->create([
                    'title' => self::text($task['title'] ?? null, 500) ?? 'Untitled',
                    'description' => ArchiveContent::restoreJson($task['description'] ?? null, $this->mediaUrls),
                    'status' => in_array($task['status'] ?? null, ['todo', 'done'], true) ? $task['status'] : 'todo',
                    'assignee_id' => $this->userFor($task['assignee'] ?? null),
                    'start_date' => self::date($task['start_date'] ?? null),
                    'start_time' => self::clock($task['start_time'] ?? null),
                    'due_date' => self::date($task['due_date'] ?? null),
                    'due_time' => self::clock($task['due_time'] ?? null),
                    'recurrence' => in_array($task['recurrence'] ?? null, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $task['recurrence'] : null,
                    'reminder_minutes' => self::int($task['reminder_minutes'] ?? null),
                    'position' => self::int($task['position'] ?? null) ?? $index,
                    'created_by' => $this->user->id,
                ]));
                if ($created->wasRecentlyCreated) {
                    $this->progress->advance();
                }
            }
        }
    }

    private function createPlans(): void
    {
        $items = ImportMappings::map($this->run, 'item');
        foreach ($this->archive->list('plans.json', 'plans') as $position => $doc) {
            $plan = ImportMappings::once($this->run, 'plan', self::ref($doc), Plan::class, function () use ($doc, $position, $items) {
                $data = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                $rows = array_map(function (array $row) use ($items) {
                    $row['itemId'] = is_string($row['item'] ?? null) ? ($items[$row['item']] ?? null) : null;
                    unset($row['item']);

                    return $row;
                }, self::items($data['rows'] ?? []));
                $name = self::text($doc['name'] ?? null, 255) ?? 'Plan';
                $plan = Plan::create([
                    'project_id' => $this->project->id,
                    'name' => $name,
                    'slug' => Plan::uniqueSlug($this->project, $name),
                    'description' => self::text($doc['description'] ?? null, 5000),
                    'data' => PlanDocument::normalize(['rows' => $rows, 'dependencies' => is_array($data['dependencies'] ?? null) ? $data['dependencies'] : []]),
                    'settings' => PlanDocument::normalizeSettings(is_array($doc['settings'] ?? null) ? $doc['settings'] : null),
                    'position' => self::int($doc['position'] ?? null) ?? $position + 1,
                    'created_by' => $this->user->id,
                    'last_edited_by' => $this->user->id,
                ]);
                $plan->recordVersion($this->user, 'Restored from a project export');

                return $plan;
            });
            if ($plan->wasRecentlyCreated) {
                $this->progress->advance();
            }
        }
    }

    private function createHelpdesks(): void
    {
        foreach ($this->archive->list('helpdesks.json', 'helpdesks') as $position => $doc) {
            $columns = self::items($doc['columns'] ?? []);
            $helpdesk = ImportMappings::once($this->run, 'helpdesk', self::ref($doc), Helpdesk::class, function () use ($doc, $position, $columns) {
                $name = self::text($doc['name'] ?? null, 255) ?? 'Helpdesk';
                $settings = array_intersect_key(is_array($doc['settings'] ?? null) ? $doc['settings'] : [], array_flip(HelpdeskForm::SETTING_KEYS));
                $settings = array_filter($settings, fn ($v) => is_scalar($v) || is_array($v));
                $helpdesk = $this->project->helpdesks()->create([
                    'name' => $name,
                    'slug' => self::freeSlug(Helpdesk::withTrashed()->where('project_id', $this->project->id), $name, 'helpdesk'),
                    'description' => self::text($doc['description'] ?? null, 5000),
                    'position' => self::int($doc['position'] ?? null) ?? $position,
                    'created_by' => $this->user->id,
                    'settings' => $settings ?: null,
                ]);
                if ($columns !== []) {
                    $helpdesk->columns()->delete();
                }

                return $helpdesk;
            });
            if ($helpdesk->wasRecentlyCreated) {
                $this->progress->advance();
            }
            foreach ($columns as $index => $column) {
                ImportMappings::once($this->run, 'hcolumn', self::ref($column), HelpdeskColumn::class, function () use ($helpdesk, $column, $index) {
                    $name = self::text($column['name'] ?? null, 255) ?? 'Column';

                    return $helpdesk->columns()->create([
                        'name' => $name,
                        'slug' => self::freeSlug(HelpdeskColumn::where('helpdesk_id', $helpdesk->id), $name, 'column'),
                        'color' => self::color($column['color'] ?? null) ?? '#6b7280',
                        'position' => self::int($column['position'] ?? null) ?? $index,
                        'wip_limit' => self::int($column['wip_limit'] ?? null),
                        'is_done_column' => (bool) ($column['is_done'] ?? false),
                    ]);
                });
            }
            foreach (self::items($doc['fields'] ?? []) as $index => $field) {
                if (! in_array($field['type'] ?? null, ['text', 'rich_text', 'select', 'checkbox', 'file', 'number', 'radio', 'date', 'checkboxes'], true)) {
                    continue;
                }
                ImportMappings::once($this->run, 'hfield', self::ref($field), HelpdeskField::class, fn () => $helpdesk->fields()->create([
                    'name' => Str::slug((string) ($field['name'] ?? $field['label'] ?? 'field'), '_') ?: 'field',
                    'label' => self::text($field['label'] ?? null, 255) ?? 'Field',
                    'type' => $field['type'],
                    'options' => is_array($field['options'] ?? null) ? array_values(array_map(fn ($o) => Str::limit((string) (is_scalar($o) ? $o : ''), 255, ''), $field['options'])) : null,
                    'is_required' => (bool) ($field['is_required'] ?? false),
                    'position' => self::int($field['position'] ?? null) ?? $index,
                    'is_visible_on_card' => (bool) ($field['is_visible_on_card'] ?? false),
                ]));
            }
        }
    }

    // ── refs and values ─────────────────────────────────────────────────────

    private function typeId(mixed $ref): ?int
    {
        if (! is_string($ref)) {
            return null;
        }
        if (str_starts_with($ref, 'slug:')) {
            $this->globalTypes ??= ItemType::whereNull('project_id')->pluck('id', 'slug')->all();

            return $this->globalTypes[substr($ref, 5)] ?? null;
        }

        return ImportMappings::find($this->run, 'type', $ref);
    }

    private function priorityId(mixed $ref): ?int
    {
        if (! is_string($ref)) {
            return null;
        }
        if (str_starts_with($ref, 'slug:')) {
            $this->globalPriorities ??= ItemPriority::whereNull('project_id')->pluck('id', 'slug')->all();

            return $this->globalPriorities[substr($ref, 5)] ?? null;
        }

        return ImportMappings::find($this->run, 'priority', $ref);
    }

    /** An archive ref (the mapping's source id). */
    private static function ref(array $doc): string
    {
        $ref = $doc['ref'] ?? null;

        return is_string($ref) && preg_match('/^[a-z]{1,3}\d{1,9}$/', $ref) ? $ref : 'x'.md5(json_encode($doc));
    }

    /** @return list<array> */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function strings(mixed $value, int $max): array
    {
        return is_array($value) ? array_values(array_filter(array_map(fn ($v) => self::text($v, $max), $value))) : [];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d{1,9}$/', $value)) ? (int) $value : (is_float($value) ? (int) round($value) : null);
    }

    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : null;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    private static function clock(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) ? $value : null;
    }

    private static function datetime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 40) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /** A slug not used yet in $query's scope. */
    private static function freeSlug($query, string $name, string $fallback): string
    {
        $base = Str::limit(Str::slug($name) ?: $fallback, 200, '');
        $taken = (clone $query)->where('slug', 'like', $base.'%')->pluck('slug')->flip();
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
