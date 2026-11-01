<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `GET /api/v1/boards/{id}/items`: the list rules + `column` and `include_old_done`. */
class ListBoardItemsRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // one column of the board (its id)
            'column' => ['sometimes', 'integer', 'min:1'],
            // Kanban: also done cards older than the board's done retention (default false)
            'include_old_done' => ['sometimes', 'boolean'],
        ];
    }

    public function column(): ?int
    {
        $column = $this->validated('column');

        return $column === null ? null : (int) $column;
    }

    public function includeOldDone(): bool
    {
        return $this->boolean('include_old_done');
    }
}
