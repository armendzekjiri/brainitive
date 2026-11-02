<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\User;
use App\Rules\ProjectMember;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use App\Support\Live\BoardLive;
use App\Support\Validation\ContentLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating, changing and deleting a board issue the same way everywhere: the board's issue dialog
 * (`BoardController@storeItem|updateItem|updateItemLabels|destroyItem`) and the App API (BRAI-404).
 * Rules and data use the column names (`assigned_to`, `item_type_id` …); callers authorize first
 * (BoardPolicy::createItems, ItemPolicy::update / delete). Bulk creates (MCP, imports, whiteboards):
 * ProjectIssues::create.
 */
final class Issues
{
    /**
     * The issue dialog's rules. `$item` = the issue being changed (null: a new one on `$board`).
     *
     * @return array<string, mixed>
     */
    public static function rules(Project $project, Board $board, ?Item $item = null): array
    {
        $rules = [
            'title' => $item ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'description' => ['nullable', ...ContentLimits::body()],
            // enabled types; a card may keep the switched-off type it already has
            'item_type_id' => ['nullable', 'integer', Rule::in(ItemType::selectableIdsFor($project, $item?->item_type_id))],
            'priority_id' => ['nullable', 'integer', Rule::in(ItemPriority::forProject($project)->pluck('id')->map(fn ($id) => (int) $id)->all())],
            'assigned_to' => ['nullable', 'integer', new ProjectMember($project)],
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'parent_id' => ['nullable', 'integer', 'exists:items,id', self::parentRule($board, $item)],
            'story_points' => 'nullable|integer|min:0',
            // Fix version: a release of this project that isn't archived.
            'release_id' => ['nullable', Rule::exists('releases', 'id')->where('project_id', $project->id)->whereIn('status', [Release::STATUS_UNRELEASED, Release::STATUS_RELEASED])],
            'original_estimate' => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if (Item::parseDuration($value) === null) {
                    $fail('Use a duration like 45m, 1h 30m, 2h or 1d.');
                }
            }],
        ];

        if ($item === null) {
            return $rules + [
                'column_id' => ['required', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
                'label_ids' => 'nullable|array',
                'label_ids.*' => 'exists:labels,id',
                'sprint_id' => ['nullable', Rule::exists('sprints', 'id')->where('board_id', $board->id)->whereIn('status', [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE])],
            ];
        }

        return $rules;
    }

    /**
     * A new issue on the board (validated with rules()): in its column at the end, the project's default
     * type / priority, labels of the project, custom field values (`fields`: [fieldId => value]); subtasks
     * join their parent's sprint, a new card on a Scrum board the running one unless `sprint_id` says
     * where (null = backlog), like Jira. The reporter watches it; mentions in the description are notified.
     *
     * @param  array<string, mixed>  $data
     *
     * The workspace's content limit is checked here (Codex review, finding 5), so every caller — the issue dialog,
     * the App API, offline sync (web and apps) — stops at it the same way.
     *
     * @throws ValidationException a subtask without a parent, an invalid custom field value
     * @throws LimitReached the workspace's content storage is used up (402 limit_reached)
     */
    public static function create(Board $board, User $user, array $data): Item
    {
        $project = $board->project;
        app(LimitGate::class)->ensureContentRoom($project->workspace);
        $itemTypeId = $data['item_type_id'] ?? ItemType::defaultIdFor($project);
        self::ensureSubtaskHasParent($project, $itemTypeId, $data['parent_id'] ?? null);

        $item = DB::transaction(function () use ($board, $project, $user, $data, $itemTypeId) {
            $maxPosition = Item::where('column_id', $data['column_id'])->max('position') ?? 0;

            $sprintId = match (true) {
                ! empty($data['parent_id']) => Item::find($data['parent_id'])?->sprint_id,
                array_key_exists('sprint_id', $data) => $data['sprint_id'],
                $board->isScrum() => $board->activeSprint()->value('id'),
                default => null,
            };
            $backlogPosition = (Item::where('board_id', $board->id)->where('sprint_id', $sprintId)->max('backlog_position') ?? 0) + 1;

            $item = $board->items()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'project_id' => $project->id,
                'column_id' => $data['column_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'item_type_id' => $itemTypeId,
                'priority_id' => $data['priority_id'] ?? ItemPriority::where('is_default', true)->first()?->id,
                'assigned_to' => $data['assigned_to'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'due_time' => $data['due_time'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'story_points' => $data['story_points'] ?? null,
                'release_id' => $data['release_id'] ?? null,
                'original_estimate' => isset($data['original_estimate']) ? Item::parseDuration($data['original_estimate']) : null,
                'key' => $project->getNextItemKey(),
                'created_by' => $user->id,
                'reporter_id' => $user->id,
                'position' => $maxPosition + 1,
                'sprint_id' => $sprintId,
                'backlog_position' => $backlogPosition,
            ]);

            if (! empty($data['label_ids'])) {
                $item->labels()->sync($project->labels()->whereIn('id', $data['label_ids'])->pluck('id'));
            }
            if (! empty($data['fields'])) {
                ItemFieldValues::apply($item->setRelation('board', $board), $data['fields']);
            }

            return $item;
        });

        // The assignee hears about it from ItemObserver (Support\Notifications\Assignments), like every path.
        if (! empty($data['description'])) {
            ItemMentions::notify($data['description'], $item, $user);
        }

        return $item;
    }

    /**
     * Change an issue with what rules() validated (only the given keys change). `original_estimate` is a
     * duration text ("1h 30m"); mentions new to the description are notified. `label_ids` (the whole list)
     * and `fields` ([fieldId => value|null]) are applied too when given.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException a subtask without a parent, an invalid custom field value
     */
    public static function update(Item $item, User $user, array $data): void
    {
        $project = $item->board->project;
        $labelIds = array_key_exists('label_ids', $data) ? (array) $data['label_ids'] : null;
        $fields = $data['fields'] ?? null;
        unset($data['label_ids'], $data['fields']);

        $typeId = array_key_exists('item_type_id', $data) ? ($data['item_type_id'] ?? $item->item_type_id) : $item->item_type_id;
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $item->parent_id;
        self::ensureSubtaskHasParent($project, $typeId, $parentId);

        if (array_key_exists('original_estimate', $data)) {
            $data['original_estimate'] = $data['original_estimate'] === null ? null : Item::parseDuration($data['original_estimate']);
        }

        $previousDescription = $item->description;

        DB::transaction(function () use ($item, $data, $labelIds, $fields) {
            // A new assignee hears about it from ItemObserver (Support\Notifications\Assignments).
            if ($data !== []) {
                $item->update($data);
            }
            if ($labelIds !== null) {
                self::syncLabels($item, $labelIds);
            }
            if (is_array($fields) && $fields !== []) {
                ItemFieldValues::apply($item, $fields);
            }
        });

        // Mentions: only people who weren't mentioned in the description before this save
        if (isset($data['description'])) {
            ItemMentions::notify($data['description'], $item, $user, $previousDescription);
        }
    }

    /**
     * Set the issue's labels to these (ids of other projects are dropped), with a history entry and a
     * live update when something changed.
     *
     * @param  array<int, int|string>  $labelIds
     */
    public static function syncLabels(Item $item, array $labelIds): void
    {
        $project = $item->board->project;
        $valid = $project->labels()->whereIn('id', array_map('intval', $labelIds))->pluck('id');
        $result = $item->labels()->sync($valid);

        if ($result['attached'] !== [] || $result['detached'] !== []) {
            BoardLive::itemIds([$item->id], ['labels'], $item->board_id);
            $names = $project->labels()->whereIn('id', array_merge($result['attached'], $result['detached']))->pluck('name', 'id');
            ActivityLog::log($item, 'labels_changed', [
                'labels' => [
                    'added' => collect($result['attached'])->map(fn ($id) => $names[$id] ?? "#{$id}")->values()->all(),
                    'removed' => collect($result['detached'])->map(fn ($id) => $names[$id] ?? "#{$id}")->values()->all(),
                ],
            ]);
        }
    }

    /** Delete an issue with its subtasks (soft deletes don't trigger the FK cascade). */
    public static function delete(Item $item): void
    {
        DB::transaction(function () use ($item) {
            Item::whereIn('id', $item->descendantIds())->get()->each->delete();
            $item->delete();
        });
    }

    /** @throws ValidationException */
    private static function ensureSubtaskHasParent(Project $project, mixed $typeId, mixed $parentId): void
    {
        $subtaskTypeId = ItemType::forProject($project)->where('slug', 'subtask')->value('id');
        if ($subtaskTypeId && (int) $typeId === (int) $subtaskTypeId && empty($parentId)) {
            throw ValidationException::withMessages(['parent_id' => 'Subtasks must have a parent task.']);
        }
    }

    /** A parent on the same board, at most 3 levels deep, never the issue itself or one below it. */
    private static function parentRule(Board $board, ?Item $item): \Closure
    {
        return function ($attribute, $value, $fail) use ($board, $item) {
            if ($value === null) {
                return;
            }
            if ($item && (int) $value === $item->id) {
                $fail('An item cannot be its own parent.');

                return;
            }
            $parent = Item::find($value);
            if (! $parent || $parent->board_id !== $board->id) {
                $fail('Parent task must be on the same board.');

                return;
            }
            $height = $item ? 1 + $item->subtreeHeight() : 1;
            if ($parent->depth() + $height > Item::MAX_DEPTH) {
                $fail('Subtasks can be nested up to 3 levels (issue → subtask → sub-subtask).');

                return;
            }
            // No cycle: walk the parent chain from the candidate; it must not reach the issue
            for ($current = $parent; $item && $current->parent_id !== null;) {
                $current = Item::find($current->parent_id);
                if (! $current) {
                    break;
                }
                if ($current->id === $item->id) {
                    $fail('Parent would create a circular reference.');

                    return;
                }
            }
        };
    }
}
