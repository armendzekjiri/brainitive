<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Message-ID of a ticket's email thread (v1.40.0, BRAI-79): the customer's messages ("in") and
 * ours ("out": agent replies, acknowledgments). Inbound In-Reply-To / References are matched
 * against these to thread a reply into its ticket; outgoing mail builds References from them.
 */
class TicketEmailMessage extends Model
{
    protected $fillable = ['ticket_id', 'ticket_reply_id', 'message_hash', 'message_id', 'direction'];

    public static function record(Ticket $ticket, string $messageId, string $direction, ?TicketReply $reply = null): self
    {
        $messageId = trim($messageId, " \t\r\n<>");

        return self::create([
            'ticket_id' => $ticket->id,
            'ticket_reply_id' => $reply?->id,
            'message_hash' => HelpdeskInboundEmail::hashOf($messageId),
            'message_id' => $messageId,
            'direction' => $direction,
        ]);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
