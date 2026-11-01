<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** `POST /api/v1/items/{id}/move`: a column of the issue's board, as the web's drag & drop. */
class MoveIssueRequest extends IssueRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $boardId = $this->issueForRules()?->board_id;

        return [
            // a column of the issue's board (GET /boards/{id} → columns)
            'column_id' => ['required', 'integer', $boardId === null ? 'min:1' : Rule::exists('board_columns', 'id')->where('board_id', $boardId)],
            // 0-based place in the column (default 0 = the top)
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // moving into a done column: complete the open subtasks (all levels) too
            'with_subtasks' => ['sometimes', 'boolean'],
        ];
    }
}
