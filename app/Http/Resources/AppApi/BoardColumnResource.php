<?php

namespace App\Http\Resources\AppApi;

use App\Models\BoardColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A column (status) of a board, in the board's order. `cards_count` = the cards the board shows in it
 * (Scrum: the running sprint; done columns: within the done retention), set by the controller.
 *
 * @mixin BoardColumn
 */
class BoardColumnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'position' => (int) $this->position,
            // cards here count as done (moving a card in completes it)
            'is_done' => (bool) $this->is_done_column,
            // null = no limit; the web warns (doesn't block) when a column is over it
            'wip_limit' => $this->wip_limit,
            'cards_count' => (int) $this->resource->getAttribute('cards_count'),
            // the board's workflow rule for entering this column (v1.52.0); null = none. Hints for the app — the
            // server refuses a move / create that breaks it with 422 + `workflow_rule`
            'workflow' => $this->workflow(),
        ];
    }

    /**
     * `summary`: the rule as sentences; `allowed_from_column_ids`: cards may only come from these columns ([] = from
     * anywhere; a new card can't be created here when set); `requires`: conditions on the card; `can_move_here`:
     * whether the signed-in user is allowed by the who-part of the rule.
     *
     * @return array{summary: list<string>, allowed_from_column_ids: list<int>, requires: array{assignee: bool, subtasks_done: bool, due_date: bool}, can_move_here: bool}|null
     */
    private function workflow(): ?array
    {
        $rule = $this->resource->getAttribute('workflow');
        if (! is_array($rule)) {
            return null;
        }

        return [
            'summary' => array_values(array_map('strval', $rule['summary'])),
            'allowed_from_column_ids' => array_values(array_map('intval', $rule['allowed_from'])),
            'requires' => [
                'assignee' => (bool) $rule['requires']['assignee'],
                'subtasks_done' => (bool) $rule['requires']['subtasks_done'],
                'due_date' => (bool) $rule['requires']['due_date'],
            ],
            'can_move_here' => (bool) $rule['actor_allowed'],
        ];
    }
}
