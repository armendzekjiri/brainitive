<?php

namespace App\Http\Requests\AppApi;

use App\Support\Helpdesks\Sla\SlaStatus;
use Illuminate\Validation\Rule;

/** `GET /api/v1/helpdesks/{id}/tickets`: the agent inbox of a helpdesk, newest first. */
class ListTicketsRequest extends ListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // open (not in a done column, default) | done | all
            'status' => ['sometimes', 'string', Rule::in(['open', 'done', 'all'])],
            // only this column (a column id of GET /helpdesks/{id})
            'column' => ['sometimes', 'integer'],
            // me | unassigned | a user id
            'assignee' => ['sometimes', 'string', 'regex:/^(me|unassigned|[1-9][0-9]{0,17})$/'],
            // a priority id (GET /helpdesks/{id} → priorities)
            'priority' => ['sometimes', 'integer'],
            // breached | at_risk | on_track | paused | met | none (the web's SLA filter)
            'sla' => ['sometimes', 'string', Rule::in(SlaStatus::FILTERS)],
        ];
    }
}
