<?php

namespace App\Http\Resources\AppApi;

use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A file on a ticket or reply: its public storage address, as on the web. @mixin TicketAttachment */
class TicketAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) ($this->original_filename ?: $this->filename),
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'url' => asset('storage/'.$this->path),
            // sent by the customer
            'from_contact' => (bool) $this->uploaded_by_contact,
        ];
    }
}
