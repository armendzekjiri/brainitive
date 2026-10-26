<?php

namespace App\Support\Imports\Trello;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ProjectMember;
use App\Support\Imports\Importer;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\ImportMappings;
use App\Support\Imports\ImportProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Trello board JSON → a new Kanban board in the chosen project (BRAI-65, v1.41.0).
 *
 * Open lists → columns in order (one of them is the done column); cards → issues with new
 * project keys, in list + card order, with description (Markdown → sanitized HTML), due / start
 * date, labels (reused by name or created), assignee (mapped member) and checklists (check lists
 * in the description, or subtasks). Archived cards are skipped or go to the done column.
 * Comments, custom fields, attachment files and power-ups are counted as not imported.
 * Every record is created through ImportMappings::once(), so a retried run never duplicates.
 */
class TrelloImporter implements Importer
{
    /** Subtasks are offered per card up to this many checklist items; above it the card keeps a check list. */
    public const MAX_SUBTASKS_PER_CARD = 50;

    public static function source(): string
    {
        return 'trello';
    }

    public static function label(): string
    {
        return 'Trello board (JSON)';
    }

    public static function description(): string
    {
        return 'In Trello: board menu → Print, export and share → Export as JSON. Lists, cards, labels, checklists and due dates come over as a new board.';
    }

    public static function accept(): string
    {
        return '.json,application/json';
    }

    public static function fileRules(): array
    {
        return ['extensions:json', 'max:'.(TrelloExport::MAX_BYTES / 1024)];
    }

    public static function needsProject(): bool
    {
        return true;
    }

    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool
    {
        return $project !== null && $project->workspace_id === $workspace->id && $user->can('createBoards', $project);
    }

    public function preview(ImportRun $run, string $path): array
    {
        $export = TrelloExport::fromFile($path);
        if ($export->openLists() === []) {
            throw new ImportFailed('This board has no open lists, so there is nothing to import.');
        }

        $byList = [];
        $archived = 0;
        $labelUse = [];
        $memberUse = [];
        $checklists = 0;
        $checkItems = 0;
        $cardsWithChecklists = 0;
        $attachments = 0;
        foreach ($export->cards as $card) {
            $isArchived = $export->isArchived($card);
            $archived += (int) $isArchived;
            if (! $isArchived) {
                $byList[$card['list']] = ($byList[$card['list']] ?? 0) + 1;
            }
            foreach ($card['labels'] as $id) {
                $labelUse[$id] = ($labelUse[$id] ?? 0) + 1;
            }
            foreach ($card['members'] as $id) {
                $memberUse[$id] = ($memberUse[$id] ?? 0) + 1;
            }
            $ofCard = $export->checklists[$card['id']] ?? [];
            $checklists += count($ofCard);
            $checkItems += array_sum(array_map(fn ($c) => count($c['items']), $ofCard));
            $cardsWithChecklists += (int) ($ofCard !== []);
            $attachments += count($card['attachments']);
        }

        $openLists = $export->openLists();
        $firstList = $openLists[0]['id'];
        $projectMembers = self::projectMembers($run->project);
        $byEmail = $projectMembers->filter(fn ($m) => $m['email'] !== null)->keyBy(fn ($m) => strtolower($m['email']));
        $existingLabels = $run->project->labels()->pluck('name')->map(fn ($n) => mb_strtolower($n))->flip();

        return [
            'board' => ['name' => $export->board['name'], 'url' => $export->board['url']],
            'lists' => array_map(fn ($l) => [
                'id' => $l['id'],
                'name' => $l['name'],
                'cards' => ($byList[$l['id']] ?? 0) + ($l['id'] === $firstList ? ($byList[''] ?? 0) : 0),
            ], $openLists),
            'archived_lists' => count($export->lists) - count($openLists),
            'done_list' => $export->defaultDoneList(),
            'cards' => ['total' => count($export->cards), 'open' => count($export->cards) - $archived, 'archived' => $archived],
            'labels' => array_values(array_map(fn ($l) => [
                'id' => $l['id'],
                'name' => $l['name'],
                'color' => $l['color'],
                'cards' => $labelUse[$l['id']] ?? 0,
                'exists' => isset($existingLabels[mb_strtolower($l['name'])]),
            ], $export->labels)),
            'checklists' => ['checklists' => $checklists, 'items' => $checkItems, 'cards' => $cardsWithChecklists],
            'members' => array_values(array_map(fn ($m) => [
                'id' => $m['id'],
                'name' => $m['name'],
                'username' => $m['username'],
                'email' => $m['email'],
                'cards' => $memberUse[$m['id']] ?? 0,
                'suggested_user_id' => $m['email'] !== null ? ($byEmail[$m['email']]['id'] ?? null) : null,
            ], $export->members)),
            'project_members' => $projectMembers->values()->all(),
            'attachments' => $attachments,
            'unsupported' => array_filter([
                'Comments' => array_sum($export->comments),
                'Custom fields' => array_sum($export->customFieldValues),
                'Attachments' => $attachments,
                'Power-ups' => $export->powerUps,
            ]),
            'warnings' => $export->warnings,
        ];
    }

    public function optionRules(ImportRun $run): array
    {
        return [
            'board_name' => ['required', 'string', 'max:255'],
            'done_list' => ['required', 'string', Rule::in(array_column($run->preview['lists'] ?? [], 'id'))],
            'include_archived' => ['boolean'],
            'checklists' => ['required', Rule::in(['description', 'subtasks'])],
            'attachment_links' => ['boolean'],
            'members' => ['array'],
            'members.*' => ['nullable', 'integer', new ProjectMember($run->project)],
        ];
    }

    public function run(ImportRun $run, string $path, ImportProgress $progress): array
    {
        $project = $run->project ?? throw new ImportFailed('The project this import was going into no longer exists.');
        $user = $run->user;
        $export = TrelloExport::fromFile($path);
        $options = $this->options($run, $export);

        $cards = array_values(array_filter($export->cards, fn ($c) => $options['include_archived'] || ! $export->isArchived($c)));
        $subtaskCount = 0;
        if ($options['checklists'] === 'subtasks') {
            foreach ($cards as $card) {
                $items = self::checkItemCount($export, $card['id']);
                $subtaskCount += $items <= self::MAX_SUBTASKS_PER_CARD ? $items : 0;
            }
        }
        $progress->total(1 + count($cards) + $subtaskCount);

        // Board + columns (Board::created adds To Do / In Progress / Done: the import brings its own)
        $isNew = ImportMappings::find($run, 'board', $export->board['id']) === null;
        $board = ImportMappings::once($run, 'board', $export->board['id'], Board::class, function () use ($project, $user, $options, $export) {
            $board = $project->boards()->create([
                'name' => $options['board_name'],
                'slug' => self::uniqueSlug($options['board_name'], fn ($slug) => Board::withTrashed()->where('project_id', $project->id)->where('slug', $slug)->exists(), 'board'),
                'description' => $export->board['desc'] !== '' ? Str::limit($export->board['desc'], 2000) : null,
                'type' => 'kanban',
                'position' => (int) $project->boards()->max('position') + 1,
                'created_by' => $user->id,
            ]);
            $board->columns()->delete();

            return $board;
        });
        if ($isNew) {
            $progress->created('boards');
        }

        $columns = [];
        $doneColumn = null;
        foreach ($export->openLists() as $position => $list) {
            $isNew = ImportMappings::find($run, 'list', $list['id']) === null;
            $column = ImportMappings::once($run, 'list', $list['id'], BoardColumn::class, fn () => $board->columns()->create([
                'name' => $list['name'],
                'slug' => self::uniqueSlug($list['name'], fn ($slug) => $board->columns()->where('slug', $slug)->exists(), 'column'),
                'color' => $list['id'] === $options['done_list'] ? '#22c55e' : '#6b7280',
                'position' => $position,
                'is_done_column' => $list['id'] === $options['done_list'],
            ]));
            $columns[$list['id']] = $column->id;
            if ($column->is_done_column) {
                $doneColumn = $column->id;
            }
            if ($isNew) {
                $progress->created('columns');
            }
        }
        $firstColumn = reset($columns);
        $doneColumn ??= end($columns);
        $progress->advance();

        $labels = $this->labels($run, $project, $export, $cards, $progress);

        $typeId = ItemType::forProject($project)->where('is_default', true)->value('id') ?? ItemType::query()->where('is_default', true)->value('id');
        $subtaskTypeId = ItemType::forProject($project)->where('slug', 'subtask')->value('id') ?? $typeId;
        $priorityId = ItemPriority::forProject($project)->where('is_default', true)->value('id') ?? ItemPriority::query()->where('is_default', true)->value('id');
        $timezone = $user->localTimezone();
        $positions = [];
        $tooManyChecklistItems = 0;
        $multipleMembers = 0;

        foreach ($cards as $index => $card) {
            $archived = $export->isArchived($card);
            $columnId = $archived ? $doneColumn : ($columns[$card['list']] ?? $firstColumn);
            $position = $positions[$columnId] = ($positions[$columnId] ?? 0) + 1;

            $assignee = null;
            $others = [];
            foreach ($card['members'] as $memberId) {
                $mapped = $options['members'][$memberId] ?? null;
                if ($assignee === null && $mapped !== null) {
                    $assignee = $mapped;
                } else {
                    $others[] = $export->members[$memberId]['name'];
                }
            }
            if ($assignee !== null && $others !== []) {
                $multipleMembers++;
            }

            $checklists = $export->checklists[$card['id']] ?? [];
            $asSubtasks = $options['checklists'] === 'subtasks' && $checklists !== [];
            if ($asSubtasks && self::checkItemCount($export, $card['id']) > self::MAX_SUBTASKS_PER_CARD) {
                $asSubtasks = false;
                $tooManyChecklistItems++;
            }

            $isNew = ImportMappings::find($run, 'card', $card['id']) === null;
            $item = ImportMappings::once($run, 'card', $card['id'], Item::class, function () use ($board, $project, $user, $card, $columnId, $doneColumn, $typeId, $priorityId, $position, $index, $assignee, $others, $checklists, $asSubtasks, $options, $timezone, $labels) {
                $item = Item::create([
                    'board_id' => $board->id,
                    'project_id' => $project->id,
                    'column_id' => $columnId,
                    'item_type_id' => $typeId,
                    'priority_id' => $priorityId,
                    'key' => $project->getNextItemKey(),
                    'title' => $card['name'],
                    'description' => TrelloDescription::html(
                        $card['desc'],
                        $asSubtasks ? [] : $checklists,
                        $options['attachment_links'] ? $card['attachments'] : [],
                        $others,
                        $assignee !== null,
                    ),
                    'created_by' => $user->id,
                    'reporter_id' => $user->id,
                    'assigned_to' => $assignee,
                    'position' => $position,
                    'backlog_position' => $index + 1,
                    'start_date' => $card['start']?->setTimezone($timezone)->toDateString(),
                    'due_date' => $card['due']?->setTimezone($timezone)->toDateString(),
                    'due_time' => $card['due']?->setTimezone($timezone)->format('H:i'),
                    'completed_at' => $columnId === $doneColumn ? now() : null,
                ]);
                $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $labels[$id] ?? null, $card['labels']))));
                if ($ids !== []) {
                    $item->labels()->sync($ids);
                }

                return $item;
            });
            if ($isNew) {
                $progress->created('issues');
            }
            $progress->advance();

            if ($asSubtasks) {
                foreach ($checklists as $checklist) {
                    foreach ($checklist['items'] as $checkItem) {
                        $subColumn = $checkItem['complete'] ? $doneColumn : $item->column_id;
                        $subPosition = $positions[$subColumn] = ($positions[$subColumn] ?? 0) + 1;
                        $title = count($checklists) > 1 ? $checklist['name'].': '.$checkItem['name'] : $checkItem['name'];
                        $isNew = ImportMappings::find($run, 'check_item', $checkItem['id']) === null;
                        ImportMappings::once($run, 'check_item', $checkItem['id'], Item::class, fn () => Item::create([
                            'board_id' => $board->id,
                            'project_id' => $project->id,
                            'column_id' => $subColumn,
                            'parent_id' => $item->id,
                            'item_type_id' => $subtaskTypeId,
                            'priority_id' => $priorityId,
                            'key' => $project->getNextItemKey(),
                            'title' => Str::limit($title, 250),
                            'created_by' => $user->id,
                            'reporter_id' => $user->id,
                            'position' => $subPosition,
                            'backlog_position' => $index + 1,
                            'completed_at' => $subColumn === $doneColumn ? now() : null,
                        ]));
                        if ($isNew) {
                            $progress->created('subtasks');
                        }
                        $progress->advance();
                    }
                }
            }
        }

        $this->report($export, $cards, $options, $progress, $tooManyChecklistItems, $multipleMembers);

        return [
            'project_id' => $project->id,
            'board_id' => $board->id,
            'url' => route('boards.show', [$project->workspace->slug, $project->slug, $board->slug]),
        ];
    }

    /**
     * The confirmed options, with defaults for anything missing (a retry of an old run).
     *
     * @return array{board_name: string, done_list: string, include_archived: bool, checklists: string, attachment_links: bool, members: array<string, int>}
     */
    private function options(ImportRun $run, TrelloExport $export): array
    {
        $options = $run->options ?? [];
        $openIds = array_column($export->openLists(), 'id');
        $done = is_string($options['done_list'] ?? null) && in_array($options['done_list'], $openIds, true) ? $options['done_list'] : $export->defaultDoneList();
        if ($done === null) {
            throw new ImportFailed('This board has no open lists, so there is nothing to import.');
        }

        // Only people who can open the project now (the preview may be days old)
        $allowed = self::projectMembers($run->project)->pluck('id')->flip();
        $members = [];
        foreach (is_array($options['members'] ?? null) ? $options['members'] : [] as $trelloId => $userId) {
            if (isset($export->members[$trelloId]) && is_numeric($userId) && isset($allowed[(int) $userId])) {
                $members[$trelloId] = (int) $userId;
            }
        }

        $name = is_string($options['board_name'] ?? null) ? trim($options['board_name']) : '';

        return [
            'board_name' => Str::limit($name !== '' ? $name : $export->board['name'], 250),
            'done_list' => $done,
            'include_archived' => (bool) ($options['include_archived'] ?? false),
            'checklists' => ($options['checklists'] ?? 'description') === 'subtasks' ? 'subtasks' : 'description',
            'attachment_links' => (bool) ($options['attachment_links'] ?? false),
            'members' => $members,
        ];
    }

    /**
     * Trello label id → project label id for the labels the imported cards use: an existing project
     * label with the same name (or slug) is reused, otherwise one is created.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, int>
     */
    private function labels(ImportRun $run, Project $project, TrelloExport $export, array $cards, ImportProgress $progress): array
    {
        $used = array_flip(array_merge([], ...array_map(fn ($c) => $c['labels'], $cards)));
        $existing = $project->labels()->get(['id', 'name', 'slug']);
        $ids = [];
        foreach ($export->labels as $trelloId => $label) {
            if (! isset($used[$trelloId])) {
                continue;
            }
            $mapped = ImportMappings::find($run, 'label', $trelloId);
            if ($mapped !== null && $existing->contains('id', $mapped)) {
                $ids[$trelloId] = $mapped;

                continue;
            }
            $slug = Str::slug($label['name']) ?: 'label-'.substr(md5($label['name']), 0, 8);
            $match = $existing->first(fn (Label $l) => mb_strtolower($l->name) === mb_strtolower($label['name'])) ?? $existing->firstWhere('slug', $slug);
            if ($match !== null) {
                $ids[$trelloId] = $match->id;

                continue;
            }
            $created = ImportMappings::once($run, 'label', $trelloId, Label::class, fn () => $project->labels()->create([
                'name' => $label['name'],
                'slug' => $slug,
                'color' => $label['color'],
            ]));
            $existing->push($created);
            $ids[$trelloId] = $created->id;
            $progress->created('labels');
        }

        return $ids;
    }

    /** @param  list<array<string, mixed>>  $cards */
    private function report(TrelloExport $export, array $cards, array $options, ImportProgress $progress, int $tooManyChecklistItems, int $multipleMembers): void
    {
        $imported = array_column($cards, 'id');
        $comments = array_sum(array_intersect_key($export->comments, array_flip($imported)));
        $fields = array_sum(array_intersect_key($export->customFieldValues, array_flip($imported)));
        $attachments = array_sum(array_map(fn ($c) => count($c['attachments']), $cards));
        // added once, at the very end: a run that crashed earlier hasn't counted them yet
        foreach (['Comments' => $comments, 'Custom fields' => $fields, 'Attachments' => $attachments, 'Power-ups' => $export->powerUps] as $what => $count) {
            if ($count > 0) {
                $progress->unsupported($what, $count);
            }
        }

        $skipped = count($export->cards) - count($cards);
        if ($skipped > 0) {
            $progress->warn("{$skipped} archived card(s) were skipped.");
        }
        if ($options['include_archived'] && count(array_filter($cards, fn ($c) => $export->isArchived($c))) > 0) {
            $progress->warn('Archived cards were put into the done column.');
        }
        if ($attachments > 0) {
            $progress->warn($options['attachment_links']
                ? 'Attachments stay in Trello; their links were added to the issue descriptions.'
                : 'Attachments stay in Trello (files are never downloaded).');
        }
        if ($comments > 0) {
            $progress->warn('Trello comments aren’t imported. Trello’s JSON export only holds the newest 1 000 actions, so older comments are missing from the file anyway.');
        }
        if ($tooManyChecklistItems > 0) {
            $progress->warn("{$tooManyChecklistItems} card(s) have more than ".self::MAX_SUBTASKS_PER_CARD.' checklist items; their checklists stayed in the description.');
        }
        if ($multipleMembers > 0) {
            $progress->warn("{$multipleMembers} card(s) had several members: the first mapped one is the assignee, the others are named in the description.");
        }
        foreach ($export->warnings as $warning) {
            $progress->warn($warning);
        }
    }

    /**
     * People who can open the project (members + workspace admins), the set ProjectMember accepts.
     *
     * @return Collection<int, array{id: int, name: string, email: ?string}>
     */
    private static function projectMembers(Project $project): Collection
    {
        $admins = $project->workspace->users()->wherePivot('role', 'admin')->get(['users.id', 'users.name', 'users.email']);

        return $project->users()->get(['users.id', 'users.name', 'users.email'])
            ->concat($admins)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();
    }

    private static function checkItemCount(TrelloExport $export, string $cardId): int
    {
        return array_sum(array_map(fn ($c) => count($c['items']), $export->checklists[$cardId] ?? []));
    }

    /** @param  callable(string): bool  $taken */
    private static function uniqueSlug(string $name, callable $taken, string $fallback): string
    {
        $base = Str::limit(Str::slug($name) ?: $fallback, 200, '');
        $slug = $base;
        for ($i = 2; $taken($slug); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
