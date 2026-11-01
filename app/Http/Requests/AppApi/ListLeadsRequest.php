<?php

namespace App\Http\Requests\AppApi;

/** `GET /api/v1/lead-forms/{id}/leads`: the leads of a form, one pipeline column or all. */
class ListLeadsRequest extends ListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // one column (board order); without: every column, newest first
            'column' => ['sometimes', 'integer'],
            // me | unassigned | a user id
            'assignee' => ['sometimes', 'string', 'regex:/^(me|unassigned|[1-9][0-9]{0,17})$/'],
        ];
    }
}
