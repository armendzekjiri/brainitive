<?php

namespace App\Http\Resources\AppApi;

use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Support\Boards\IssueMarkdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message of a ticket's thread: the customer's, an agent's reply or an internal note (agents only, as
 * on the web). Body as Markdown + sanitized HTML.
 *
 * @mixin TicketReply
 */
class TicketReplyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            // contact (the customer) | agent
            'from' => $this->ticket_contact_id !== null && $this->user_id === null ? 'contact' : 'agent',
            'author' => $this->user !== null
                ? ['id' => (int) $this->user->id, 'name' => (string) $this->user->name]
                : ['id' => null, 'name' => $this->contact?->name ?? $this->contact?->email],
            // never sent to the customer
            'internal' => (bool) $this->is_internal_note,
            'body_markdown' => IssueMarkdown::toMarkdown($this->body),
            // sanitized HTML, for rendering
            'body_html' => (string) $this->body,
            // agent replies through a connected mailbox: queued | sent | failed (null otherwise)
            'email_status' => $this->email_status,
            'attachments' => $this->attachments->map(fn (TicketAttachment $a) => TicketAttachmentResource::make($a)->resolve($request))->values()->all(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
