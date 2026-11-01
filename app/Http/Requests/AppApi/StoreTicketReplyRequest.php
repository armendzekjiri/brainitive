<?php

namespace App\Http\Requests\AppApi;

/**
 * `POST /api/v1/tickets/{id}/replies`: an agent's reply (emailed to the customer) or an internal note. Needs
 * edit rights on the ticket (project editors / admins, as the web).
 */
class StoreTicketReplyRequest extends TicketRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Markdown (no @mentions: the thread is the customer's email; they come out as plain "@Name"); images / files: links from POST /uploads
            'body' => ['required', 'string', 'max:50000'],
            // true: an internal note the customer never sees (default false: a reply to the customer)
            'internal' => ['sometimes', 'boolean'],
            // retry key: the same id + input answers 200 with the reply the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
