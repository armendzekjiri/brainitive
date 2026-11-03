<?php

namespace App\Http\Resources\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Sprint;
use Illuminate\Http\Request;

/**
 * One board (`GET /boards/{id}`, BRAI-403): the board fields + its columns (with the number of cards the
 * board shows in each), swimlane setup, done retention, the running sprint and what the user may do.
 * The cards are a list of their own (`GET /boards/{id}/items`). Needs `columns` (with `cards_count`)
 * and `activeSprint` loaded by the controller.
 *
 * @mixin Board
 */
class BoardDetailResource extends BoardResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $board = $this->resource;
        $sprint = $board->activeSprint;
        $canUpdate = $user->can('update', $board);

        return [
            ...parent::toArray($request),
            'columns' => BoardColumnResource::collection($board->columns),
            // Kanban: done cards finished more than this many days ago are left out (0 = all are shown)
            'done_retention_days' => $board->doneRetentionDays(),
            'swimlanes' => $this->swimlanes(),
            // Scrum: the running sprint (the board shows only its cards); null on Kanban or between sprints
            'active_sprint' => $sprint instanceof Sprint ? [
                'id' => (int) $sprint->id,
                'name' => (string) $sprint->name,
                'goal' => $sprint->goal,
                'start_date' => $sprint->start_date?->format('Y-m-d'),
                'end_date' => $sprint->end_date?->format('Y-m-d'),
            ] : null,
            'can' => [
                // board settings, columns, swimlanes (web only)
                'update' => $canUpdate,
                'create_items' => $user->can('createItems', $board),
                // edit, move and assign cards (ItemPolicy::update)
                'edit_items' => $canUpdate,
                // delete cards someone else created (everyone may delete their own)
                'delete_any_item' => $user->can('delete', (new Item(['created_by' => 0]))->setRelation('board', $board)),
                'manage_sprints' => $board->isScrum() && $canUpdate,
            ],
        ];
    }

    /**
     * The board's swimlanes (`settings.swimlanes`, Support\Boards\Swimlanes). `mode`: none | custom |
     * assignee | priority | type | release. Custom lanes are ordered rules: a card goes into the first
     * lane whose rules all match (any listed id per rule; assignee / release id 0 = none), the rest into
     * `rest_name`.
     *
     * @return array{mode: string, rest_name: string, lanes: list<array{id: string, name: string, color: string|null, rules: array{priority_ids: list<int>, type_ids: list<int>, label_ids: list<int>, assignee_ids: list<int>, release_ids: list<int>, overdue: bool}}>}
     */
    private function swimlanes(): array
    {
        $setup = (array) (($this->resource->settings ?? [])['swimlanes'] ?? []);
        $ids = fn (array $rules, string $key): array => array_values(array_map('intval', (array) ($rules[$key] ?? [])));

        $lanes = [];
        foreach ((array) ($setup['lanes'] ?? []) as $lane) {
            $lane = (array) $lane;
            $rules = (array) ($lane['rules'] ?? []);
            $lanes[] = [
                'id' => (string) ($lane['id'] ?? ''),
                'name' => (string) ($lane['name'] ?? ''),
                'color' => isset($lane['color']) ? (string) $lane['color'] : null,
                'rules' => [
                    'priority_ids' => $ids($rules, 'priority_ids'),
                    'type_ids' => $ids($rules, 'type_ids'),
                    'label_ids' => $ids($rules, 'label_ids'),
                    'assignee_ids' => $ids($rules, 'assignee_ids'),
                    'release_ids' => $ids($rules, 'release_ids'),
                    'overdue' => (bool) ($rules['overdue'] ?? false),
                ],
            ];
        }

        return [
            'mode' => (string) ($setup['mode'] ?? 'none'),
            'rest_name' => (string) ($setup['rest_name'] ?? 'Everything else'),
            'lanes' => $lanes,
        ];
    }
}
