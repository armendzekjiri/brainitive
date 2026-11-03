<?php

namespace App\Http\Requests\AppApi;

use App\Rules\ProjectMember;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/tickets/{id}`: the sent fields change — status (column), assignee, priority, subject.
 * Moving into a done column can carry the closing message and email the customer, as the web's "Resolve".
 */
class UpdateTicketRequest extends TicketRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $ticket = $this->ticketForRules();
        $helpdeskId = $ticket?->helpdesk_id ?? 0;
        $workspaceId = $ticket?->helpdesk->project->workspace_id ?? 0;
        $projectId = $ticket?->helpdesk->project_id ?? 0;

        return [
            'subject' => ['sometimes', 'string', 'max:255'],
            // a column of the ticket's helpdesk (its status)
            'column_id' => ['sometimes', 'integer', Rule::exists('helpdesk_columns', 'id')->where('helpdesk_id', $helpdeskId)],
            // place in the column, 0 = top (default)
            'position' => ['sometimes', 'integer', 'min:0'],
            // into a done column: a closing message, saved as a reply to the customer
            'resolution_message' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // into a done column: email the customer that it's resolved
            'notify_contact' => ['sometimes', 'boolean'],
            // someone who can open the ticket's project (a member or a workspace admin), or null
            'assignee_id' => ['sometimes', 'nullable', 'integer', ...($ticket ? [new ProjectMember($ticket->helpdesk->project)] : [])],
            // a priority of the project (GET /helpdesks/{id} → priorities), or null
            'priority_id' => ['sometimes', 'nullable', 'integer', Rule::exists('item_priorities', 'id')->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))],
            // the time of the ticket the app last read (updated_at): 409 when it changed since
            'updated_at' => ['sometimes', 'date'],
        ];
    }
}
