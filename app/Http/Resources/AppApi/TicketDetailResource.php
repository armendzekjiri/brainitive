<?php

namespace App\Http\Resources\AppApi;

use App\Models\HelpdeskField;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketFieldValue;
use App\Models\TicketReply;
use App\Support\Boards\IssueMarkdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ticket with its thread (oldest first), custom field values, files and the user's rights — the web's
 * ticket panel for a project member.
 *
 * @mixin Ticket
 */
class TicketDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $base = TicketResource::make($this->resource)->resolve($request);
        $user = $request->user();

        return [
            'id' => (int) $base['id'],
            'key' => (string) $base['key'],
            'helpdesk_id' => (int) $base['helpdesk_id'],
            'subject' => (string) $base['subject'],
            'status' => $base['status'],
            'priority' => $base['priority'],
            'assignee' => $base['assignee'],
            'contact' => $base['contact'],
            'sla' => $base['sla'],
            'created_at' => $base['created_at'],
            'updated_at' => $base['updated_at'],
            'completed_at' => $base['completed_at'],
            'url' => $base['url'],
            // what the customer wrote first (sanitized HTML) as Markdown + HTML
            'description_markdown' => IssueMarkdown::toMarkdown($this->description),
            'description_html' => $this->description,
            // custom fields of the helpdesk with this ticket's values
            'fields' => $this->helpdesk->fields->map(fn (HelpdeskField $f) => [
                'id' => (int) $f->id,
                'label' => (string) ($f->label ?: $f->name),
                'type' => (string) $f->type,
                'value' => $this->fieldValues->first(fn (TicketFieldValue $v) => $v->helpdesk_field_id === $f->id)?->value,
            ])->values()->all(),
            // files on the ticket itself (reply files are on their reply)
            'attachments' => $this->attachments->whereNull('ticket_reply_id')->map(fn (TicketAttachment $a) => TicketAttachmentResource::make($a)->resolve($request))->values()->all(),
            'replies' => $this->replies->map(fn (TicketReply $r) => TicketReplyResource::make($r)->resolve($request))->values()->all(),
            'watching' => $this->watchers->contains('id', $user->id),
            'can' => [
                // reply, add internal notes, change status / assignee / priority (TicketPolicy::update)
                'update' => $user->can('update', $this->resource),
            ],
        ];
    }
}
