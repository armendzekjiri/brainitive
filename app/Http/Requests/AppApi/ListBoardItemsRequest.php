<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `GET /api/v1/boards/{id}/items`: the list rules + `column`, `include_old_done` and the board page's
 * filters in its URL / saved-view format (Support\Boards\BoardFilters); custom fields as `f<field id>`.
 */
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
            // title or key contains it
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            // me | unassigned | user-<id>
            'assignee' => ['sometimes', 'nullable', 'string', 'regex:/^(me|unassigned|user-\d{1,10})$/'],
            // comma-separated column ids
            'status' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}(,\d{1,18})*$/'],
            // comma-separated label ids (any of them)
            'labels' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}(,\d{1,18})*$/'],
            // comma-separated priority ids
            'priority' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}(,\d{1,18})*$/'],
            // comma-separated issue type ids
            'type' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}(,\d{1,18})*$/'],
            // recent, due, blocked (comma-separated; all must match)
            'quick' => ['sometimes', 'nullable', 'string', 'regex:/^(recent|due|blocked)(,(recent|due|blocked))*$/'],
        ];
    }

    /**
     * The board filters as the board URL / a saved view has them, incl. custom field filters `f<id>`
     * (none | any | yes | no | ~text | option ids[,none] | min..max).
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        $filters = [];
        foreach ($this->query() as $key => $value) {
            if (is_string($value) && (in_array($key, ['q', 'assignee', 'status', 'labels', 'priority', 'type', 'quick'], true) || preg_match('/^f\d{1,10}$/', (string) $key))) {
                $filters[(string) $key] = $value;
            }
        }

        return $filters;
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
