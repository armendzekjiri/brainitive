<?php

namespace App\Support\Imports\Jira;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Imports\Importer;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\ImportMappings;
use App\Support\Imports\ImportProgress;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Jira "Export issues → CSV (all fields)" → a new board in the chosen project (v1.41.0, BRAI-139).
 *
 * Preview (dry run): counts, statuses, types, priorities, sprints, people and what can't come over,
 * plus a default mapping the user can change. Run: board + columns (one per mapped column name,
 * the done column flagged), item types created where asked, labels reused by name, planned sprints
 * (Scrum board) when chosen, issues parents first (epic → story → sub-task via parent_id),
 * comments as the importing user ("Originally by X on date"), blocks / relates / duplicates /
 * clones links between imported issues. The Jira key stays in import_mappings and as a reference
 * line at the top of the description. Everything goes through ImportMappings::once(): a retried run
 * continues without duplicates.
 */
class JiraImporter implements Importer
{
    /** import_mappings source type → report label */
    private const COUNTED = ['board' => 'boards', 'column' => 'columns', 'type' => 'item types', 'sprint' => 'sprints', 'label' => 'labels', 'issue' => 'issues', 'comment' => 'comments', 'link' => 'links'];

    private const COLUMN_COLORS = ['todo' => '#6b7280', 'in_progress' => '#3b82f6', 'done' => '#22c55e'];

    /** Jira's classic priorities → Brainitive's default priority slugs. */
    private const PRIORITY_ALIASES = [
        'blocker' => 'highest', 'critical' => 'highest', 'urgent' => 'highest',
        'major' => 'high', 'normal' => 'medium', 'minor' => 'low', 'trivial' => 'lowest',
    ];

    public static function source(): string
    {
        return 'jira';
    }

    public static function label(): string
    {
        return 'Jira (CSV)';
    }

    public static function description(): string
    {
        return 'In Jira, search the issues and choose Export → Export CSV (all fields). Issues with their statuses, types, priorities, epics and sub-tasks, labels, sprints, links and comments come over as a new board.';
    }

    public static function accept(): string
    {
        return '.csv,text/csv';
    }

    public static function fileRules(): array
    {
        return ['max:'.intdiv(JiraExport::MAX_BYTES, 1024), 'extensions:csv,txt'];
    }

    public static function needsProject(): bool
    {
        return true;
    }

    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool
    {
        return $project !== null && $user->can('createBoards', $project);
    }

    public function preview(ImportRun $run, string $path): array
    {
        $export = JiraExport::read($path);
        $project = $this->project($run);
        $defaults = $this->defaults($export, $project);
        $hierarchy = $export->hierarchy();
        $parents = array_filter($hierarchy['parents']);
        $sprints = $export->tally('sprints');

        return [
            'issues' => count($export->issues),
            'projects' => $export->projects(),
            'delimiter' => $export->delimiter === "\t" ? 'tab' : $export->delimiter,
            'statuses' => $export->statuses(),
            'types' => $export->tally('type'),
            'priorities' => $export->tally('priority'),
            'sprints' => $sprints,
            'labels' => count($export->tally('labels')),
            'people' => $export->persons(),
            'comments' => array_sum(array_map(fn ($issue) => count($issue['comments']), $export->issues)),
            'links' => array_sum(array_map(fn ($issue) => count($issue['links']), $export->issues)),
            'nested' => count($parents),
            'sample' => array_map(fn ($issue) => ['key' => $issue['key'], 'summary' => $issue['summary'], 'type' => $issue['type'], 'parent' => $hierarchy['parents'][$issue['key']]], array_slice($export->issues, 0, 5)),
            'unsupported' => $export->unsupported,
            'warnings' => $export->warnings,
            'choices' => [
                'types' => ItemType::forProject($project)->orderBy('project_id')->orderBy('id')->get(['id', 'name', 'slug'])->map(fn ($t) => $t->only('id', 'name', 'slug'))->values()->all(),
                'priorities' => ItemPriority::forProject($project)->orderBy('order')->get(['id', 'name'])->map(fn ($p) => $p->only('id', 'name'))->values()->all(),
                'members' => $project->accessibleUsers()->orderBy('name')->get(['id', 'name', 'email'])->map(fn ($u) => $u->only('id', 'name', 'email'))->values()->all(),
            ],
            'defaults' => $defaults,
        ];
    }

    public function optionRules(ImportRun $run): array
    {
        $project = $this->project($run);
        $typeIds = ItemType::forProject($project)->pluck('id')->map(fn ($id) => (string) $id)->all();

        return [
            'board_name' => ['required', 'string', 'max:255'],
            'statuses' => ['required', 'array', 'max:500'],
            'statuses.*.name' => ['required', 'string', 'max:255'],
            'statuses.*.column' => ['required', 'string', 'max:60'],
            'done_column' => ['required', 'string', 'max:60'],
            'types' => ['array', 'max:500'],
            'types.*.name' => ['required', 'string', 'max:255'],
            'types.*.target' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($typeIds) {
                if ($value !== 'create' && ! in_array((string) $value, $typeIds, true)) {
                    $fail('Pick an item type of this project, or “Create”.');
                }
            }],
            'priorities' => ['array', 'max:500'],
            'priorities.*.name' => ['required', 'string', 'max:255'],
            'priorities.*.target' => ['nullable', 'integer', Rule::in(ItemPriority::forProject($project)->pluck('id')->all())],
            'people' => ['array', 'max:5000'],
            'people.*.name' => ['required', 'string', 'max:255'],
            'people.*.user_id' => ['nullable', 'integer', Rule::in($project->accessibleUsers()->pluck('id')->all())],
            'import_comments' => ['boolean'],
            'create_sprints' => ['boolean'],
        ];
    }

    public function run(ImportRun $run, string $path, ImportProgress $progress): array
    {
        $export = JiraExport::read($path);
        $project = $this->project($run);
        $user = $run->user;
        $options = $this->options($run, $export, $project);
        $hierarchy = $export->hierarchy();
        $progress->total(count($export->issues));

        $sprintNames = $options['create_sprints'] ? array_column($export->tally('sprints'), 'name') : [];
        $board = ImportMappings::once($run, 'board', 'board', Board::class, function () use ($project, $user, $options, $sprintNames) {
            $board = $project->boards()->create([
                'name' => $options['board_name'],
                'slug' => $this->boardSlug($project, $options['board_name']),
                'type' => $sprintNames !== [] ? 'scrum' : 'kanban',
                'position' => ($project->boards()->max('position') ?? 0) + 1,
                'created_by' => $user->id,
                // BRAI-346: an imported board shows its whole history until someone turns done retention on
                'settings' => ['done_retention_days' => 0],
            ]);
            $board->columns()->delete(); // Board::created adds To Do / In Progress / Done; the mapping decides

            return $board;
        });

        $columns = $this->columns($run, $board, $export, $options);
        $types = $this->types($run, $project, $options);
        $priorities = $this->priorities($project, $options);
        $people = $this->people($project, $options);

        $sprints = [];
        foreach (array_values($sprintNames) as $index => $name) {
            $sprints[$name] = ImportMappings::once($run, 'sprint', $name, Sprint::class, fn () => $board->sprints()->create([
                'name' => mb_substr($name, 0, 255),
                'position' => $index + 1,
                'created_by' => $user->id,
            ]))->id;
        }

        $rootSprints = $sprints !== [] ? $this->rootSprints($export, $hierarchy['parents'], $progress) : [];
        $labels = [];
        $subtaskTypes = ItemType::forProject($project)->where('slug', 'subtask')->pluck('id')->flip();
        $defaultType = ItemType::defaultIdFor($project);
        $defaultPriority = ItemPriority::forProject($project)->where('is_default', true)->value('id');
        $positions = Item::where('board_id', $board->id)->selectRaw('column_id, max(position) as top')->groupBy('column_id')->pluck('top', 'column_id')->map(fn ($top) => (int) $top)->all();
        $backlog = (int) (Item::where('board_id', $board->id)->max('backlog_position') ?? 0);
        $items = ImportMappings::map($run, 'issue');

        foreach ($hierarchy['order'] as $index) {
            $issue = $export->issues[$index];
            $parentKey = $hierarchy['parents'][$issue['key']];
            $parentId = $parentKey !== null ? ($items[$parentKey] ?? null) : null;
            $parent = $parentId !== null ? Item::find($parentId) : null;
            [$columnId, $done] = $columns[$issue['status']];

            $item = ImportMappings::once($run, 'issue', $issue['key'], Item::class, function () use ($issue, $board, $project, $user, $columnId, $done, $parent, $types, $subtaskTypes, $defaultType, $priorities, $defaultPriority, $people, $sprints, &$positions, &$backlog, &$labels, $run, $export, $rootSprints) {
                $typeId = $types[$issue['type']] ?? $defaultType;
                if ($parent === null && isset($subtaskTypes[$typeId])) {
                    $typeId = $defaultType; // a subtask needs a parent, as in the app
                }
                $sprint = $parent !== null ? $parent->sprint_id : ($sprints[$rootSprints[$issue['key']] ?? ''] ?? null);

                $item = Item::create([
                    'board_id' => $board->id,
                    'project_id' => $project->id,
                    'column_id' => $columnId,
                    'parent_id' => $parent?->id,
                    'sprint_id' => $sprint,
                    'item_type_id' => $typeId,
                    'priority_id' => $priorities[$issue['priority']] ?? $defaultPriority,
                    'key' => $project->getNextItemKey(),
                    'title' => $issue['summary'],
                    'description' => $this->issueHtml($issue, $export),
                    'created_by' => $user->id,
                    'assigned_to' => $issue['assignee'] !== null ? ($people[$issue['assignee']] ?? null) : null,
                    'reporter_id' => ($issue['reporter'] !== null ? ($people[$issue['reporter']] ?? null) : null) ?? $user->id,
                    'position' => $positions[$columnId] = ($positions[$columnId] ?? 0) + 1,
                    'backlog_position' => ++$backlog,
                    'story_points' => $issue['story_points'],
                    'original_estimate' => $issue['estimate'],
                    'start_date' => $issue['start'],
                    'due_date' => $issue['due'],
                    'completed_at' => $done ? ($issue['resolved'] ?? now()) : null,
                ]);

                $labelIds = [];
                foreach ($issue['labels'] as $name) {
                    $labelIds[] = $labels[mb_strtolower($name)] ??= $this->labelId($run, $project, $name);
                }
                if ($labelIds !== []) {
                    $item->labels()->sync(array_values(array_unique($labelIds)));
                }

                return $item;
            });
            $items[$issue['key']] = $item->id;

            if ($options['import_comments']) {
                foreach ($issue['comments'] as $number => $comment) {
                    if ($comment['body'] === '') {
                        continue;
                    }
                    ImportMappings::once($run, 'comment', $issue['key'].'#'.$number, Comment::class, function () use ($item, $user, $comment, $export) {
                        $created = $item->comments()->create(['user_id' => $user->id, 'body' => $this->commentBody($comment, $export)]);
                        ActivityLog::log($item, 'commented', null, ['comment_id' => $created->id, 'reply' => false, 'excerpt' => Str::limit(trim(strip_tags($created->body)), 120)]);

                        return $created;
                    });
                }
            }
            $progress->advance();
        }

        // links once every issue exists: only between issues of this file
        $skipped = 0;
        foreach ($export->issues as $issue) {
            foreach ($issue['links'] as $link) {
                $from = $items[$issue['key']] ?? null;
                $to = $items[$link['to']] ?? null;
                if ($from === null || $to === null || $from === $to) {
                    $skipped++;

                    continue;
                }
                ImportMappings::once($run, 'link', "{$issue['key']}|{$link['type']}|{$link['to']}", ItemLink::class, fn () => ItemLink::create([
                    'source_item_id' => $from,
                    'target_item_id' => $to,
                    'link_type' => $link['type'],
                    'created_by' => $user->id,
                ]));
            }
        }
        if ($skipped > 0) {
            $progress->warn("{$skipped} issue links point at issues that aren’t in this file and were skipped.");
        }

        foreach ($export->warnings as $warning) {
            $progress->warn($warning);
        }
        if ($sprints !== []) {
            $progress->warn('Sprints come over as planned sprints. Start or complete them on the board’s backlog page.');
        }
        // what this run created, from its mappings: right after a crash and a resume too
        $before = $run->report['created'] ?? [];
        foreach (self::COUNTED as $type => $label) {
            $total = ImportMapping::where('import_run_id', $run->id)->where('source_type', $type)->count();
            $progress->created($label, $total - ($before[$label] ?? 0));
        }

        $unsupported = $export->unsupported;
        if (! $options['import_comments']) {
            $unsupported['Comments (not chosen)'] = array_sum(array_map(fn ($issue) => count($issue['comments']), $export->issues));
        }
        $reported = $run->report['unsupported'] ?? []; // a resumed run already reported them
        foreach (array_filter($unsupported) as $what => $count) {
            if (! isset($reported[$what])) {
                $progress->unsupported($what, $count);
            }
        }

        return [
            'project_id' => $project->id,
            'board_id' => $board->id,
            // a Scrum board shows the active sprint only, and imported sprints are planned: open the backlog
            'url' => route($board->isScrum() ? 'boards.backlog' : 'boards.show', [$project->workspace->slug, $project->slug, $board->slug]),
        ];
    }

    /**
     * The sprint of each top-level issue. Brainitive keeps an issue and its sub-issues in one sprint
     * (the backlog moves them together), while Jira plans stories of one epic in different sprints:
     * a top-level issue gets its own current sprint, else the one most of its sub-issues are in.
     *
     * @param  array<string, string|null>  $parents
     * @return array<string, string> top-level Jira key → sprint name
     */
    private function rootSprints(JiraExport $export, array $parents, ImportProgress $progress): array
    {
        $votes = [];
        foreach ($export->issues as $issue) {
            $sprint = JiraExport::currentSprint($issue);
            if ($sprint === null) {
                continue;
            }
            $root = $issue['key'];
            for ($hops = 0; $parents[$root] !== null && $hops < 10; $hops++) {
                $root = $parents[$root];
            }
            $votes[$root][$sprint] = ($votes[$root][$sprint] ?? 0) + ($root === $issue['key'] ? 1_000_000 : 1);
        }

        $result = [];
        foreach ($votes as $root => $counts) {
            arsort($counts);
            $result[$root] = (string) array_key_first($counts);
            if (count($counts) > 1) {
                $progress->warn("{$root} and its sub-issues were in different Jira sprints. Brainitive keeps an issue and its sub-issues in one sprint, so they are all in “{$result[$root]}”.");
            }
        }

        return $result;
    }

    // ── mapping ─────────────────────────────────────────────────────────────

    /** The default mapping shown in the preview (and used for anything the options leave out). */
    private function defaults(JiraExport $export, Project $project): array
    {
        $types = ItemType::forProject($project)->get(['id', 'name', 'slug', 'project_id']);
        $priorities = ItemPriority::forProject($project)->get(['id', 'name', 'slug']);
        $members = $project->accessibleUsers()->get(['id', 'name', 'email']);

        return [
            'board_name' => 'Jira import '.now()->format('j M Y'),
            'statuses' => array_map(fn ($s) => ['name' => $s['name'], 'column' => $s['category'] === 'done' ? 'Done' : mb_substr($s['name'], 0, 60)], $export->statuses()),
            'done_column' => 'Done',
            'types' => array_map(fn ($t) => ['name' => $t['name'], 'target' => $this->matchType($types, $t['name'])], $export->tally('type')),
            'priorities' => array_map(fn ($p) => ['name' => $p['name'], 'target' => $this->matchPriority($priorities, $p['name'])], $export->tally('priority')),
            'people' => array_map(fn ($p) => ['name' => $p['name'], 'user_id' => $this->matchPerson($members, $p['name'])], $export->persons()),
            'import_comments' => true,
            'create_sprints' => $export->tally('sprints') !== [],
        ];
    }

    /** The confirmed options over the defaults, by Jira name. */
    private function options(ImportRun $run, JiraExport $export, Project $project): array
    {
        $defaults = $this->defaults($export, $project);
        $chosen = $run->options ?? [];
        $merge = function (string $list, string $field) use ($defaults, $chosen): array {
            $byName = [];
            foreach ($defaults[$list] as $row) {
                $byName[$row['name']] = $row[$field];
            }
            foreach ((array) ($chosen[$list] ?? []) as $row) {
                if (is_array($row) && isset($row['name']) && array_key_exists($row['name'], $byName) && array_key_exists($field, $row)) {
                    $byName[$row['name']] = $row[$field];
                }
            }

            return $byName;
        };

        $statuses = array_map(fn ($column) => trim((string) $column) ?: 'To Do', $merge('statuses', 'column'));

        return [
            'board_name' => trim((string) ($chosen['board_name'] ?? '')) ?: $defaults['board_name'],
            'statuses' => $statuses,
            'done_column' => trim((string) ($chosen['done_column'] ?? '')) ?: $defaults['done_column'],
            'types' => $merge('types', 'target'),
            'priorities' => $merge('priorities', 'target'),
            'people' => $merge('people', 'user_id'),
            'import_comments' => (bool) ($chosen['import_comments'] ?? $defaults['import_comments']),
            'create_sprints' => (bool) ($chosen['create_sprints'] ?? $defaults['create_sprints']),
        ];
    }

    /**
     * Columns in status order; the done column last-but-flagged (appended when no status maps to it).
     *
     * @return array<string, array{0: int, 1: bool}> Jira status → [column id, is done]
     */
    private function columns(ImportRun $run, Board $board, JiraExport $export, array $options): array
    {
        $names = [];
        $category = [];
        foreach ($export->statuses() as $status) {
            $name = mb_substr($options['statuses'][$status['name']], 0, 60);
            $key = mb_strtolower($name);
            $names[$key] ??= $name;
            $category[$key] ??= $status['category'];
        }
        $doneKey = mb_strtolower(mb_substr($options['done_column'], 0, 60));
        $names[$doneKey] ??= mb_substr($options['done_column'], 0, 60);
        $category[$doneKey] = 'done';
        // the done column goes last, as on every board
        $done = [$doneKey => $names[$doneKey]];
        unset($names[$doneKey]);
        $names += $done;

        $ids = [];
        $slugs = [];
        $position = 0;
        foreach ($names as $key => $name) {
            $slug = Str::slug($name) ?: 'column';
            $base = $slug;
            for ($n = 2; isset($slugs[$slug]); $n++) {
                $slug = "{$base}-{$n}";
            }
            $slugs[$slug] = true;
            $ids[$key] = ImportMappings::once($run, 'column', $key, BoardColumn::class, fn () => $board->columns()->create([
                'name' => $name,
                'slug' => $slug,
                'color' => self::COLUMN_COLORS[$category[$key]],
                'position' => $position,
                'is_done_column' => $key === $doneKey,
            ]))->id;
            $position++;
        }

        $map = [];
        foreach ($options['statuses'] as $status => $column) {
            $key = mb_strtolower(mb_substr($column, 0, 60));
            $map[$status] = [$ids[$key], $key === $doneKey];
        }

        return $map;
    }

    /** @return array<string, int> Jira type → item type id (types to create are created now) */
    private function types(ImportRun $run, Project $project, array $options): array
    {
        $map = [];
        foreach ($options['types'] as $name => $target) {
            if ($target === 'create') {
                $map[$name] = ImportMappings::once($run, 'type', mb_strtolower($name), ItemType::class, fn () => $project->itemTypes()->create([
                    'name' => mb_substr($name, 0, 255),
                    'slug' => $this->typeSlug($project, $name),
                    'icon' => 'check-square',
                    'color' => '#6b7280',
                    'description' => 'Imported from Jira',
                    'is_active' => true,
                ]))->id;
            } elseif ($target !== null) {
                $map[$name] = (int) $target;
            }
        }

        return $map;
    }

    /** @return array<string, int> */
    private function priorities(Project $project, array $options): array
    {
        $valid = ItemPriority::forProject($project)->pluck('id')->flip();

        return array_filter(array_map(fn ($id) => $id !== null && isset($valid[(int) $id]) ? (int) $id : null, $options['priorities']));
    }

    /** @return array<string, int> Jira person → project member (still with access when the run starts) */
    private function people(Project $project, array $options): array
    {
        $members = $project->accessibleUsers()->pluck('id')->flip();

        return array_filter(array_map(fn ($id) => $id !== null && isset($members[(int) $id]) ? (int) $id : null, $options['people']));
    }

    private function labelId(ImportRun $run, Project $project, string $name): int
    {
        $name = mb_substr($name, 0, 255);
        $slug = Str::slug($name) ?: 'label-'.substr(md5($name), 0, 8);
        $existing = $project->labels()->where(fn ($q) => $q->where('slug', $slug)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))->first();
        if ($existing !== null) {
            return $existing->id;
        }

        return ImportMappings::once($run, 'label', mb_strtolower($name), Label::class, fn () => $project->labels()->create([
            'name' => $name,
            'slug' => $slug,
            'color' => '#6b7280',
        ]))->id;
    }

    private function matchType(Collection $types, string $name): string
    {
        $wanted = self::normalize($name);
        // the project's own types first, then the default ones
        $match = $types->sortBy(fn ($t) => $t->project_id === null ? 1 : 0)
            ->first(fn ($t) => self::normalize($t->name) === $wanted || self::normalize($t->slug) === $wanted);

        return $match ? (string) $match->id : 'create';
    }

    private function matchPriority(Collection $priorities, string $name): ?int
    {
        $wanted = self::normalize($name);
        $match = $priorities->first(fn ($p) => self::normalize($p->name) === $wanted || self::normalize($p->slug) === $wanted);
        if ($match === null && isset(self::PRIORITY_ALIASES[$wanted])) {
            $match = $priorities->first(fn ($p) => $p->slug === self::PRIORITY_ALIASES[$wanted]);
        }

        return $match?->id;
    }

    /** By email when the export has one (Jira Server user names often are), else a unique name. */
    private function matchPerson(Collection $members, string $person): ?int
    {
        $wanted = mb_strtolower(trim($person));
        if (filter_var($wanted, FILTER_VALIDATE_EMAIL)) {
            return $members->first(fn ($u) => mb_strtolower((string) $u->email) === $wanted)?->id;
        }
        $matches = $members->filter(fn ($u) => mb_strtolower(trim((string) $u->name)) === $wanted);

        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    private static function normalize(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));
    }

    // ── content ─────────────────────────────────────────────────────────────

    private function issueHtml(array $issue, JiraExport $export): string
    {
        $reference = 'Jira: '.$issue['key'];
        if ($issue['reporter'] !== null) {
            $reference .= ' · Reported by '.$issue['reporter'].($issue['created'] ? ' on '.CarbonImmutable::parse($issue['created'])->format('j M Y') : '');
        }
        $html = '<p><em>'.e($reference).'</em></p>'.JiraMarkup::toHtml($issue['description'], $export->people);
        if ($issue['environment'] !== '') {
            $html .= '<h3>Environment</h3>'.JiraMarkup::toHtml($issue['environment'], $export->people);
        }

        return $html;
    }

    private function commentBody(array $comment, JiraExport $export): string
    {
        $origin = 'Originally by '.($comment['author'] ?? 'a Jira user').($comment['date'] ? ' on '.CarbonImmutable::parse($comment['date'])->format('j M Y') : '');

        return '<p><em>'.e($origin).'</em></p>'.JiraMarkup::toHtml($comment['body'], $export->people);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function project(ImportRun $run): Project
    {
        $project = $run->project;
        if ($project === null) {
            throw new ImportFailed('The project for this import no longer exists.');
        }

        return $project;
    }

    private function boardSlug(Project $project, string $name): string
    {
        $base = Str::slug($name) ?: 'jira-import';
        $slug = $base;
        for ($n = 2; Board::withTrashed()->where('project_id', $project->id)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function typeSlug(Project $project, string $name): string
    {
        $base = Str::slug($name) ?: 'type-'.substr(md5($name), 0, 8);
        $slug = $base;
        for ($n = 2; ItemType::forProjectSettings($project)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
