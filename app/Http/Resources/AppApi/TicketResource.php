<?php

namespace App\Http\Resources\AppApi;

use App\Models\Ticket;
use App\Support\Helpdesks\Sla\SlaStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A helpdesk ticket in the agent inbox (what the web's helpdesk board card shows a project member): status,
 * priority, assignee, the customer (name + address, as the web's agent view) and the SLA state.
 *
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->helpdesk->project;
        $column = $this->column;

        return [
            'id' => (int) $this->id,
            'key' => (string) $this->key,
            'helpdesk_id' => (int) $this->helpdesk_id,
            'subject' => (string) $this->subject,
            // the column = its status
            'status' => $column === null ? null : ['column_id' => (int) $column->id, 'name' => (string) $column->name, 'is_done' => (bool) $column->is_done_column],
            'priority' => $this->priority === null ? null : ['id' => (int) $this->priority->id, 'name' => (string) $this->priority->name, 'color' => $this->priority->color],
            'assignee' => $this->assignee === null ? null : PersonResource::make($this->assignee)->resolve($request),
            // the customer who wrote in
            'contact' => $this->contact === null ? null : ['name' => $this->contact->name, 'email' => (string) $this->contact->email],
            'sla' => self::sla($this->resource),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            // when it went into a done column
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'url' => route('tickets.show', [$project->workspace->slug, $project->slug, $this->helpdesk->slug, $this->id]),
        ];
    }

    /**
     * none | breached | respond | resolve | paused | met, the due time, and when it broke (as the web's SLA badge).
     *
     * @return array{state: string, due_at: string|null, at_risk: bool, breached_since: string|null, breached_targets: list<string>}
     */
    public static function sla(Ticket $ticket): array
    {
        $sla = SlaStatus::of($ticket, now());

        return [
            'state' => $sla['state'],
            // respond / resolve: the deadline
            'due_at' => $sla['due_at']?->toIso8601ZuluString(),
            // respond / resolve: past the warning time
            'at_risk' => $sla['at_risk'],
            'breached_since' => $sla['breached_since']?->toIso8601ZuluString(),
            // first_response and / or resolution
            'breached_targets' => $sla['breached_targets'],
        ];
    }
}
